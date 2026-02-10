from pydantic import BaseModel
import os


class Settings(BaseModel):
    host: str = os.getenv("CONTROLLER_HOST", "0.0.0.0")
    port: int = int(os.getenv("CONTROLLER_PORT", "8080"))
    admin_path: str = os.getenv("ADMIN_PATH", "panel")
    jwt_secret: str = os.getenv("JWT_SECRET", "change-me")
    db_url: str = os.getenv("DB_URL", "sqlite:///./controller.db")
    admin_user: str = os.getenv("ADMIN_USER", "admin")
    admin_password: str = os.getenv("ADMIN_PASSWORD", "admin123")


settings = Settings()
