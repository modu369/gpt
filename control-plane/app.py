from __future__ import annotations

import os
import secrets
import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Iterable

from flask import (
    Flask,
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
SCRIPT_BASE_URL = os.getenv(
    "SCRIPT_BASE_URL",
    "https://raw.githubusercontent.com/modu369/gpt/codex/develop-high-performance-cloudflare-proxy-system/scripts",
)
REPO_URL = os.getenv(
    "REPO_URL",
    "https://github.com/modu369/gpt.git",
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


def is_logged_in() -> bool:
    return bool(session.get("user"))


@app.before_request
def ensure_db() -> None:
    init_db()


@app.route("/login", methods=["GET", "POST"])
def login():
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")
        user = get_db().execute("SELECT * FROM users WHERE username = ?", (username,)).fetchone()
        if user and check_password_hash(user["password_hash"], password):
            session["user"] = username
            return redirect(url_for("dashboard"))
        return render_template("login.html", error="账号或密码错误")
    return render_template("login.html")


@app.route("/logout")
def logout():
    session.clear()
    return redirect(url_for("login"))


@app.route("/")
def dashboard():
    if not is_logged_in():
        return redirect(url_for("login"))
    db = get_db()
    domains = db.execute("SELECT * FROM domains ORDER BY id DESC").fetchall()
    cf_ips = db.execute("SELECT * FROM cf_ips ORDER BY id DESC").fetchall()
    agents = db.execute("SELECT * FROM agents ORDER BY id DESC").fetchall()
    control_url = os.getenv("CONTROL_PUBLIC_URL", request.host_url.rstrip("/"))
    return render_template(
        "dashboard.html",
        domains=domains,
        cf_ips=cf_ips,
        agents=agents,
        control_url=control_url,
        script_base_url=SCRIPT_BASE_URL,
        repo_url=REPO_URL,
    )


@app.route("/domains", methods=["POST"])
def add_domain():
    if not is_logged_in():
        return redirect(url_for("login"))
    domain = request.form.get("domain", "").strip()
    if domain:
        db = get_db()
        db.execute(
            "INSERT OR IGNORE INTO domains (domain, created_at) VALUES (?, ?)",
            (domain, datetime.utcnow().isoformat()),
        )
        db.commit()
    return redirect(url_for("dashboard"))


@app.route("/domains/<int:domain_id>/delete", methods=["POST"])
def delete_domain(domain_id: int):
    if not is_logged_in():
        return redirect(url_for("login"))
    db = get_db()
    db.execute("DELETE FROM domains WHERE id = ?", (domain_id,))
    db.commit()
    return redirect(url_for("dashboard"))


@app.route("/cf_ips", methods=["POST"])
def add_cf_ip():
    if not is_logged_in():
        return redirect(url_for("login"))
    ip = request.form.get("ip", "").strip()
    weight = int(request.form.get("weight", "100") or 100)
    if ip:
        db = get_db()
        db.execute(
            "INSERT OR IGNORE INTO cf_ips (ip, weight, created_at) VALUES (?, ?, ?)",
            (ip, weight, datetime.utcnow().isoformat()),
        )
        db.commit()
    return redirect(url_for("dashboard"))


@app.route("/cf_ips/<int:ip_id>/delete", methods=["POST"])
def delete_cf_ip(ip_id: int):
    if not is_logged_in():
        return redirect(url_for("login"))
    db = get_db()
    db.execute("DELETE FROM cf_ips WHERE id = ?", (ip_id,))
    db.commit()
    return redirect(url_for("dashboard"))


@app.route("/agents", methods=["POST"])
def create_agent():
    if not is_logged_in():
        return redirect(url_for("login"))
    name = request.form.get("name", "").strip() or "relay"
    token = secrets.token_urlsafe(24)
    db = get_db()
    db.execute(
        "INSERT INTO agents (name, token, created_at) VALUES (?, ?, ?)",
        (name, token, datetime.utcnow().isoformat()),
    )
    db.commit()
    return redirect(url_for("dashboard"))


@app.route("/agents/<int:agent_id>/delete", methods=["POST"])
def delete_agent(agent_id: int):
    if not is_logged_in():
        return redirect(url_for("login"))
    db = get_db()
    db.execute("DELETE FROM agents WHERE id = ?", (agent_id,))
    db.commit()
    return redirect(url_for("dashboard"))


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
        \"\"\"\n        UPDATE agents\n        SET loadavg = ?, mem_used_mb = ?, rx_bytes = ?, tx_bytes = ?, last_seen = ?\n        WHERE id = ?\n        \"\"\",\n        (\n            payload.get(\"loadavg\"),\n            payload.get(\"mem_used_mb\"),\n            payload.get(\"rx_bytes\"),\n            payload.get(\"tx_bytes\"),\n            datetime.utcnow().isoformat(),\n            agent[\"id\"],\n        ),\n    )
    db.commit()
    return jsonify({\"status\": \"ok\"})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.getenv("CONTROL_PORT", "8080")))
