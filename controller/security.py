from datetime import datetime, timedelta
from jose import jwt
from passlib.context import CryptContext
from .config import settings


pwd_context = CryptContext(schemes=["pbkdf2_sha256"], deprecated="auto")


def hash_password(password: str) -> str:
    return pwd_context.hash(password)


def verify_password(password: str, password_hash: str) -> bool:
    try:
        return pwd_context.verify(password, password_hash)
    except Exception:
        return False


def create_access_token(subject: str, minutes: int = 1440) -> str:
    expire = datetime.utcnow() + timedelta(minutes=minutes)
    return jwt.encode({"sub": subject, "exp": expire}, settings.jwt_secret, algorithm="HS256")


def decode_token(token: str):
    return jwt.decode(token, settings.jwt_secret, algorithms=["HS256"])
