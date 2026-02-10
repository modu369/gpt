import asyncio
import json
import os
import subprocess
from pathlib import Path

import httpx
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel

from .cert_manager import ensure_certificate
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


@app.on_event("startup")
async def startup() -> None:
    Path(settings.state_dir).mkdir(parents=True, exist_ok=True)
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
def cert_apply(data: CertApplyIn, x_agent_secret: str = Header(default="")):
    if x_agent_secret != settings.shared_secret:
        raise HTTPException(status_code=403, detail="Forbidden")
    ok, status, detail = ensure_certificate(data.domain, data.verify_mode)
    save_cert_state(data.domain, status, detail)
    if not ok:
        return {"ok": False, "status": status, "detail": detail}
    return {"ok": True, "status": status, "detail": detail}


def save_cert_state(domain: str, status: str, detail: str) -> None:
    path = Path(settings.state_dir) / "cert_state.json"
    state = {}
    if path.exists():
        try:
            state = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            state = {}
    state[domain] = {"status": status, "detail": detail}
    path.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")


def _metric_snapshot() -> dict:
    cpu = 0.0
    # simple fallback: load average / cores
    try:
        load1 = os.getloadavg()[0]
        cores = os.cpu_count() or 1
        cpu = min(100.0, max(0.0, (load1 / cores) * 100.0))
    except Exception:
        cpu = 0.0

    mem_total = 1
    mem_used = 0
    with open("/proc/meminfo", "r", encoding="utf-8") as f:
        lines = f.readlines()
    kv = {x.split(":")[0]: int(x.split()[1]) for x in lines if ":" in x}
    mem_total = kv.get("MemTotal", 1)
    mem_available = kv.get("MemAvailable", 0)
    mem_used = max(0, mem_total - mem_available)

    bw = 0.0
    return {
        "cpu_percent": round(cpu, 2),
        "memory_mb_used": int(mem_used / 1024),
        "bandwidth_mbps_used": bw,
        "monthly_traffic_used_gb": 0.0,
    }


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
