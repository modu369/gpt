from datetime import datetime
from pathlib import Path
import secrets
from typing import List

import httpx
from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import FileResponse, HTMLResponse
from pydantic import BaseModel
from sqlmodel import Session, select

from .config import settings
from .db import get_session, init_db
from .models import (
    Admin,
    CertificateRecord,
    CfIp,
    DnsScheduleConfig,
    Node,
    NodeToken,
    SystemSetting,
    WhitelistDomain,
)
from .security import create_access_token, decode_token, hash_password, verify_password
from .services.huawei_dns import NodeWeight, compute_weight, update_weighted_records


base = f"/{settings.admin_path}/api"
app = FastAPI(
    title="CF Relay Controller",
    docs_url=f"{base}/docs",
    openapi_url=f"{base}/openapi.json",
)


class LoginIn(BaseModel):
    username: str
    password: str


class TokenOut(BaseModel):
    access_token: str


class NodeRegisterIn(BaseModel):
    token: str
    name: str
    endpoint: str
    shared_secret: str
    cpu_cores: int = 0
    memory_mb: int = 0
    max_bandwidth_mbps: int = 0


class NodeMetricIn(BaseModel):
    cpu_percent: float
    memory_mb_used: int
    bandwidth_mbps_used: float
    monthly_traffic_used_gb: float = 0


class DomainIn(BaseModel):
    domain: str


class CfIpIn(BaseModel):
    ip: str


class DnsConfigIn(BaseModel):
    cname: str
    zone_id: str
    ttl: int = 30
    low_traffic_threshold_percent: int = 10


class CertRequestIn(BaseModel):
    domain: str
    verify_mode: str = "http"


class CertReportIn(BaseModel):
    domain: str
    status: str
    detail: str = ""
    verify_mode: str = "http"


class NodeCreateIn(BaseModel):
    node_name: str


class SettingsIn(BaseModel):
    controller_port: int
    admin_path: str
    default_admin_user: str
    default_admin_password: str


def auth(authorization: str = Header(default=""), session: Session = Depends(get_session)) -> Admin:
    if not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Unauthorized")
    token = authorization.split(" ", 1)[1]
    try:
        payload = decode_token(token)
    except Exception as exc:
        raise HTTPException(status_code=401, detail="Invalid token") from exc
    admin = session.exec(select(Admin).where(Admin.username == payload["sub"])).first()
    if not admin:
        raise HTTPException(status_code=401, detail="Unauthorized")
    return admin


@app.on_event("startup")
def startup() -> None:
    init_db()


def get_system_setting(session: Session) -> SystemSetting:
    cfg = session.exec(select(SystemSetting).where(SystemSetting.id == 1)).first()
    if not cfg:
        cfg = SystemSetting(id=1)
        session.add(cfg)
        session.commit()
        session.refresh(cfg)
    return cfg

def _expanded_cf_ips(session: Session) -> list[dict]:
    rows = session.exec(select(CfIp).where(CfIp.enabled == True)).all()  # noqa: E712
    result = []
    for x in rows:
        result.append({"ip": x.ip, "port": 80})
        result.append({"ip": x.ip, "port": 443})
    return result


async def push_node_config(node: Node, session: Session) -> dict:
    whitelist = [d.domain for d in session.exec(select(WhitelistDomain)).all()]
    cf_ips = _expanded_cf_ips(session)
    certs = [
        {"domain": c.domain, "status": c.status, "verify_mode": c.verify_mode}
        for c in session.exec(select(CertificateRecord)).all()
    ]
    payload = {"whitelist": whitelist, "cf_ips": cf_ips, "certificates": certs, "updated_at": datetime.utcnow().isoformat()}

    async with httpx.AsyncClient(timeout=8.0) as client:
        r = await client.post(
            f"{node.endpoint.rstrip('/')}/agent/config",
            json=payload,
            headers={"X-Agent-Secret": node.shared_secret},
        )
    return {"node": node.name, "status": r.status_code}



async def push_all_nodes(session: Session) -> dict:
    nodes: List[Node] = session.exec(select(Node).where(Node.enabled == True)).all()  # noqa: E712
    whitelist = [d.domain for d in session.exec(select(WhitelistDomain)).all()]
    cf_ips = _expanded_cf_ips(session)
    certs = [
        {"domain": c.domain, "status": c.status, "verify_mode": c.verify_mode}
        for c in session.exec(select(CertificateRecord)).all()
    ]
    payload = {"whitelist": whitelist, "cf_ips": cf_ips, "certificates": certs, "updated_at": datetime.utcnow().isoformat()}

    results = []
    async with httpx.AsyncClient(timeout=8.0) as client:
        for n in nodes:
            try:
                r = await client.post(
                    f"{n.endpoint.rstrip('/')}/agent/config",
                    json=payload,
                    headers={"X-Agent-Secret": n.shared_secret},
                )
                results.append({"node": n.name, "status": r.status_code})
            except Exception as exc:
                results.append({"node": n.name, "error": str(exc)})
    return {"results": results}


@app.get(f"/{settings.admin_path}/healthz")
def panel_healthz():
    return {"ok": True, "service": "controller", "admin_path": settings.admin_path}


@app.get(f"/{settings.admin_path}", response_class=HTMLResponse)
def panel_entry():
    page = Path(__file__).parent / "static" / "admin" / "index.html"
    return FileResponse(page)


@app.get(f"/{settings.admin_path}/", response_class=HTMLResponse)
def panel_entry_slash():
    page = Path(__file__).parent / "static" / "admin" / "index.html"
    return FileResponse(page)


@app.post(f"{base}/auth/login", response_model=TokenOut)
def login(data: LoginIn, session: Session = Depends(get_session)):
    admin = session.exec(select(Admin).where(Admin.username == data.username)).first()
    if not admin or not verify_password(data.password, admin.password_hash):
        raise HTTPException(status_code=401, detail="Bad credentials")
    return TokenOut(access_token=create_access_token(admin.username))


@app.get(f"{base}/settings")
def get_settings(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = get_system_setting(session)
    return cfg


@app.post(f"{base}/settings")
def set_settings(data: SettingsIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = get_system_setting(session)
    cfg.controller_port = data.controller_port
    cfg.admin_path = data.admin_path.strip("/")
    cfg.default_admin_user = data.default_admin_user
    cfg.default_admin_password = data.default_admin_password
    cfg.updated_at = datetime.utcnow()

    admin = session.exec(select(Admin)).first()
    if admin:
        admin.username = data.default_admin_user
        admin.password_hash = hash_password(data.default_admin_password)
        session.add(admin)

    session.add(cfg)
    session.commit()
    return {"ok": True, "message": "保存成功，端口/路径变更需要重启主控服务后生效"}


@app.post(f"{base}/node-tokens")
def create_node_token(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    token = secrets.token_urlsafe(32)
    session.add(NodeToken(token=token))
    session.commit()
    return {"token": token}


@app.post(f"{base}/nodes/onboard")
def create_node_install_commands(
    data: NodeCreateIn,
    request: Request,
    _: Admin = Depends(auth),
    session: Session = Depends(get_session),
):
    cfg = get_system_setting(session)
    token = secrets.token_urlsafe(32)
    session.add(NodeToken(token=token))
    session.commit()

    host = request.headers.get("x-forwarded-host") or request.headers.get("host") or "127.0.0.1:8080"
    scheme = request.headers.get("x-forwarded-proto") or request.url.scheme
    controller_url = f"{scheme}://{host}".rstrip("/")

    admin_path = cfg.admin_path.strip("/")
    install = (
        "bash -lc \"curl -fsSL "
        "https://raw.githubusercontent.com/modu369/gpt/codex/implement-management-backend-updates/scripts/install_agent.sh -o /tmp/install_agent.sh && "
        "chmod +x /tmp/install_agent.sh && "
        f"/tmp/install_agent.sh --controller {controller_url} --admin-path {admin_path} "
        f"--token {token} --node-name {data.node_name}\""
    )
    uninstall = "bash -lc \"curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/implement-management-backend-updates/scripts/uninstall.sh | bash\""
    return {
        "token": token,
        "controller_url": controller_url,
        "install_command": install,
        "uninstall_command": uninstall,
    }


@app.post(f"{base}/nodes/register")
async def register_node(data: NodeRegisterIn, session: Session = Depends(get_session)):
    t = session.exec(select(NodeToken).where(NodeToken.token == data.token, NodeToken.used == False)).first()  # noqa: E712
    if not t:
        raise HTTPException(status_code=403, detail="Invalid token")
    t.used = True
    node = session.exec(select(Node).where(Node.name == data.name)).first()
    if not node:
        node = Node(name=data.name, endpoint=data.endpoint, shared_secret=data.shared_secret)
        session.add(node)
    node.endpoint = data.endpoint
    node.shared_secret = data.shared_secret
    node.cpu_cores = data.cpu_cores
    node.memory_mb = data.memory_mb
    node.max_bandwidth_mbps = data.max_bandwidth_mbps
    node.online = True
    node.updated_at = datetime.utcnow()
    session.add(t)
    session.commit()
    try:
        return {"ok": True, "sync": await push_node_config(node, session)}
    except Exception as exc:
        return {"ok": True, "sync_error": str(exc)}


@app.post(f"{base}/nodes/{{node_name}}/speedtest")
async def trigger_node_speedtest(node_name: str, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    node = session.exec(select(Node).where(Node.name == node_name)).first()
    if not node:
        raise HTTPException(status_code=404, detail="node not found")
    async with httpx.AsyncClient(timeout=40.0) as client:
        r = await client.post(f"{node.endpoint.rstrip('/')}/agent/network/speedtest", headers={"X-Agent-Secret": node.shared_secret})
    data = r.json()
    if r.status_code != 200:
        raise HTTPException(status_code=r.status_code, detail=data)
    node.max_bandwidth_mbps = int(data.get("effective_mbps") or node.max_bandwidth_mbps)
    node.last_speedtest_at = datetime.utcnow()
    session.add(node)
    session.commit()
    return data




@app.post(f"{base}/nodes/{{node_name}}/certificates/report")
async def report_certificate_status(
    node_name: str,
    data: CertReportIn,
    x_agent_secret: str = Header(default=""),
    session: Session = Depends(get_session),
):
    node = session.exec(select(Node).where(Node.name == node_name)).first()
    if not node:
        raise HTTPException(status_code=404, detail="node not found")
    if x_agent_secret != node.shared_secret:
        raise HTTPException(status_code=403, detail="Forbidden")

    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == data.domain)).first()
    if not rec:
        rec = CertificateRecord(domain=data.domain, verify_mode=data.verify_mode)
        session.add(rec)

    rec.verify_mode = data.verify_mode
    rec.status = data.status
    rec.fail_reason = data.detail if data.status != "issued" else ""
    rec.last_synced_node = node.name
    rec.updated_at = datetime.utcnow()
    session.add(rec)
    session.commit()
    await push_all_nodes(session)
    return {"ok": True}
@app.post(f"{base}/nodes/{{node_name}}/metrics")
def report_metrics(node_name: str, data: NodeMetricIn, x_agent_secret: str = Header(default=""), session: Session = Depends(get_session)):
    node = session.exec(select(Node).where(Node.name == node_name)).first()
    if not node:
        raise HTTPException(status_code=404, detail="node not found")
    if x_agent_secret != node.shared_secret:
        raise HTTPException(status_code=403, detail="Forbidden")

    node.used_cpu_percent = data.cpu_percent
    node.used_memory_mb = data.memory_mb_used
    node.used_bandwidth_mbps = data.bandwidth_mbps_used
    node.monthly_traffic_used_gb = data.monthly_traffic_used_gb
    node.online = True
    node.updated_at = datetime.utcnow()
    if node.traffic_limit_enabled and node.monthly_traffic_limit_gb > 0:
        ratio = node.monthly_traffic_used_gb / node.monthly_traffic_limit_gb
        if ratio >= 1.0:
            node.enabled = False
    session.add(node)
    session.commit()
    return {"ok": True}


@app.get(f"{base}/dashboard/overview")
def overview(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    nodes: List[Node] = session.exec(select(Node)).all()
    total_cpu = sum(n.cpu_cores for n in nodes)
    used_cpu = sum((n.used_cpu_percent / 100.0) * n.cpu_cores for n in nodes)
    total_mem = sum(n.memory_mb for n in nodes)
    used_mem = sum(n.used_memory_mb for n in nodes)
    total_bw = sum(n.max_bandwidth_mbps for n in nodes)
    used_bw = sum(n.used_bandwidth_mbps for n in nodes)
    overloaded = [
        n.name
        for n in nodes
        if n.used_cpu_percent >= 90
        or (n.memory_mb and n.used_memory_mb / n.memory_mb >= 0.9)
        or (n.max_bandwidth_mbps and n.used_bandwidth_mbps / n.max_bandwidth_mbps >= 0.9)
    ]
    return {
        "total_nodes": len(nodes),
        "totals": {
            "cpu_cores": total_cpu,
            "cpu_used_cores_est": round(used_cpu, 2),
            "memory_mb": total_mem,
            "memory_used_mb": used_mem,
            "bandwidth_mbps": total_bw,
            "bandwidth_used_mbps": round(used_bw, 2),
        },
        "overloaded_nodes": overloaded,
        "suggest_add_node": len(overloaded) == len(nodes) and len(nodes) > 0,
    }


@app.get(f"{base}/nodes")
def list_nodes(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return session.exec(select(Node)).all()


@app.post(f"{base}/whitelist")
async def add_domain(data: DomainIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    if session.exec(select(WhitelistDomain).where(WhitelistDomain.domain == data.domain)).first():
        return {"ok": True, "exists": True}
    session.add(WhitelistDomain(domain=data.domain))
    cert = session.exec(select(CertificateRecord).where(CertificateRecord.domain == data.domain)).first()
    if not cert:
        session.add(CertificateRecord(domain=data.domain, verify_mode="http", status="pending"))
    session.commit()
    try:
        await dispatch_certificate_apply(data.domain, "http", session)
    except Exception:
        pass
    await push_all_nodes(session)
    return {"ok": True}


@app.get(f"{base}/whitelist")
def list_domains(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return session.exec(select(WhitelistDomain)).all()


@app.delete(f"{base}/whitelist/{{domain}}")
async def delete_domain(domain: str, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(WhitelistDomain).where(WhitelistDomain.domain == domain)).first()
    if not rec:
        return {"ok": True, "exists": False}
    session.delete(rec)
    session.commit()
    await push_all_nodes(session)
    return {"ok": True}


@app.post(f"{base}/cf-ips")
async def add_cf_ip(data: CfIpIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    if session.exec(select(CfIp).where(CfIp.ip == data.ip)).first():
        return {"ok": True, "exists": True}
    session.add(CfIp(ip=data.ip, port=443))
    session.commit()
    await push_all_nodes(session)
    return {"ok": True}


@app.delete(f"{base}/cf-ips/{{ip}}")
async def delete_cf_ip(ip: str, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(CfIp).where(CfIp.ip == ip)).first()
    if not rec:
        return {"ok": True, "exists": False}
    session.delete(rec)
    session.commit()
    await push_all_nodes(session)
    return {"ok": True}


@app.get(f"{base}/cf-ips")
def list_cf_ips(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    rows = session.exec(select(CfIp).where(CfIp.enabled == True)).all()  # noqa: E712
    return [{"ip": x.ip, "ports": [80, 443], "enabled": x.enabled} for x in rows]


@app.post(f"{base}/dns/config")
async def set_dns_config(data: DnsConfigIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = session.exec(select(DnsScheduleConfig)).first()
    if not cfg:
        cfg = DnsScheduleConfig()
        session.add(cfg)
    cfg.cname = data.cname
    cfg.zone_id = data.zone_id
    cfg.ttl = data.ttl
    cfg.low_traffic_threshold_percent = data.low_traffic_threshold_percent
    cfg.updated_at = datetime.utcnow()
    session.add(cfg)
    session.commit()
    await push_all_nodes(session)
    return {"ok": True}


@app.get(f"{base}/dns/config")
def get_dns_config(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = session.exec(select(DnsScheduleConfig)).first()
    return cfg or {}


@app.post(f"{base}/dns/reconcile")
def reconcile_dns(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = session.exec(select(DnsScheduleConfig)).first()
    if not cfg:
        raise HTTPException(status_code=400, detail="dns config not set")

    candidates: List[NodeWeight] = []
    for n in session.exec(select(Node).where(Node.enabled == True)).all():  # noqa: E712
        if not n.online:
            continue
        cpu_p = n.used_cpu_percent
        mem_p = 100.0 * n.used_memory_mb / n.memory_mb if n.memory_mb else 0.0
        bw_p = 100.0 * n.used_bandwidth_mbps / n.max_bandwidth_mbps if n.max_bandwidth_mbps else 0.0
        if max(cpu_p, mem_p, bw_p) >= 95:
            continue
        w = compute_weight(cpu_p, mem_p, bw_p)
        host = n.endpoint.replace("http://", "").replace("https://", "").split(":")[0]
        candidates.append(NodeWeight(ip=host, weight=w))

    ok, message = update_weighted_records(
        ak=settings.hw_access_key,
        sk=settings.hw_secret_key,
        region=settings.hw_region,
        zone_id=cfg.zone_id or settings.hw_zone_id,
        recordset_name=cfg.cname,
        ttl=cfg.ttl,
        targets=candidates,
    )
    if not ok:
        raise HTTPException(status_code=500, detail=message)
    return {"ok": True, "message": message, "targets": [c.__dict__ for c in candidates]}


@app.post(f"{base}/certificates/manual")
async def add_certificate_manual(data: CertRequestIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == data.domain)).first()
    if not rec:
        rec = CertificateRecord(domain=data.domain, verify_mode=data.verify_mode)
        session.add(rec)
    rec.status = "pending"
    rec.fail_reason = ""
    rec.verify_mode = data.verify_mode
    rec.updated_at = datetime.utcnow()
    session.add(rec)
    session.commit()
    return await dispatch_certificate_apply(data.domain, data.verify_mode, session)


@app.get(f"{base}/certificates")
def list_certificates(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return session.exec(select(CertificateRecord)).all()


@app.get(f"{base}/certificates/{{domain}}/logs")
def certificate_logs(domain: str, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == domain)).first()
    if not rec:
        raise HTTPException(status_code=404, detail="certificate record not found")
    return {
        "domain": rec.domain,
        "status": rec.status,
        "fail_reason": rec.fail_reason,
        "retries": rec.retries,
        "last_synced_node": rec.last_synced_node,
        "updated_at": rec.updated_at.isoformat() if rec.updated_at else "",
    }


@app.delete(f"{base}/certificates/{{domain}}")
async def delete_certificate(domain: str, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == domain)).first()
    if not rec:
        return {"ok": True, "exists": False}
    session.delete(rec)
    session.commit()
    await push_all_nodes(session)
    return {"ok": True}


@app.post(f"{base}/certificates/{{domain}}/verify")
async def verify_certificate_once(domain: str, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == domain)).first()
    if not rec:
        raise HTTPException(status_code=404, detail="certificate record not found")
    rec.status = "retrying"
    rec.retries += 1
    rec.updated_at = datetime.utcnow()
    session.add(rec)
    session.commit()
    return await dispatch_certificate_apply(rec.domain, rec.verify_mode, session)


@app.post(f"{base}/certificates/{{domain}}/retry")
async def retry_certificate(domain: str, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == domain)).first()
    if not rec:
        raise HTTPException(status_code=404, detail="certificate record not found")
    rec.status = "retrying"
    rec.retries += 1
    rec.updated_at = datetime.utcnow()
    session.add(rec)
    session.commit()
    return await dispatch_certificate_apply(rec.domain, rec.verify_mode, session)


async def dispatch_certificate_apply(domain: str, verify_mode: str, session: Session):
    nodes: List[Node] = session.exec(select(Node).where(Node.enabled == True)).all()  # noqa: E712
    if not nodes:
        raise HTTPException(status_code=400, detail="no enabled nodes")
    results = []
    async with httpx.AsyncClient(timeout=20.0) as client:
        for n in nodes:
            try:
                r = await client.post(
                    f"{n.endpoint.rstrip('/')}/agent/cert/apply",
                    json={"domain": domain, "verify_mode": verify_mode},
                    headers={"X-Agent-Secret": n.shared_secret},
                )
                res = r.json()
                results.append({"node": n.name, "status": r.status_code, "result": res})
            except Exception as exc:
                results.append({"node": n.name, "error": str(exc)})

    success = any(x.get("status") == 200 and x.get("result", {}).get("ok") for x in results)
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == domain)).first()
    if rec:
        rec.status = "issued" if success else "failed"
        rec.fail_reason = "" if success else str(results)
        rec.last_synced_node = ",".join([x.get("node", "") for x in results if x.get("status") == 200])
        rec.updated_at = datetime.utcnow()
        session.add(rec)
        session.commit()
    await push_all_nodes(session)
    return {"results": results}


@app.post(f"{base}/sync")
async def sync(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return await push_all_nodes(session)
