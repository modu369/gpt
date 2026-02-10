import json
import os
import subprocess
from pathlib import Path
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel

from .config import settings
from .haproxy_renderer import render


app = FastAPI(title="CF Relay Agent")


class ConfigIn(BaseModel):
    whitelist: list[str]
    cf_ips: list[dict]
    updated_at: str


@app.on_event("startup")
def startup() -> None:
    Path(settings.state_dir).mkdir(parents=True, exist_ok=True)


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


if __name__ == "__main__":
    os.execvp(
        "uvicorn",
        ["uvicorn", "agent.main:app", "--host", settings.host, "--port", str(settings.port)],
    )
