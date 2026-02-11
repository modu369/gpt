import asyncio
import json
import os
import socket
import subprocess
import time
from pathlib import Path

import httpx
from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.responses import PlainTextResponse, Response
from pydantic import BaseModel

from .cert_manager import WWWROOT, ensure_certificate
from .config import settings
from .haproxy_renderer import render


app = FastAPI(title="CF Relay Agent")


class ConfigIn(BaseModel):
    whitelist: list[str]
    cf_ips: list[dict]
    certificates: list[dict] = []
    updated_at: str


class CertApplyIn(BaseModel):
    domain: str
    verify_mode: str = "http"


_CERT_TASKS: dict[str, asyncio.Task] = {}


@app.on_event("startup")
async def startup() -> None:
    Path(settings.state_dir).mkdir(parents=True, exist_ok=True)
    Path(WWWROOT).mkdir(parents=True, exist_ok=True)
    asyncio.create_task(report_metrics_loop())


@app.get("/healthz")
def healthz():
    return {"ok": True}


@app.post("/agent/config")
def apply_config(data: ConfigIn, x_agent_secret: str = Header(default="")):
    if x_agent_secret != settings.shared_secret:
        raise HTTPException(status_code=403, detail="Forbidden")

    state_path = Path(settings.state_dir) / "runtime_config.json"
    state_path.write_text(json.dumps(data.model_dump(), ensure_ascii=False, indent=2), encoding="utf-8")

    cfg_text = render(data.model_dump())
    cfg_path = Path(settings.haproxy_cfg)
    cfg_path.write_text(cfg_text, encoding="utf-8")

    check = subprocess.run(["haproxy", "-c", "-f", str(cfg_path)], capture_output=True, text=True)
    if check.returncode != 0:
        raise HTTPException(status_code=500, detail=f"haproxy cfg invalid: {check.stderr}")

    subprocess.run(["systemctl", "reload", "haproxy"], check=False)
    return {"ok": True}


@app.post("/agent/cert/apply")
async def cert_apply(data: CertApplyIn, x_agent_secret: str = Header(default="")):
    if x_agent_secret != settings.shared_secret:
        raise HTTPException(status_code=403, detail="Forbidden")
    ok, status, detail = ensure_certificate(data.domain, data.verify_mode)
    save_cert_state(data.domain, status, detail)
    await report_cert_status(data.domain, status, detail, data.verify_mode)
    if not ok:
        return {"ok": False, "status": status, "detail": detail}
    return {"ok": True, "status": status, "detail": detail}


@app.post("/agent/network/speedtest")
def speedtest(x_agent_secret: str = Header(default="")):
    if x_agent_secret != settings.shared_secret:
        raise HTTPException(status_code=403, detail="Forbidden")
    download, upload = _speedtest_snapshot()
    return {
        "download_mbps": download,
        "upload_mbps": upload,
        "effective_mbps": int(min(download, upload)),
    }


@app.api_route("/{path:path}", methods=["GET", "POST", "PUT", "DELETE", "PATCH", "HEAD", "OPTIONS"])
async def gate_http(path: str, request: Request):
    if path.startswith("agent/") or path == "healthz":
        raise HTTPException(status_code=404, detail="Not Found")

    if path.startswith(".well-known/acme-challenge/"):
        token = path.split("/", 3)[-1]
        p = Path(WWWROOT) / token
        if p.exists():
            return PlainTextResponse(p.read_text(encoding="utf-8"))
        return PlainTextResponse("challenge token not found", status_code=404)

    host = (request.headers.get("host") or "").split(":")[0].strip().lower()
    if not host:
        return PlainTextResponse("missing host", status_code=400)

    cfg = load_runtime_cfg()
    whitelist = cfg.get("whitelist", [])
    if whitelist and host not in whitelist:
        return PlainTextResponse("forbidden host", status_code=403)

    if not _domain_resolves_to_node(host):
        return PlainTextResponse("domain not resolved to this node", status_code=403)

    cert_state = load_cert_state().get(host, {})
    status = cert_state.get("status", "pending")
    if status != "issued":
        _schedule_cert_issue(host)
        return PlainTextResponse("certificate is being issued, retry later", status_code=425)

    cf_ips = cfg.get("cf_ips", [])
    if not cf_ips:
        return PlainTextResponse("no cf backend configured", status_code=503)

    xf_proto = request.headers.get("x-forwarded-proto", "http")
    preferred_port = 443 if xf_proto == "https" else 80
    target = next((x for x in cf_ips if int(x.get("port", 0)) == preferred_port), cf_ips[0])
    target_url = f"http://{target['ip']}:{target['port']}/{path}"
    if request.url.query:
        target_url = f"{target_url}?{request.url.query}"

    body = await request.body()
    headers = dict(request.headers)
    headers["host"] = host
    headers.pop("content-length", None)

    async with httpx.AsyncClient(timeout=15.0, follow_redirects=False) as client:
        upstream = await client.request(request.method, target_url, headers=headers, content=body)

    excluded = {"content-encoding", "transfer-encoding", "connection"}
    rsp_headers = {k: v for k, v in upstream.headers.items() if k.lower() not in excluded}
    return Response(content=upstream.content, status_code=upstream.status_code, headers=rsp_headers)


def _schedule_cert_issue(domain: str) -> None:
    task = _CERT_TASKS.get(domain)
    if task and not task.done():
        return
    _CERT_TASKS[domain] = asyncio.create_task(_issue_and_report(domain))


async def _issue_and_report(domain: str) -> None:
    ok, status, detail = ensure_certificate(domain, "http")
    save_cert_state(domain, status, detail)
    await report_cert_status(domain, status, detail, "http")


def _domain_resolves_to_node(domain: str) -> bool:
    try:
        _, _, ips = socket.gethostbyname_ex(domain)
    except Exception:
        return False
    local_ips = set()
    try:
        local_ips.update(socket.gethostbyname_ex(socket.gethostname())[2])
    except Exception:
        pass
    try:
        out = subprocess.check_output(["bash", "-lc", "curl -fsSL https://api.ipify.org || true"], text=True, timeout=3).strip()
        if out:
            local_ips.add(out)
    except Exception:
        pass
    return any(ip in local_ips for ip in ips)


def load_runtime_cfg() -> dict:
    path = Path(settings.state_dir) / "runtime_config.json"
    if not path.exists():
        return {"whitelist": [], "cf_ips": [], "certificates": []}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {"whitelist": [], "cf_ips": [], "certificates": []}


def load_cert_state() -> dict:
    path = Path(settings.state_dir) / "cert_state.json"
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}


async def report_cert_status(domain: str, status: str, detail: str, verify_mode: str) -> None:
    if not settings.controller_api_base or not settings.node_name:
        return
    url = f"{settings.controller_api_base.rstrip('/')}/nodes/{settings.node_name}/certificates/report"
    payload = {"domain": domain, "status": status, "detail": detail, "verify_mode": verify_mode}
    try:
        async with httpx.AsyncClient(timeout=6.0) as client:
            await client.post(url, json=payload, headers={"X-Agent-Secret": settings.shared_secret})
    except Exception:
        pass


def _speedtest_snapshot() -> tuple[float, float]:
    if shutil_which("speedtest-cli"):
        try:
            r = subprocess.run(["speedtest-cli", "--simple"], capture_output=True, text=True, timeout=60)
            if r.returncode == 0:
                down = 0.0
                up = 0.0
                for line in r.stdout.splitlines():
                    if line.startswith("Download"):
                        down = float(line.split()[1])
                    if line.startswith("Upload"):
                        up = float(line.split()[1])
                if down > 0 and up > 0:
                    return down, up
        except Exception:
            pass
    return 100.0, 100.0


def shutil_which(cmd: str) -> bool:
    return subprocess.call(["bash", "-lc", f"command -v {cmd}"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) == 0


def save_cert_state(domain: str, status: str, detail: str) -> None:
    path = Path(settings.state_dir) / "cert_state.json"
    state = load_cert_state()
    state[domain] = {"status": status, "detail": detail}
    path.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")


def _metric_snapshot() -> dict:
    cpu = 0.0
    try:
        load1 = os.getloadavg()[0]
        cores = os.cpu_count() or 1
        cpu = min(100.0, max(0.0, (load1 / cores) * 100.0))
    except Exception:
        cpu = 0.0

    with open("/proc/meminfo", "r", encoding="utf-8") as f:
        lines = f.readlines()
    kv = {x.split(":")[0]: int(x.split()[1]) for x in lines if ":" in x}
    mem_total = kv.get("MemTotal", 1)
    mem_available = kv.get("MemAvailable", 0)
    mem_used = max(0, mem_total - mem_available)

    bw = 0.0
    try:
        b1 = _sum_bytes()
        time.sleep(1)
        b2 = _sum_bytes()
        bw = max(0.0, (b2 - b1) * 8 / 1_000_000)
    except Exception:
        bw = 0.0

    return {
        "cpu_percent": round(cpu, 2),
        "memory_mb_used": int(mem_used / 1024),
        "bandwidth_mbps_used": round(bw, 2),
        "monthly_traffic_used_gb": 0.0,
    }


def _sum_bytes() -> int:
    total = 0
    with open("/proc/net/dev", "r", encoding="utf-8") as f:
        for line in f.readlines()[2:]:
            parts = line.replace(":", " ").split()
            if len(parts) >= 10:
                total += int(parts[1]) + int(parts[9])
    return total


async def report_metrics_loop() -> None:
    if not settings.controller_api_base or not settings.node_name:
        return
    while True:
        payload = _metric_snapshot()
        url = f"{settings.controller_api_base.rstrip('/')}/nodes/{settings.node_name}/metrics"
        try:
            async with httpx.AsyncClient(timeout=5.0) as client:
                await client.post(url, json=payload, headers={"X-Agent-Secret": settings.shared_secret})
        except Exception:
            pass
        await asyncio.sleep(5)


if __name__ == "__main__":
    os.execvp(
        "uvicorn",
        ["uvicorn", "agent.main:app", "--host", settings.host, "--port", str(settings.port)],
    )
