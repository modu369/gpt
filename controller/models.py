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
    effective_admin_path: str = "yun123"
    pending_admin_path: str = ""
    path_switch_deadline: Optional[datetime] = None
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
    traffic_low_threshold_percent: int = 10
    traffic_suspended: bool = False
    traffic_suspended_month: str = ""
    last_speedtest_at: datetime = Field(default_factory=datetime.utcnow)

    traffic_month: str = ""
    traffic_rx_gb: float = 0
    traffic_tx_gb: float = 0

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
    healthy: bool = True
    fail_count: int = 0
    health_score: int = 100
    icmp_ok: bool = True
    tcp_ok: bool = True
    http_ok: bool = True
    last_probe_detail: str = ""
    last_checked_at: datetime = Field(default_factory=datetime.utcnow)


class DnsScheduleConfig(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    cname: str = ""
    zone_id: str = ""
    ttl: int = 30
    low_traffic_threshold_percent: int = 10
    updated_at: datetime = Field(default_factory=datetime.utcnow)


class DnsAutoConfig(SQLModel, table=True):
    id: Optional[int] = Field(default=1, primary_key=True)
    enabled: bool = False
    interval_sec: int = 30
    change_threshold: int = 5
    debounce_sec: int = 10
    last_run_at: datetime = Field(default_factory=datetime.utcnow)
    last_message: str = ""
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
    dns_phase: str = "none"
    challenge_id: Optional[int] = None
    last_verify_at: Optional[datetime] = None
    updated_at: datetime = Field(default_factory=datetime.utcnow)


class AcmeChallenge(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    domain: str = Field(index=True)
    token: str = Field(index=True)
    content: str
    verify_mode: str = "http"
    status: str = "pending"
    provider: str = "manual"
    zone_id: str = ""
    record_name: str = ""
    verified_at: Optional[datetime] = None
    expires_at: Optional[datetime] = None
    version: int = 1
    state: str = "pending"  # pending|active|done|expired
    cleanup_state: str = "pending"
    updated_at: datetime = Field(default_factory=datetime.utcnow)


class OverloadEvent(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    node_name: str = Field(index=True)
    reason: str
    value: float = 0
    threshold: float = 0
    count: int = 1
    acknowledged: bool = False
    remark: str = ""
    occurred_at: datetime = Field(default_factory=datetime.utcnow)


class AuditLog(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    category: str = Field(index=True)
    action: str
    actor: str = "system"
    target: str = ""
    detail: str = ""
    created_at: datetime = Field(default_factory=datetime.utcnow)
