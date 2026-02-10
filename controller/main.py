from datetime import datetime
from pathlib import Path
import secrets
from typing import List

import httpx
from fastapi import Depends, FastAPI, Header, HTTPException
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
    WhitelistDomain,
)
from .security import create_access_token, decode_token, verify_password
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
    port: int = 443


class DnsConfigIn(BaseModel):
    cname: str
    zone_id: str
    ttl: int = 30
    low_traffic_threshold_percent: int = 10


class CertRequestIn(BaseModel):
    domain: str
    verify_mode: str = "http"


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


@app.post(f"{base}/node-tokens")
def create_node_token(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    token = secrets.token_urlsafe(32)
    session.add(NodeToken(token=token))
    session.commit()
    return {"token": token}


@app.post(f"{base}/nodes/register")
def register_node(data: NodeRegisterIn, session: Session = Depends(get_session)):
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
def add_domain(data: DomainIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    if session.exec(select(WhitelistDomain).where(WhitelistDomain.domain == data.domain)).first():
        return {"ok": True, "exists": True}
    session.add(WhitelistDomain(domain=data.domain))
    # 自动登记证书记录（待申请）
    cert = session.exec(select(CertificateRecord).where(CertificateRecord.domain == data.domain)).first()
    if not cert:
        session.add(CertificateRecord(domain=data.domain, verify_mode="http", status="pending"))
    session.commit()
    return {"ok": True}


@app.get(f"{base}/whitelist")
def list_domains(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return session.exec(select(WhitelistDomain)).all()


@app.post(f"{base}/cf-ips")
def add_cf_ip(data: CfIpIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    if session.exec(select(CfIp).where(CfIp.ip == data.ip, CfIp.port == data.port)).first():
        return {"ok": True, "exists": True}
    session.add(CfIp(ip=data.ip, port=data.port))
    session.commit()
    return {"ok": True}


@app.get(f"{base}/cf-ips")
def list_cf_ips(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return session.exec(select(CfIp).where(CfIp.enabled == True)).all()  # noqa: E712


@app.post(f"{base}/dns/config")
def set_dns_config(data: DnsConfigIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
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
    return {"results": results}


@app.post(f"{base}/sync")
async def sync(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    nodes: List[Node] = session.exec(select(Node).where(Node.enabled == True)).all()  # noqa: E712
    whitelist = [d.domain for d in session.exec(select(WhitelistDomain)).all()]
    cf_ips = [{"ip": x.ip, "port": x.port} for x in session.exec(select(CfIp).where(CfIp.enabled == True)).all()]  # noqa: E712
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
