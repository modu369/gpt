from datetime import datetime, time, timedelta
import asyncio
import os
import subprocess
from pathlib import Path
import secrets
from typing import List

import httpx
from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import FileResponse, HTMLResponse
from pydantic import BaseModel
from sqlmodel import Session, desc, select

from .config import settings
from .db import engine, get_session, init_db
from .models import (
    Admin,
    CertificateRecord,
    CfIp,
    DnsScheduleConfig,
    DnsAutoConfig,
    AcmeChallenge,
    OverloadEvent,
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
    traffic_month: str = ""
    traffic_rx_gb: float = 0
    traffic_tx_gb: float = 0


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


class NodeTrafficIn(BaseModel):
    enabled: bool = False
    monthly_limit_gb: int = 0
    count_mode: str = "both"
    low_threshold_percent: int = 10


class ChallengeSyncIn(BaseModel):
    domain: str
    token: str
    content: str


class DnsAutoConfigIn(BaseModel):
    enabled: bool = False
    interval_sec: int = 30
    change_threshold: int = 5


class CertDnsStartIn(BaseModel):
    provider: str = "manual"
    zone_id: str = ""
    record_name: str = ""
    record_value: str = ""


class CertDnsVerifyIn(BaseModel):
    challenge_id: int


class RuntimeApplyIn(BaseModel):
    keep_old_path_minutes: int = 15


class ChallengeCleanupIn(BaseModel):
    retain_recent: int = 1


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


_last_dns_signature = ""
_last_dns_weights: dict[str, int] = {}


def _normalize_panel_path(v: str) -> str:
    return (v or "").strip().strip("/")


def _path_window_is_active(cfg: SystemSetting) -> bool:
    return bool(cfg.pending_admin_path and cfg.path_switch_deadline and cfg.path_switch_deadline > datetime.utcnow())


def _normalize_runtime_window(session: Session, cfg: SystemSetting) -> None:
    if cfg.pending_admin_path and cfg.path_switch_deadline and cfg.path_switch_deadline <= datetime.utcnow():
        cfg.pending_admin_path = ""
        cfg.path_switch_deadline = None
        session.add(cfg)
        session.commit()


def _runtime_paths(session: Session) -> tuple[str, str, str, datetime | None]:
    cfg = get_system_setting(session)
    _normalize_runtime_window(session, cfg)
    effective = _normalize_panel_path(cfg.effective_admin_path or cfg.admin_path or settings.admin_path) or settings.admin_path
    desired = _normalize_panel_path(cfg.admin_path or effective) or effective
    pending = _normalize_panel_path(cfg.pending_admin_path)
    deadline = cfg.path_switch_deadline
    return effective, desired, pending, deadline


@app.on_event("startup")
async def startup() -> None:
    init_db()
    asyncio.create_task(cf_ip_health_loop())
    asyncio.create_task(dns_auto_reconcile_loop())


@app.middleware("http")
async def runtime_admin_path_alias(request: Request, call_next):
    raw_path = request.scope.get("path", "")
    if not raw_path.startswith("/"):
        return await call_next(request)
    default_path = f"/{settings.admin_path.strip('/')}"
    if raw_path.startswith(default_path):
        return await call_next(request)
    alias_paths: list[str] = []
    with Session(engine) as session:
        effective, _desired, pending, _deadline = _runtime_paths(session)
        for p in [effective, pending]:
            norm = _normalize_panel_path(p)
            if norm and norm != settings.admin_path and norm not in alias_paths:
                alias_paths.append(norm)
    for alias in alias_paths:
        prefix = f"/{alias}"
        if raw_path == prefix or raw_path.startswith(prefix + "/"):
            request.scope["path"] = raw_path.replace(prefix, default_path, 1)
            request.scope["raw_path"] = request.scope["path"].encode("utf-8")
            break
    return await call_next(request)


def get_system_setting(session: Session) -> SystemSetting:
    cfg = session.exec(select(SystemSetting).where(SystemSetting.id == 1)).first()
    if not cfg:
        cfg = SystemSetting(id=1)
        session.add(cfg)
        session.commit()
        session.refresh(cfg)
    if not cfg.effective_admin_path:
        cfg.effective_admin_path = cfg.admin_path or settings.admin_path
        session.add(cfg)
        session.commit()
        session.refresh(cfg)
    return cfg

def _expanded_cf_ips(session: Session) -> list[dict]:
    rows = session.exec(select(CfIp).where(CfIp.enabled == True, CfIp.healthy == True)).all()  # noqa: E712
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
    challenges = [{"domain": c.domain, "token": c.token, "content": c.content} for c in session.exec(select(AcmeChallenge).where(AcmeChallenge.status == "pending")).all()]
    payload = {"whitelist": whitelist, "cf_ips": cf_ips, "certificates": certs, "challenges": challenges, "updated_at": datetime.utcnow().isoformat()}

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
    challenges = [{"domain": c.domain, "token": c.token, "content": c.content} for c in session.exec(select(AcmeChallenge).where(AcmeChallenge.status == "pending")).all()]
    payload = {"whitelist": whitelist, "cf_ips": cf_ips, "certificates": certs, "challenges": challenges, "updated_at": datetime.utcnow().isoformat()}

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




async def cf_ip_health_loop() -> None:
    while True:
        try:
            with Session(engine) as session:
                changed = False
                ips = session.exec(select(CfIp)).all()
                for x in ips:
                    ok = subprocess.run(
                        ["ping", "-c", "1", "-W", "1", x.ip],
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.DEVNULL,
                        check=False,
                    ).returncode == 0
                    prev = x.healthy
                    if ok:
                        x.healthy = True
                        x.fail_count = 0
                    else:
                        x.fail_count = (x.fail_count or 0) + 1
                        if x.fail_count >= 2:
                            x.healthy = False
                    x.last_checked_at = datetime.utcnow()
                    if prev != x.healthy:
                        changed = True
                    session.add(x)
                session.commit()
                if changed:
                    await push_all_nodes(session)
        except Exception:
            pass
        await asyncio.sleep(5)


def record_overload_event(session: Session, node: Node, reason: str, value: float, threshold: float) -> None:
    now = datetime.utcnow()
    day_start = datetime.combine(now.date(), time.min)
    recent = session.exec(
        select(OverloadEvent)
        .where(
            OverloadEvent.node_name == node.name,
            OverloadEvent.reason == reason,
            OverloadEvent.occurred_at >= day_start,
        )
        .order_by(desc(OverloadEvent.occurred_at))
    ).first()
    if recent:
        recent.count += 1
        recent.value = value
        recent.threshold = threshold
        recent.occurred_at = now
        session.add(recent)
    else:
        session.add(OverloadEvent(node_name=node.name, reason=reason, value=value, threshold=threshold, occurred_at=now))

def get_dns_auto_config(session: Session) -> DnsAutoConfig:
    cfg = session.exec(select(DnsAutoConfig).where(DnsAutoConfig.id == 1)).first()
    if not cfg:
        cfg = DnsAutoConfig(id=1)
        session.add(cfg)
        session.commit()
        session.refresh(cfg)
    return cfg


def _candidate_node_weights(session: Session) -> list[NodeWeight]:
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
    return candidates


def _candidate_signature(candidates: list[NodeWeight]) -> str:
    rows = sorted((x.ip, x.weight) for x in candidates)
    return "|".join([f"{ip}:{w}" for ip, w in rows])


def _weight_delta(prev: dict[str, int], curr: list[NodeWeight]) -> int:
    delta = 0
    curr_map = {x.ip: x.weight for x in curr}
    for ip in set(prev.keys()) | set(curr_map.keys()):
        delta += abs(curr_map.get(ip, 0) - prev.get(ip, 0))
    return delta


def reconcile_dns_impl(session: Session) -> dict:
    cfg = session.exec(select(DnsScheduleConfig)).first()
    if not cfg:
        raise HTTPException(status_code=400, detail="dns config not set")
    candidates = _candidate_node_weights(session)
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
    auto_cfg = get_dns_auto_config(session)
    auto_cfg.last_run_at = datetime.utcnow()
    auto_cfg.last_message = message
    session.add(auto_cfg)
    session.commit()
    return {"ok": True, "message": message, "targets": [c.__dict__ for c in candidates]}


async def _doh_txt(name: str) -> list[str]:
    urls = [
        "https://cloudflare-dns.com/dns-query",
        "https://dns.google/resolve",
    ]
    out: list[str] = []
    async with httpx.AsyncClient(timeout=6.0) as client:
        for u in urls:
            try:
                if "cloudflare" in u:
                    r = await client.get(u, params={"name": name, "type": "TXT"}, headers={"accept": "application/dns-json"})
                else:
                    r = await client.get(u, params={"name": name, "type": "TXT"})
                data = r.json()
                answers = data.get("Answer") or []
                for ans in answers:
                    v = (ans.get("data") or "").strip('"')
                    if v:
                        out.append(v)
            except Exception:
                continue
    return out


async def dns_auto_reconcile_loop() -> None:
    global _last_dns_signature, _last_dns_weights
    while True:
        try:
            with Session(engine) as session:
                cfg = get_dns_auto_config(session)
                if settings.dns_auto_reconcile and cfg.enabled:
                    candidates = _candidate_node_weights(session)
                    sig = _candidate_signature(candidates)
                    delta = _weight_delta(_last_dns_weights, candidates)
                    if sig != _last_dns_signature and delta >= cfg.change_threshold:
                        reconcile_dns_impl(session)
                        _last_dns_signature = sig
                        _last_dns_weights = {x.ip: x.weight for x in candidates}
                    await asyncio.sleep(max(5, cfg.interval_sec))
                    continue
        except Exception:
            pass
        await asyncio.sleep(10)

@app.get(f"/{settings.admin_path}/healthz")
def panel_healthz():
    runtime_admin_path = settings.admin_path
    with Session(engine) as session:
        effective, desired, pending, deadline = _runtime_paths(session)
        runtime_admin_path = desired or effective
    return {
        "ok": True,
        "service": "controller",
        "admin_path": settings.admin_path,
        "runtime_admin_path": runtime_admin_path,
        "pending_admin_path": pending,
        "path_switch_deadline": deadline.isoformat() if deadline else "",
    }


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
    _normalize_runtime_window(session, cfg)
    return cfg


@app.post(f"{base}/settings")
def set_settings(data: SettingsIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = get_system_setting(session)
    cfg.controller_port = data.controller_port
    cfg.admin_path = _normalize_panel_path(data.admin_path)
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
    return {"ok": True, "message": "保存成功（账号密码立即生效；可点击“应用运行时变更”实现路径窗口切换与服务平滑重载）"}


@app.post(f"{base}/settings/apply-runtime")
def apply_runtime_settings(data: RuntimeApplyIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = get_system_setting(session)
    desired_path = _normalize_panel_path(cfg.admin_path)
    effective_path = _normalize_panel_path(cfg.effective_admin_path or settings.admin_path)
    keep_minutes = max(1, min(120, data.keep_old_path_minutes))
    switch_deadline = datetime.utcnow() + timedelta(minutes=keep_minutes)

    if desired_path != effective_path:
        cfg.pending_admin_path = effective_path
        cfg.path_switch_deadline = switch_deadline
        cfg.effective_admin_path = desired_path
    else:
        cfg.pending_admin_path = ""
        cfg.path_switch_deadline = None
    cfg.updated_at = datetime.utcnow()
    session.add(cfg)
    session.commit()

    service_restart = "skipped"
    if os.path.exists("/bin/systemctl") or os.path.exists("/usr/bin/systemctl"):
        try:
            subprocess.run(["systemctl", "restart", "cfrelay-controller"], check=False)
            service_restart = "triggered"
        except Exception as exc:
            service_restart = f"failed: {exc}"

    result = {
        "ok": True,
        "target_port": cfg.controller_port,
        "effective_admin_path": cfg.effective_admin_path,
        "pending_admin_path": cfg.pending_admin_path,
        "path_switch_deadline": cfg.path_switch_deadline.isoformat() if cfg.path_switch_deadline else "",
        "message": "运行时配置已应用：新路径立即可用；旧路径在窗口期内保留后自动失效。",
        "service_restart": service_restart,
    }
    return result


@app.post(f"{base}/settings/revert-runtime")
def revert_runtime_settings(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = get_system_setting(session)
    _normalize_runtime_window(session, cfg)
    if not cfg.pending_admin_path:
        return {"ok": True, "message": "当前没有可回滚的路径窗口", "effective_admin_path": cfg.effective_admin_path}

    cfg.admin_path = cfg.pending_admin_path
    cfg.effective_admin_path = cfg.pending_admin_path
    cfg.pending_admin_path = ""
    cfg.path_switch_deadline = None
    cfg.updated_at = datetime.utcnow()
    session.add(cfg)
    session.commit()
    return {"ok": True, "message": "已回滚到旧路径", "effective_admin_path": cfg.effective_admin_path}

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
@app.post(f"{base}/nodes/{{node_name}}/unregister")
async def unregister_node(node_name: str, x_agent_secret: str = Header(default=""), session: Session = Depends(get_session)):
    node = session.exec(select(Node).where(Node.name == node_name)).first()
    if not node:
        return {"ok": True, "exists": False}
    if x_agent_secret != node.shared_secret:
        raise HTTPException(status_code=403, detail="Forbidden")
    session.delete(node)
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
    node.traffic_month = data.traffic_month or datetime.utcnow().strftime("%Y-%m")
    node.traffic_rx_gb = max(0.0, data.traffic_rx_gb)
    node.traffic_tx_gb = max(0.0, data.traffic_tx_gb)
    if node.traffic_count_mode == "ingress":
        node.monthly_traffic_used_gb = node.traffic_rx_gb
    elif node.traffic_count_mode == "egress":
        node.monthly_traffic_used_gb = node.traffic_tx_gb
    else:
        calc_both = node.traffic_rx_gb + node.traffic_tx_gb
        node.monthly_traffic_used_gb = calc_both if calc_both > 0 else max(0.0, data.monthly_traffic_used_gb)
    node.online = True
    node.updated_at = datetime.utcnow()
    if node.used_cpu_percent >= 90:
        record_overload_event(session, node, "cpu", node.used_cpu_percent, 90)
    mem_ratio = (node.used_memory_mb / node.memory_mb * 100) if node.memory_mb else 0
    if mem_ratio >= 90:
        record_overload_event(session, node, "memory", mem_ratio, 90)
    bw_ratio = (node.used_bandwidth_mbps / node.max_bandwidth_mbps * 100) if node.max_bandwidth_mbps else 0
    if bw_ratio >= 90:
        record_overload_event(session, node, "bandwidth", bw_ratio, 90)

    if node.traffic_limit_enabled and node.monthly_traffic_limit_gb > 0:
        ratio = node.monthly_traffic_used_gb / node.monthly_traffic_limit_gb
        remain_percent = max(0.0, (1 - ratio) * 100)
        if remain_percent <= node.traffic_low_threshold_percent:
            node.enabled = False
            node.traffic_suspended = True
            node.traffic_suspended_month = datetime.utcnow().strftime("%Y-%m")
            record_overload_event(session, node, "traffic", remain_percent, node.traffic_low_threshold_percent)
        # auto resume next month
        current_month = datetime.utcnow().strftime("%Y-%m")
        if node.traffic_suspended and node.traffic_suspended_month and node.traffic_suspended_month != current_month:
            node.enabled = True
            node.traffic_suspended = False
            node.monthly_traffic_used_gb = 0

    session.add(node)
    session.commit()

    auto_cfg = get_dns_auto_config(session)
    if settings.dns_auto_reconcile and auto_cfg.enabled:
        global _last_dns_signature, _last_dns_weights
        candidates = _candidate_node_weights(session)
        sig = _candidate_signature(candidates)
        delta = _weight_delta(_last_dns_weights, candidates)
        if sig != _last_dns_signature and delta >= auto_cfg.change_threshold:
            try:
                reconcile_dns_impl(session)
                _last_dns_signature = sig
                _last_dns_weights = {x.ip: x.weight for x in candidates}
            except Exception:
                pass
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


@app.post(f"{base}/nodes/{{node_name}}/traffic-config")
def set_node_traffic_config(node_name: str, data: NodeTrafficIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    node = session.exec(select(Node).where(Node.name == node_name)).first()
    if not node:
        raise HTTPException(status_code=404, detail="node not found")
    node.traffic_limit_enabled = data.enabled
    node.monthly_traffic_limit_gb = data.monthly_limit_gb
    node.traffic_count_mode = data.count_mode
    node.traffic_low_threshold_percent = data.low_threshold_percent
    session.add(node)
    session.commit()
    return {"ok": True}


@app.get(f"{base}/overload-events")
def list_overload_events(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return session.exec(select(OverloadEvent).order_by(OverloadEvent.occurred_at.desc())).all()


@app.delete(f"{base}/overload-events")
def clear_overload_events(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    rows = session.exec(select(OverloadEvent)).all()
    for r in rows:
        session.delete(r)
    session.commit()
    return {"ok": True, "count": len(rows)}


@app.post(f"{base}/acme/challenges")
async def add_challenge(data: ChallengeSyncIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(AcmeChallenge).where(AcmeChallenge.domain == data.domain, AcmeChallenge.token == data.token)).first()
    if not rec:
        last = session.exec(
            select(AcmeChallenge)
            .where(AcmeChallenge.domain == data.domain)
            .order_by(desc(AcmeChallenge.id))
        ).first()
        rec = AcmeChallenge(
            domain=data.domain,
            token=data.token,
            content=data.content,
            status="pending",
            state="pending",
            version=(last.version + 1) if last else 1,
        )
    else:
        rec.content = data.content
        rec.status = "pending"
        rec.state = "pending"
        rec.cleanup_state = "pending"
        rec.updated_at = datetime.utcnow()
    session.add(rec)
    session.commit()
    await push_all_nodes(session)
    return {"ok": True}


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
    rows = session.exec(select(CfIp).where(CfIp.enabled == True, CfIp.healthy == True)).all()  # noqa: E712
    return [{"ip": x.ip, "ports": [80, 443], "enabled": x.enabled, "healthy": x.healthy, "fail_count": x.fail_count, "last_checked_at": x.last_checked_at} for x in rows]


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
    return reconcile_dns_impl(session)


@app.post(f"{base}/dns/reconcile/now")
def reconcile_dns_now(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return reconcile_dns_impl(session)


@app.post(f"{base}/dns/auto-config")
def set_dns_auto_config(data: DnsAutoConfigIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    cfg = get_dns_auto_config(session)
    cfg.enabled = data.enabled
    cfg.interval_sec = max(5, data.interval_sec)
    cfg.change_threshold = max(1, data.change_threshold)
    cfg.updated_at = datetime.utcnow()
    session.add(cfg)
    session.commit()
    return {"ok": True, "config": cfg}


@app.get(f"{base}/dns/auto-config")
def get_dns_auto(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return get_dns_auto_config(session)


@app.post(f"{base}/certificates/{{domain}}/dns/start")
async def start_certificate_dns(domain: str, data: CertDnsStartIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    if not settings.cert_dns_v2:
        raise HTTPException(status_code=404, detail="CERT_DNS_V2 disabled")
    token = data.record_value.strip() or secrets.token_urlsafe(24)
    record_name = data.record_name.strip() or f"_acme-challenge.{domain}"
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == domain)).first()
    if not rec:
        rec = CertificateRecord(domain=domain, verify_mode="dns", status="pending")
    rec.verify_mode = "dns"
    rec.dns_phase = "waiting_dns"
    rec.status = "pending"
    rec.updated_at = datetime.utcnow()

    last = session.exec(
        select(AcmeChallenge)
        .where(AcmeChallenge.domain == domain, AcmeChallenge.verify_mode == "dns")
        .order_by(desc(AcmeChallenge.id))
    ).first()
    next_version = (last.version + 1) if last else 1

    chall = AcmeChallenge(
        domain=domain,
        token=token,
        content=token,
        verify_mode="dns",
        status="pending",
        provider=data.provider or "manual",
        zone_id=data.zone_id or "",
        record_name=record_name,
        expires_at=datetime.utcnow() + timedelta(days=7),
        version=next_version,
        state="pending",
        cleanup_state="pending",
    )
    session.add(chall)
    session.commit()
    session.refresh(chall)
    rec.challenge_id = chall.id
    session.add(rec)
    session.commit()
    await push_all_nodes(session)
    return {
        "ok": True,
        "challenge_id": chall.id,
        "txt_name": record_name,
        "txt_value": token,
        "status": "waiting_dns",
    }


@app.post(f"{base}/certificates/{{domain}}/dns/verify")
async def verify_certificate_dns(domain: str, data: CertDnsVerifyIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    if not settings.cert_dns_v2:
        raise HTTPException(status_code=404, detail="CERT_DNS_V2 disabled")
    chall = session.exec(select(AcmeChallenge).where(AcmeChallenge.id == data.challenge_id, AcmeChallenge.domain == domain)).first()
    if not chall:
        raise HTTPException(status_code=404, detail="challenge not found")

    values = await _doh_txt(chall.record_name or f"_acme-challenge.{domain}")
    if chall.content not in values:
        return {"ok": False, "status": "waiting_dns", "detail": "TXT not propagated", "answers": values}

    nodes: List[Node] = session.exec(select(Node).where(Node.enabled == True)).all()  # noqa: E712
    if not nodes:
        raise HTTPException(status_code=400, detail="no enabled nodes")
    n = nodes[0]
    async with httpx.AsyncClient(timeout=10.0) as client:
        r = await client.post(
            f"{n.endpoint.rstrip('/')}/agent/cert/precheck",
            json={"domain": domain, "verify_mode": "dns"},
            headers={"X-Agent-Secret": n.shared_secret},
        )
    if r.status_code != 200:
        raise HTTPException(status_code=r.status_code, detail=r.text)

    chall.status = "verified"
    chall.state = "active"
    chall.verified_at = datetime.utcnow()
    chall.cleanup_state = "active"
    chall.updated_at = datetime.utcnow()

    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == domain)).first()
    if not rec:
        rec = CertificateRecord(domain=domain, verify_mode="dns")
    rec.verify_mode = "dns"
    rec.status = "retrying"
    rec.dns_phase = "issuing"
    rec.challenge_id = chall.id
    rec.last_verify_at = datetime.utcnow()
    rec.updated_at = datetime.utcnow()
    session.add(chall)
    session.add(rec)
    session.commit()
    return await dispatch_certificate_apply(domain, "dns", session)

@app.post(f"{base}/certificates/{{domain}}/precheck")
async def precheck_certificate(domain: str, verify_mode: str = "http", _: Admin = Depends(auth), session: Session = Depends(get_session)):
    nodes: List[Node] = session.exec(select(Node).where(Node.enabled == True)).all()  # noqa: E712
    if not nodes:
        raise HTTPException(status_code=400, detail="no enabled nodes")
    n = nodes[0]
    async with httpx.AsyncClient(timeout=10.0) as client:
        r = await client.post(
            f"{n.endpoint.rstrip('/')}/agent/cert/precheck",
            json={"domain": domain, "verify_mode": verify_mode},
            headers={"X-Agent-Secret": n.shared_secret},
        )
    data = r.json()
    if r.status_code != 200:
        raise HTTPException(status_code=r.status_code, detail=data)
    if verify_mode == "dns" and data.get("ok"):
        token = data.get("txt_value", "")
        content = data.get("txt_value", "")
        if token:
            chall = session.exec(select(AcmeChallenge).where(AcmeChallenge.domain == domain, AcmeChallenge.token == token)).first()
            if not chall:
                chall = AcmeChallenge(domain=domain, token=token, content=content, verify_mode="dns", status="pending")
            session.add(chall)
            session.commit()
    return data


@app.post(f"{base}/certificates/manual")
async def add_certificate_manual(data: CertRequestIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    rec = session.exec(select(CertificateRecord).where(CertificateRecord.domain == data.domain)).first()
    if not rec:
        rec = CertificateRecord(domain=data.domain, verify_mode=data.verify_mode)
        session.add(rec)
    rec.status = "pending"
    rec.fail_reason = ""
    rec.verify_mode = data.verify_mode
    rec.dns_phase = "none" if data.verify_mode == "http" else "waiting_dns"
    rec.updated_at = datetime.utcnow()
    session.add(rec)
    session.commit()
    pre = await precheck_certificate(data.domain, data.verify_mode, _, session)
    if not pre.get("ok"):
        rec.status = "failed"
        rec.fail_reason = str(pre)
        session.add(rec)
        session.commit()
        return {"precheck": pre}
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
        rec.dns_phase = "issued" if success and verify_mode == "dns" else rec.dns_phase
        rec.fail_reason = "" if success else str(results)
        rec.last_synced_node = ",".join([x.get("node", "") for x in results if x.get("status") == 200])
        rec.updated_at = datetime.utcnow()
        session.add(rec)
        if success and verify_mode == "dns" and rec.challenge_id:
            chall = session.exec(select(AcmeChallenge).where(AcmeChallenge.id == rec.challenge_id)).first()
            if chall:
                chall.status = "done"
                chall.state = "done"
                chall.cleanup_state = "done"
                chall.updated_at = datetime.utcnow()
                session.add(chall)
        session.commit()
    await push_all_nodes(session)
    return {"results": results}


@app.post(f"{base}/acme/challenges/cleanup")
async def cleanup_challenges(data: ChallengeCleanupIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    keep = max(1, min(5, data.retain_recent))
    now = datetime.utcnow()
    challenges = session.exec(select(AcmeChallenge).order_by(desc(AcmeChallenge.id))).all()
    keep_ids: set[int] = set()
    for row in challenges:
        if row.verify_mode != "dns":
            continue
        dom_rows = session.exec(
            select(AcmeChallenge)
            .where(AcmeChallenge.domain == row.domain, AcmeChallenge.verify_mode == "dns")
            .order_by(desc(AcmeChallenge.version), desc(AcmeChallenge.id))
        ).all()
        for item in dom_rows[:keep]:
            if item.id is not None:
                keep_ids.add(item.id)

    cleaned = 0
    expired = 0
    for row in challenges:
        if row.id in keep_ids:
            continue
        if row.status == "done" or row.cleanup_state == "done":
            row.state = "done"
            row.cleanup_state = "done"
            row.updated_at = now
            session.add(row)
            cleaned += 1
            continue
        if row.expires_at and row.expires_at < now:
            row.status = "expired"
            row.state = "expired"
            row.cleanup_state = "expired"
            row.updated_at = now
            session.add(row)
            expired += 1

    session.commit()
    await push_all_nodes(session)
    return {"ok": True, "cleaned": cleaned, "expired": expired, "kept": len(keep_ids)}


@app.get(f"{base}/acme/challenges")
def list_challenges(domain: str = "", _: Admin = Depends(auth), session: Session = Depends(get_session)):
    query = select(AcmeChallenge)
    if domain.strip():
        query = query.where(AcmeChallenge.domain == domain.strip())
    rows = session.exec(query.order_by(desc(AcmeChallenge.id))).all()
    return rows


@app.post(f"{base}/sync")
async def sync(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return await push_all_nodes(session)
