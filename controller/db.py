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
    _sqlite_add_column_if_missing("cfip", "last_checked_at", "DATETIME")
    _sqlite_add_column_if_missing("certificaterecord", "fail_reason", "VARCHAR DEFAULT ''")
    _sqlite_add_column_if_missing("certificaterecord", "retries", "INTEGER DEFAULT 0")
    _sqlite_add_column_if_missing("certificaterecord", "last_synced_node", "VARCHAR DEFAULT ''")


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
                    default_admin_user=settings.admin_user,
                    default_admin_password=settings.admin_password,
                )
            )
        session.commit()


def get_session():
    with Session(engine) as session:
        yield session
