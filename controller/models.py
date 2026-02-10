from datetime import datetime
from typing import Optional
from sqlmodel import SQLModel, Field


class Admin(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    username: str = Field(index=True, unique=True)
    password_hash: str


class SystemSetting(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    controller_port: int = 8080
    admin_path: str = "yun123"
    default_admin_user: str = "admin"
    default_admin_password: str = "admin123"
    updated_at: datetime = Field(default_factory=datetime.utcnow)


class Node(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    name: str = Field(index=True, unique=True)
    endpoint: str
    shared_secret: str
    enabled: bool = True

    cpu_cores: int = 0
    memory_mb: int = 0
    max_bandwidth_mbps: int = 0

    # runtime metrics
    used_cpu_percent: float = 0
    used_memory_mb: int = 0
    used_bandwidth_mbps: float = 0
    online: bool = False

    monthly_traffic_limit_gb: int = 0
    monthly_traffic_used_gb: float = 0
    traffic_limit_enabled: bool = False
    traffic_count_mode: str = "both"  # both|ingress|egress
    last_speedtest_at: datetime = Field(default_factory=datetime.utcnow)

    updated_at: datetime = Field(default_factory=datetime.utcnow)


class NodeToken(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    token: str = Field(index=True, unique=True)
    used: bool = False
    created_at: datetime = Field(default_factory=datetime.utcnow)


class WhitelistDomain(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    domain: str = Field(index=True, unique=True)


class CfIp(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    ip: str = Field(index=True, unique=True)
    port: int = 443
    enabled: bool = True


class DnsScheduleConfig(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    cname: str = ""
    zone_id: str = ""
    ttl: int = 30
    low_traffic_threshold_percent: int = 10
    updated_at: datetime = Field(default_factory=datetime.utcnow)


class CertificateRecord(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    domain: str = Field(index=True, unique=True)
    verify_mode: str = "http"  # http|dns
    status: str = "pending"  # pending|issued|failed|retrying
    fail_reason: str = ""
    retries: int = 0
    last_synced_node: str = ""
    cert_expires_at: str = ""
    updated_at: datetime = Field(default_factory=datetime.utcnow)
