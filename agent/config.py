import os
from pydantic import BaseModel


class Settings(BaseModel):
    host: str = os.getenv("AGENT_HOST", "0.0.0.0")
    port: int = int(os.getenv("AGENT_PORT", "18080"))
    shared_secret: str = os.getenv("AGENT_SHARED_SECRET", "change-me")
    state_dir: str = os.getenv("AGENT_STATE_DIR", "/opt/cfrelay")
    haproxy_cfg: str = os.getenv("HAPROXY_CFG", "/etc/haproxy/haproxy.cfg")


settings = Settings()
