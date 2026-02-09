from __future__ import annotations

import os
import secrets
import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Iterable
from urllib.parse import urlparse, urlunparse

from flask import (
    Flask,
    abort,
    g,
    jsonify,
    redirect,
    render_template,
    request,
    session,
    url_for,
)
from werkzeug.security import check_password_hash, generate_password_hash

BASE_DIR = Path(__file__).resolve().parent
DB_PATH = BASE_DIR / "control.db"

DEFAULT_ADMIN_USER = os.getenv("CONTROL_ADMIN_USER", "admin")
DEFAULT_ADMIN_PASS = os.getenv("CONTROL_ADMIN_PASS", "admin123")
SECRET_KEY = os.getenv("CONTROL_SECRET_KEY", secrets.token_hex(16))
DEFAULT_ACCESS_PATH = os.getenv("CONTROL_ACCESS_PATH", "yun123")
SCRIPT_BASE_URL = os.getenv(
    "SCRIPT_BASE_URL",
    "https://raw.githubusercontent.com/modu369/gpt/codex/develop-high-performance-cloudflare-proxy-system-6h0ek0/scripts",
)
REPO_URL = os.getenv(
    "REPO_URL",
    "https://github.com/modu369/gpt.git",
)
REPO_REF = os.getenv(
    "REPO_REF",
    "codex/develop-high-performance-cloudflare-proxy-system-6h0ek0",
)

app = Flask(__name__)
app.secret_key = SECRET_KEY


def get_db() -> sqlite3.Connection:
    if "db" not in g:
        conn = sqlite3.connect(DB_PATH)
        conn.row_factory = sqlite3.Row
        g.db = conn
    return g.db


@app.teardown_appcontext
def close_db(exception: Exception | None) -> None:
    db = g.pop("db", None)
    if db is not None:
        db.close()


def init_db() -> None:
    db = get_db()
    db.executescript(
        """
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT UNIQUE NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS cf_ips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT UNIQUE NOT NULL,
            weight INTEGER NOT NULL DEFAULT 100,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS agents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            token TEXT UNIQUE NOT NULL,
            last_seen TEXT,
            loadavg TEXT,
            mem_used_mb REAL,
            rx_bytes INTEGER,
            tx_bytes INTEGER,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        """
    )
    db.commit()

    columns = {row[1] for row in db.execute("PRAGMA table_info(agents)").fetchall()}
    new_columns = {
        "loadavg": "TEXT",
        "mem_used_mb": "REAL",
        "rx_bytes": "INTEGER",
        "tx_bytes": "INTEGER",
    }
    for column, col_type in new_columns.items():
        if column not in columns:
            db.execute(f"ALTER TABLE agents ADD COLUMN {column} {col_type}")
    db.commit()

    user = db.execute("SELECT * FROM users WHERE username = ?", (DEFAULT_ADMIN_USER,)).fetchone()
    if user is None:
        db.execute(
            "INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)",
            (DEFAULT_ADMIN_USER, generate_password_hash(DEFAULT_ADMIN_PASS), datetime.utcnow().isoformat()),
        )
        db.commit()
    if get_setting("control_port") is None:
        set_setting("control_port", os.getenv("CONTROL_PORT", "8080"))
    if get_setting("access_path") is None:
        set_setting("access_path", DEFAULT_ACCESS_PATH)


def get_setting(key: str, default: str | None = None) -> str | None:
    row = get_db().execute("SELECT value FROM settings WHERE key = ?", (key,)).fetchone()
    if row:
        return row["value"]
    return default


def set_setting(key: str, value: str) -> None:
    db = get_db()
    db.execute(
        "INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        (key, value),
    )
    db.commit()


def normalize_access_path(value: str) -> str:
    cleaned = value.strip().strip("/")
    return cleaned


def get_access_config() -> tuple[str, str]:
    access_path = get_setting("access_path", DEFAULT_ACCESS_PATH) or ""
    access_path = normalize_access_path(access_path)
    access_port = get_setting("control_port", os.getenv("CONTROL_PORT", "8080")) or ""
    return access_path, access_port


def request_port() -> str:
    host = request.host
    port = None
    if host.startswith("["):
        if "]:" in host:
            port = host.split("]:", 1)[1]
    elif ":" in host:
        port = host.rsplit(":", 1)[1]
    if port:
        return port
    if request.scheme == "https":
        return "443"
    return "80"


def require_access(access_key: str | None) -> None:
    access_path, access_port = get_access_config()
    if access_port and request_port() != str(access_port):
        abort(404)
    if access_path and access_key != access_path:
        abort(404)
    if not access_path and access_key is not None:
        abort(404)


def scoped_url(endpoint: str, **values: str) -> str:
    access_path, _ = get_access_config()
    if access_path:
        values.setdefault("access_key", access_path)
    return url_for(endpoint, **values)


def build_access_url(base_url: str, access_port: str, access_path: str) -> str:
    parsed = urlparse(base_url)
    hostname = parsed.hostname or base_url
    scheme = parsed.scheme or "http"
    netloc = parsed.netloc or hostname
    if access_port:
        netloc = f"{hostname}:{access_port}"
    path = f"/{access_path}" if access_path else ""
    return urlunparse((scheme, netloc, path, "", "", ""))


def is_logged_in() -> bool:
    return bool(session.get("user"))


@app.before_request
def ensure_db() -> None:
    init_db()


@app.context_processor
def inject_scoped_helpers():
    access_path, access_port = get_access_config()
    control_public_url = os.getenv("CONTROL_PUBLIC_URL", request.host_url.rstrip("/"))
    return {
        "scoped_url": scoped_url,
        "access_path": access_path,
        "access_port": access_port,
        "access_url": build_access_url(control_public_url, access_port, access_path),
    }


@app.route("/login", methods=["GET", "POST"])
@app.route("/<access_key>/login", methods=["GET", "POST"])
def login(access_key: str | None = None):
    require_access(access_key)
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")
        user = get_db().execute("SELECT * FROM users WHERE username = ?", (username,)).fetchone()
        if user and check_password_hash(user["password_hash"], password):
            session["user"] = username
            return redirect(scoped_url("dashboard"))
        return render_template("login.html", error="账号或密码错误")
    return render_template("login.html")


@app.route("/logout")
@app.route("/<access_key>/logout")
def logout(access_key: str | None = None):
    require_access(access_key)
    session.clear()
    return redirect(scoped_url("login"))


@app.route("/")
@app.route("/<access_key>/")
def dashboard(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    db = get_db()
    domains = db.execute("SELECT * FROM domains ORDER BY id DESC").fetchall()
    cf_ips = db.execute("SELECT * FROM cf_ips ORDER BY id DESC").fetchall()
    agents = db.execute("SELECT * FROM agents ORDER BY id DESC").fetchall()
    control_url = os.getenv("CONTROL_PUBLIC_URL", request.host_url.rstrip("/"))
    access_path, access_port = get_access_config()
    return render_template(
        "dashboard.html",
        domains=domains,
        cf_ips=cf_ips,
        agents=agents,
        control_url=control_url,
        script_base_url=SCRIPT_BASE_URL,
        repo_url=REPO_URL,
        repo_ref=REPO_REF,
        access_path=access_path,
        access_port=access_port,
    )


@app.route("/domains", methods=["POST"])
@app.route("/<access_key>/domains", methods=["POST"])
def add_domain(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    domain = request.form.get("domain", "").strip()
    if domain:
        db = get_db()
        db.execute(
            "INSERT OR IGNORE INTO domains (domain, created_at) VALUES (?, ?)",
            (domain, datetime.utcnow().isoformat()),
        )
        db.commit()
    return redirect(scoped_url("dashboard"))


@app.route("/domains/<int:domain_id>/delete", methods=["POST"])
@app.route("/<access_key>/domains/<int:domain_id>/delete", methods=["POST"])
def delete_domain(domain_id: int, access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    db = get_db()
    db.execute("DELETE FROM domains WHERE id = ?", (domain_id,))
    db.commit()
    return redirect(scoped_url("dashboard"))


@app.route("/cf_ips", methods=["POST"])
@app.route("/<access_key>/cf_ips", methods=["POST"])
def add_cf_ip(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    ip = request.form.get("ip", "").strip()
    weight = int(request.form.get("weight", "100") or 100)
    if ip:
        db = get_db()
        db.execute(
            "INSERT OR IGNORE INTO cf_ips (ip, weight, created_at) VALUES (?, ?, ?)",
            (ip, weight, datetime.utcnow().isoformat()),
        )
        db.commit()
    return redirect(scoped_url("dashboard"))


@app.route("/cf_ips/<int:ip_id>/delete", methods=["POST"])
@app.route("/<access_key>/cf_ips/<int:ip_id>/delete", methods=["POST"])
def delete_cf_ip(ip_id: int, access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    db = get_db()
    db.execute("DELETE FROM cf_ips WHERE id = ?", (ip_id,))
    db.commit()
    return redirect(scoped_url("dashboard"))


@app.route("/agents", methods=["POST"])
@app.route("/<access_key>/agents", methods=["POST"])
def create_agent(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    name = request.form.get("name", "").strip() or "relay"
    token = secrets.token_urlsafe(24)
    db = get_db()
    db.execute(
        "INSERT INTO agents (name, token, created_at) VALUES (?, ?, ?)",
        (name, token, datetime.utcnow().isoformat()),
    )
    db.commit()
    return redirect(scoped_url("dashboard"))


@app.route("/agents/<int:agent_id>/delete", methods=["POST"])
@app.route("/<access_key>/agents/<int:agent_id>/delete", methods=["POST"])
def delete_agent(agent_id: int, access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    db = get_db()
    db.execute("DELETE FROM agents WHERE id = ?", (agent_id,))
    db.commit()
    return redirect(scoped_url("dashboard"))


@app.route("/settings/access", methods=["POST"])
@app.route("/<access_key>/settings/access", methods=["POST"])
def update_access_settings(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    port = request.form.get("control_port", "").strip()
    path = normalize_access_path(request.form.get("access_path", ""))
    if port:
        set_setting("control_port", port)
    if path:
        set_setting("access_path", path)
    else:
        set_setting("access_path", "")
    return redirect(scoped_url("dashboard"))


def fetch_domains() -> Iterable[str]:
    rows = get_db().execute("SELECT domain FROM domains").fetchall()
    return [row["domain"] for row in rows]


def fetch_cf_ips() -> Iterable[dict]:
    rows = get_db().execute("SELECT ip, weight FROM cf_ips").fetchall()
    return [{"ip": row["ip"], "weight": row["weight"]} for row in rows]


@app.route("/api/config", methods=["GET"])
def api_config():
    token = request.headers.get("X-Agent-Token", "").strip()
    agent = get_db().execute("SELECT * FROM agents WHERE token = ?", (token,)).fetchone()
    if not agent:
        return jsonify({"error": "unauthorized"}), 401
    db = get_db()
    db.execute(
        "UPDATE agents SET last_seen = ? WHERE id = ?",
        (datetime.utcnow().isoformat(), agent["id"]),
    )
    db.commit()
    return jsonify(
        {
            "domains": list(fetch_domains()),
            "cf_ips": list(fetch_cf_ips()),
        }
    )


@app.route("/api/metrics", methods=["POST"])
def api_metrics():
    token = request.headers.get("X-Agent-Token", "").strip()
    agent = get_db().execute("SELECT * FROM agents WHERE token = ?", (token,)).fetchone()
    if not agent:
        return jsonify({"error": "unauthorized"}), 401
    payload = request.get_json(silent=True) or {}
    db = get_db()
    db.execute(
        """
        UPDATE agents
        SET loadavg = ?, mem_used_mb = ?, rx_bytes = ?, tx_bytes = ?, last_seen = ?
        WHERE id = ?
        """,
        (
            payload.get("loadavg"),
            payload.get("mem_used_mb"),
            payload.get("rx_bytes"),
            payload.get("tx_bytes"),
            datetime.utcnow().isoformat(),
            agent["id"],
        ),
    )
    db.commit()
    return jsonify({"status": "ok"})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.getenv("CONTROL_PORT", "8080")))
