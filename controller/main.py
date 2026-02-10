from datetime import datetime
import secrets
from typing import List

import httpx
from fastapi import Depends, FastAPI, HTTPException, Header
from pydantic import BaseModel
from sqlmodel import Session, select

from .config import settings
from .db import get_session, init_db
from .models import Admin, CfIp, Node, NodeToken, WhitelistDomain
from .security import create_access_token, decode_token, verify_password


app = FastAPI(title="CF Relay Controller")


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


class DomainIn(BaseModel):
    domain: str


class CfIpIn(BaseModel):
    ip: str
    port: int = 443


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


base = f"/{settings.admin_path}/api"


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
    if node:
        node.endpoint = data.endpoint
        node.shared_secret = data.shared_secret
        node.cpu_cores = data.cpu_cores
        node.memory_mb = data.memory_mb
        node.max_bandwidth_mbps = data.max_bandwidth_mbps
        node.updated_at = datetime.utcnow()
    else:
        node = Node(
            name=data.name,
            endpoint=data.endpoint,
            shared_secret=data.shared_secret,
            cpu_cores=data.cpu_cores,
            memory_mb=data.memory_mb,
            max_bandwidth_mbps=data.max_bandwidth_mbps,
        )
        session.add(node)
    session.add(t)
    session.commit()
    return {"ok": True}


@app.get(f"{base}/nodes")
def list_nodes(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    return session.exec(select(Node)).all()


@app.post(f"{base}/whitelist")
def add_domain(data: DomainIn, _: Admin = Depends(auth), session: Session = Depends(get_session)):
    if session.exec(select(WhitelistDomain).where(WhitelistDomain.domain == data.domain)).first():
        return {"ok": True, "exists": True}
    session.add(WhitelistDomain(domain=data.domain))
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


@app.post(f"{base}/sync")
async def sync(_: Admin = Depends(auth), session: Session = Depends(get_session)):
    nodes: List[Node] = session.exec(select(Node).where(Node.enabled == True)).all()  # noqa: E712
    whitelist = [d.domain for d in session.exec(select(WhitelistDomain)).all()]
    cf_ips = [{"ip": x.ip, "port": x.port} for x in session.exec(select(CfIp).where(CfIp.enabled == True)).all()]  # noqa: E712
    payload = {"whitelist": whitelist, "cf_ips": cf_ips, "updated_at": datetime.utcnow().isoformat()}

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
