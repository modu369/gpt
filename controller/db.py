from sqlalchemy import text
from sqlmodel import Session, SQLModel, create_engine, select

from .config import settings
from .models import Admin, SystemSetting
from .security import hash_password


engine = create_engine(settings.db_url, connect_args={"check_same_thread": False})


def _sqlite_add_column_if_missing(table: str, column: str, ddl: str) -> None:
    with engine.begin() as conn:
        cols = [r[1] for r in conn.execute(text(f"PRAGMA table_info({table})")).fetchall()]
        if column not in cols:
            conn.execute(text(f"ALTER TABLE {table} ADD COLUMN {column} {ddl}"))


def migrate_schema() -> None:
    if not settings.db_url.startswith("sqlite"):
        return
    _sqlite_add_column_if_missing("node", "last_speedtest_at", "DATETIME")
    _sqlite_add_column_if_missing("node", "traffic_limit_enabled", "BOOLEAN DEFAULT 0")
    _sqlite_add_column_if_missing("node", "traffic_count_mode", "VARCHAR DEFAULT 'both'")
    _sqlite_add_column_if_missing("node", "traffic_low_threshold_percent", "INTEGER DEFAULT 10")
    _sqlite_add_column_if_missing("node", "traffic_suspended", "BOOLEAN DEFAULT 0")
    _sqlite_add_column_if_missing("node", "traffic_suspended_month", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("cfip", "healthy", "BOOLEAN DEFAULT 1")
    _sqlite_add_column_if_missing("cfip", "fail_count", "INTEGER DEFAULT 0")
    _sqlite_add_column_if_missing("cfip", "health_score", "INTEGER DEFAULT 100")
    _sqlite_add_column_if_missing("cfip", "icmp_ok", "BOOLEAN DEFAULT 1")
    _sqlite_add_column_if_missing("cfip", "tcp_ok", "BOOLEAN DEFAULT 1")
    _sqlite_add_column_if_missing("cfip", "http_ok", "BOOLEAN DEFAULT 1")
    _sqlite_add_column_if_missing("cfip", "last_probe_detail", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("cfip", "last_checked_at", "DATETIME")
    _sqlite_add_column_if_missing("certificaterecord", "fail_reason", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("certificaterecord", "retries", "INTEGER DEFAULT 0")
    _sqlite_add_column_if_missing("certificaterecord", "last_synced_node", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("node", "traffic_month", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("node", "traffic_rx_gb", "FLOAT DEFAULT 0")
    _sqlite_add_column_if_missing("node", "traffic_tx_gb", "FLOAT DEFAULT 0")
    _sqlite_add_column_if_missing("certificaterecord", "dns_phase", "VARCHAR DEFAULT 'none'")
    _sqlite_add_column_if_missing("certificaterecord", "challenge_id", "INTEGER")
    _sqlite_add_column_if_missing("certificaterecord", "last_verify_at", "DATETIME")
    _sqlite_add_column_if_missing("acmechallenge", "provider", "VARCHAR DEFAULT 'manual'")
    _sqlite_add_column_if_missing("acmechallenge", "zone_id", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("acmechallenge", "record_name", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("acmechallenge", "verified_at", "DATETIME")
    _sqlite_add_column_if_missing("acmechallenge", "expires_at", "DATETIME")
    _sqlite_add_column_if_missing("acmechallenge", "version", "INTEGER DEFAULT 1")
    _sqlite_add_column_if_missing("acmechallenge", "state", "VARCHAR DEFAULT 'pending'")
    _sqlite_add_column_if_missing("acmechallenge", "cleanup_state", "VARCHAR DEFAULT 'pending'")
    _sqlite_add_column_if_missing("systemsetting", "effective_admin_path", "VARCHAR DEFAULT 'yun123'")
    _sqlite_add_column_if_missing("systemsetting", "pending_admin_path", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("systemsetting", "path_switch_deadline", "DATETIME")
    _sqlite_add_column_if_missing("dnsautoconfig", "debounce_sec", "INTEGER DEFAULT 10")


def init_db() -> None:
    SQLModel.metadata.create_all(engine)
    migrate_schema()
    with Session(engine) as session:
        admin = session.exec(select(Admin).where(Admin.username == settings.admin_user)).first()
        if not admin:
            session.add(Admin(username=settings.admin_user, password_hash=hash_password(settings.admin_password)))

        sys_cfg = session.exec(select(SystemSetting).where(SystemSetting.id == 1)).first()
        if not sys_cfg:
            session.add(
                SystemSetting(
                    id=1,
                    controller_port=settings.port,
                    admin_path=settings.admin_path,
                    effective_admin_path=settings.admin_path,
                    pending_admin_path="",
                    path_switch_deadline=None,
                    default_admin_user=settings.admin_user,
                    default_admin_password=settings.admin_password,
                )
            )
        else:
            updated = False
            if not sys_cfg.effective_admin_path:
                sys_cfg.effective_admin_path = sys_cfg.admin_path or settings.admin_path
                updated = True
            if sys_cfg.pending_admin_path is None:
                sys_cfg.pending_admin_path = ""
                updated = True
            if updated:
                session.add(sys_cfg)
        session.commit()


def get_session():
    with Session(engine) as session:
        yield session
