from datetime import datetime, timedelta
from jose import jwt
from passlib.context import CryptContext
from .config import settings


pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")


def hash_password(password: str) -> str:
    return pwd_context.hash(password)


def verify_password(password: str, password_hash: str) -> bool:
    return pwd_context.verify(password, password_hash)


def create_access_token(subject: str, minutes: int = 1440) -> str:
    expire = datetime.utcnow() + timedelta(minutes=minutes)
    return jwt.encode({"sub": subject, "exp": expire}, settings.jwt_secret, algorithm="HS256")


def decode_token(token: str):
    return jwt.decode(token, settings.jwt_secret, algorithms=["HS256"])
