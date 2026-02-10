from datetime import datetime
from typing import Optional
from sqlmodel import SQLModel, Field


class Admin(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    username: str = Field(index=True, unique=True)
    password_hash: str


class Node(SQLModel, table=True):
    id: Optional[int] = Field(default=None, primary_key=True)
    name: str = Field(index=True, unique=True)
    endpoint: str
    shared_secret: str
    enabled: bool = True
    cpu_cores: int = 0
    memory_mb: int = 0
    max_bandwidth_mbps: int = 0
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

