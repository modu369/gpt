from pydantic import BaseModel
import os


class Settings(BaseModel):
    host: str = os.getenv("CONTROLLER_HOST", "0.0.0.0")
    port: int = int(os.getenv("CONTROLLER_PORT", "8080"))
    admin_path: str = os.getenv("ADMIN_PATH", "yun123")
    jwt_secret: str = os.getenv("JWT_SECRET", "change-me")
    db_url: str = os.getenv("DB_URL", "sqlite:///./controller.db")
    admin_user: str = os.getenv("ADMIN_USER", "admin")
    admin_password: str = os.getenv("ADMIN_PASSWORD", "admin123")

    # Huawei Cloud DNS (intl) optional
    hw_access_key: str = os.getenv("HUAWEI_AK", "")
    hw_secret_key: str = os.getenv("HUAWEI_SK", "")
    hw_region: str = os.getenv("HUAWEI_REGION", "ap-southeast-1")
    hw_zone_id: str = os.getenv("HUAWEI_DNS_ZONE_ID", "")
    hw_recordset_name: str = os.getenv("HUAWEI_DNS_RECORDSET", "")
    cert_dns_v2: bool = os.getenv("CERT_DNS_V2", "1") == "1"
    dns_auto_reconcile: bool = os.getenv("DNS_AUTO_RECONCILE", "1") == "1"


settings = Settings()
