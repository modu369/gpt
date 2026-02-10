from sqlmodel import Session, SQLModel, create_engine, select
from .config import settings
from .models import Admin, SystemSetting
from .security import hash_password


engine = create_engine(settings.db_url, connect_args={"check_same_thread": False})


def init_db() -> None:
    SQLModel.metadata.create_all(engine)
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
