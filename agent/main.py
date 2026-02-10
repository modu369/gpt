import asyncio
import json
import os
import subprocess
import time
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


def _speedtest_snapshot() -> tuple[float, float]:
    # preferred: speedtest-cli
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

    # simple net usage estimate from /proc/net/dev delta over 1 second
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
