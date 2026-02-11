import os
from pydantic import BaseModel


class Settings(BaseModel):
    host: str = os.getenv("AGENT_HOST", "0.0.0.0")
    port: int = int(os.getenv("AGENT_PORT", "18080"))
    shared_secret: str = os.getenv("AGENT_SHARED_SECRET", "change-me")
    state_dir: str = os.getenv("AGENT_STATE_DIR", "/opt/cfrelay")
    haproxy_cfg: str = os.getenv("HAPROXY_CFG", "/etc/haproxy/haproxy.cfg")
    controller_api_base: str = os.getenv("CONTROLLER_API_BASE", "")
    node_name: str = os.getenv("NODE_NAME", "")
    upstream_timeout_s: float = float(os.getenv("UPSTREAM_TIMEOUT_S", "15"))
    pool_max_connections: int = int(os.getenv("POOL_MAX_CONNECTIONS", "200"))
    pool_max_keepalive_connections: int = int(os.getenv("POOL_MAX_KEEPALIVE_CONNECTIONS", "80"))
    pool_keepalive_expiry_s: float = float(os.getenv("POOL_KEEPALIVE_EXPIRY_S", "30"))


settings = Settings()
