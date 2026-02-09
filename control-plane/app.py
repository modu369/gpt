from __future__ import annotations

import os
import secrets
import sqlite3
from datetime import datetime
from pathlib import Path
from typing import Iterable
from urllib.parse import urlparse, urlunparse

import requests
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
            mem_total_mb REAL,
            cpu_percent REAL,
            agent_ip TEXT,
            rx_bytes INTEGER,
            tx_bytes INTEGER,
            rx_mbps REAL,
            tx_mbps REAL,
            bandwidth_mbps REAL,
            bandwidth_manual INTEGER NOT NULL DEFAULT 0,
            last_overload_at TEXT,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS overload_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            agent_id INTEGER NOT NULL,
            reason TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(agent_id) REFERENCES agents(id)
        );
        """
    )
    db.commit()

    columns = {row[1] for row in db.execute("PRAGMA table_info(agents)").fetchall()}
    new_columns = {
        "loadavg": "TEXT",
        "mem_used_mb": "REAL",
        "mem_total_mb": "REAL",
        "cpu_percent": "REAL",
        "agent_ip": "TEXT",
        "rx_bytes": "INTEGER",
        "tx_bytes": "INTEGER",
        "rx_mbps": "REAL",
        "tx_mbps": "REAL",
        "bandwidth_mbps": "REAL",
        "bandwidth_manual": "INTEGER",
        "last_overload_at": "TEXT",
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


def get_settings(keys: Iterable[str]) -> dict[str, str]:
    db = get_db()
    placeholders = ",".join("?" for _ in keys)
    rows = db.execute(
        f"SELECT key, value FROM settings WHERE key IN ({placeholders})",
        tuple(keys),
    ).fetchall()
    existing = {row["key"]: row["value"] for row in rows}
    return {key: existing.get(key, "") for key in keys}


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


def parse_float(value: object) -> float | None:
    try:
        if value is None:
            return None
        return float(value)
    except (TypeError, ValueError):
        return None


def get_agent_value(agent: sqlite3.Row, key: str) -> object | None:
    if key in agent.keys():
        return agent[key]
    return None


def compute_saturation(agent: sqlite3.Row) -> tuple[bool, list[str]]:
    reasons: list[str] = []
    cpu_percent = parse_float(get_agent_value(agent, "cpu_percent"))
    mem_used = parse_float(get_agent_value(agent, "mem_used_mb"))
    mem_total = parse_float(get_agent_value(agent, "mem_total_mb"))
    bandwidth_mbps = parse_float(get_agent_value(agent, "bandwidth_mbps"))
    rx_mbps = parse_float(get_agent_value(agent, "rx_mbps")) or 0.0
    tx_mbps = parse_float(get_agent_value(agent, "tx_mbps")) or 0.0
    if cpu_percent is not None and cpu_percent >= 90:
        reasons.append("CPU")
    if mem_used is not None and mem_total and mem_total > 0:
        if mem_used / mem_total >= 0.9:
            reasons.append("内存")
    if bandwidth_mbps and bandwidth_mbps > 0:
        if (rx_mbps + tx_mbps) / bandwidth_mbps >= 0.9:
            reasons.append("带宽")
    return bool(reasons), reasons


def compute_summary(agents: list[sqlite3.Row]) -> dict:
    total_mem_used = 0.0
    total_mem = 0.0
    total_bw_used = 0.0
    total_bw = 0.0
    cpu_values: list[float] = []
    for agent in agents:
        mem_used = parse_float(get_agent_value(agent, "mem_used_mb")) or 0.0
        mem_total = parse_float(get_agent_value(agent, "mem_total_mb")) or 0.0
        rx_mbps = parse_float(get_agent_value(agent, "rx_mbps")) or 0.0
        tx_mbps = parse_float(get_agent_value(agent, "tx_mbps")) or 0.0
        bw_mbps = parse_float(get_agent_value(agent, "bandwidth_mbps")) or 0.0
        cpu_percent = parse_float(get_agent_value(agent, "cpu_percent"))
        if cpu_percent is not None:
            cpu_values.append(cpu_percent)
        total_mem_used += mem_used
        total_mem += mem_total
        total_bw_used += rx_mbps + tx_mbps
        total_bw += bw_mbps
    avg_cpu = sum(cpu_values) / len(cpu_values) if cpu_values else 0.0
    mem_percent = (total_mem_used / total_mem * 100) if total_mem else 0.0
    bw_percent = (total_bw_used / total_bw * 100) if total_bw else 0.0
    return {
        "avg_cpu_percent": round(avg_cpu, 2),
        "mem_used_mb": round(total_mem_used, 2),
        "mem_total_mb": round(total_mem, 2),
        "mem_percent": round(mem_percent, 2),
        "bw_used_mbps": round(total_bw_used, 2),
        "bw_total_mbps": round(total_bw, 2),
        "bw_percent": round(bw_percent, 2),
    }


def compute_weight(agent: sqlite3.Row) -> int:
    cpu_percent = parse_float(get_agent_value(agent, "cpu_percent")) or 0.0
    mem_used = parse_float(get_agent_value(agent, "mem_used_mb")) or 0.0
    mem_total = parse_float(get_agent_value(agent, "mem_total_mb")) or 0.0
    bandwidth_mbps = parse_float(get_agent_value(agent, "bandwidth_mbps")) or 0.0
    rx_mbps = parse_float(get_agent_value(agent, "rx_mbps")) or 0.0
    tx_mbps = parse_float(get_agent_value(agent, "tx_mbps")) or 0.0
    mem_percent = (mem_used / mem_total * 100) if mem_total else 0.0
    bw_percent = ((rx_mbps + tx_mbps) / bandwidth_mbps * 100) if bandwidth_mbps else 0.0
    pressure = max(cpu_percent, mem_percent, bw_percent)
    weight = max(1, int(round(100 - pressure)))
    return weight


def should_sync_dns() -> bool:
    last_sync = get_setting("dns_last_sync", "")
    if not last_sync:
        return True
    try:
        last_dt = datetime.fromisoformat(last_sync)
    except ValueError:
        return True
    return (datetime.utcnow() - last_dt).total_seconds() > 10


def sync_huawei_dns_weights() -> None:
    settings = get_settings(
        [
            "dns_enabled",
            "dns_base_url",
            "dns_zone_id",
            "dns_record_name",
            "dns_record_type",
            "dns_auth_token",
        ]
    )
    if settings["dns_enabled"] != "true":
        return
    base_url = settings["dns_base_url"].rstrip("/")
    zone_id = settings["dns_zone_id"].strip()
    record_name = settings["dns_record_name"].strip()
    record_type = settings["dns_record_type"].strip() or "A"
    auth_token = settings["dns_auth_token"].strip()
    if not (base_url and zone_id and record_name and auth_token):
        return
    agents = get_db().execute("SELECT * FROM agents ORDER BY id DESC").fetchall()
    targets = []
    for agent in agents:
        ip = (agent["agent_ip"] or "").strip()
        if not ip:
            continue
        weight = compute_weight(agent)
        targets.append({"ip": ip, "weight": weight})
    if not targets:
        return
    headers = {"X-Auth-Token": auth_token, "Content-Type": "application/json"}
    list_url = f"{base_url}/v2/zones/{zone_id}/recordsets"
    response = requests.get(
        list_url,
        headers=headers,
        params={"name": record_name, "type": record_type},
        timeout=10,
    )
    response.raise_for_status()
    existing = response.json().get("recordsets", [])
    existing_map = {}
    for recordset in existing:
        records = recordset.get("records") or []
        if records:
            existing_map[records[0]] = recordset
    for target in targets:
        payload = {
            "name": record_name,
            "type": record_type,
            "records": [target["ip"]],
            "weight": target["weight"],
        }
        record = target["ip"]
        if record in existing_map:
            record_id = existing_map[record]["id"]
            update_url = f"{base_url}/v2/recordsets/{record_id}"
            requests.put(update_url, headers=headers, json=payload, timeout=10).raise_for_status()
        else:
            create_url = f"{base_url}/v2/zones/{zone_id}/recordsets"
            requests.post(create_url, headers=headers, json=payload, timeout=10).raise_for_status()
    set_setting("dns_last_sync", datetime.utcnow().isoformat())


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
    agents = db.execute("SELECT * FROM agents ORDER BY id DESC").fetchall()
    overload_total = db.execute("SELECT COUNT(*) FROM overload_events").fetchone()[0]
    saturation_map = {}
    for agent in agents:
        saturated, reasons = compute_saturation(agent)
        saturation_map[agent["id"]] = {"saturated": saturated, "reasons": reasons}
    control_url = os.getenv("CONTROL_PUBLIC_URL", request.host_url.rstrip("/"))
    access_path, access_port = get_access_config()
    summary = compute_summary(agents)
    return render_template(
        "dashboard.html",
        agents=agents,
        control_url=control_url,
        script_base_url=SCRIPT_BASE_URL,
        repo_url=REPO_URL,
        repo_ref=REPO_REF,
        access_path=access_path,
        access_port=access_port,
        summary=summary,
        overload_total=overload_total,
        saturation_map=saturation_map,
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
    return redirect(scoped_url("settings"))


@app.route("/domains/<int:domain_id>/delete", methods=["POST"])
@app.route("/<access_key>/domains/<int:domain_id>/delete", methods=["POST"])
def delete_domain(domain_id: int, access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    db = get_db()
    db.execute("DELETE FROM domains WHERE id = ?", (domain_id,))
    db.commit()
    return redirect(scoped_url("settings"))


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
    return redirect(scoped_url("settings"))


@app.route("/cf_ips/<int:ip_id>/delete", methods=["POST"])
@app.route("/<access_key>/cf_ips/<int:ip_id>/delete", methods=["POST"])
def delete_cf_ip(ip_id: int, access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    db = get_db()
    db.execute("DELETE FROM cf_ips WHERE id = ?", (ip_id,))
    db.commit()
    return redirect(scoped_url("settings"))


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


@app.route("/settings", methods=["GET"])
@app.route("/<access_key>/settings", methods=["GET"])
def settings(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    db = get_db()
    domains = db.execute("SELECT * FROM domains ORDER BY id DESC").fetchall()
    cf_ips = db.execute("SELECT * FROM cf_ips ORDER BY id DESC").fetchall()
    agents = db.execute("SELECT * FROM agents ORDER BY id DESC").fetchall()
    access_path, access_port = get_access_config()
    dns_settings = get_settings(
        [
            "dns_enabled",
            "dns_base_url",
            "dns_zone_id",
            "dns_record_name",
            "dns_record_type",
            "dns_auth_token",
        ]
    )
    return render_template(
        "settings.html",
        domains=domains,
        cf_ips=cf_ips,
        agents=agents,
        access_path=access_path,
        access_port=access_port,
        dns_settings=dns_settings,
    )


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
    return redirect(scoped_url("settings"))


@app.route("/settings/dns", methods=["POST"])
@app.route("/<access_key>/settings/dns", methods=["POST"])
def update_dns_settings(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    enabled = "true" if request.form.get("dns_enabled") == "on" else "false"
    set_setting("dns_enabled", enabled)
    set_setting("dns_base_url", request.form.get("dns_base_url", "").strip())
    set_setting("dns_zone_id", request.form.get("dns_zone_id", "").strip())
    set_setting("dns_record_name", request.form.get("dns_record_name", "").strip())
    set_setting("dns_record_type", request.form.get("dns_record_type", "").strip() or "A")
    set_setting("dns_auth_token", request.form.get("dns_auth_token", "").strip())
    return redirect(scoped_url("settings"))


@app.route("/settings/dns/sync", methods=["POST"])
@app.route("/<access_key>/settings/dns/sync", methods=["POST"])
def manual_dns_sync(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    try:
        sync_huawei_dns_weights()
    except requests.RequestException:
        pass
    return redirect(scoped_url("settings"))


@app.route("/agents/<int:agent_id>/bandwidth", methods=["POST"])
@app.route("/<access_key>/agents/<int:agent_id>/bandwidth", methods=["POST"])
def update_agent_bandwidth(agent_id: int, access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    bandwidth = request.form.get("bandwidth_mbps", "").strip()
    agent_ip = request.form.get("agent_ip", "").strip()
    db = get_db()
    db.execute(
        "UPDATE agents SET bandwidth_mbps = ?, bandwidth_manual = 1, agent_ip = ? WHERE id = ?",
        (bandwidth or None, agent_ip or None, agent_id),
    )
    db.commit()
    return redirect(scoped_url("settings"))


@app.route("/overloads", methods=["GET"])
@app.route("/<access_key>/overloads", methods=["GET"])
def overloads(access_key: str | None = None):
    require_access(access_key)
    if not is_logged_in():
        return redirect(scoped_url("login"))
    rows = get_db().execute(
        """
        SELECT agents.name AS agent_name,
               overload_events.reason AS reason,
               MAX(overload_events.created_at) AS last_seen,
               COUNT(*) AS count
        FROM overload_events
        JOIN agents ON agents.id = overload_events.agent_id
        GROUP BY agents.id, overload_events.reason
        ORDER BY last_seen DESC
        """
    ).fetchall()
    return render_template("overloads.html", events=rows)


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
    mem_used = payload.get("mem_used_mb")
    mem_total = payload.get("mem_total_mb")
    cpu_percent = payload.get("cpu_percent")
    rx_mbps = payload.get("rx_mbps")
    tx_mbps = payload.get("tx_mbps")
    bandwidth_mbps = payload.get("bandwidth_mbps")
    public_ip = (payload.get("public_ip") or "").strip()
    update_fields = [
        payload.get("loadavg"),
        mem_used,
        mem_total,
        cpu_percent,
        payload.get("rx_bytes"),
        payload.get("tx_bytes"),
        rx_mbps,
        tx_mbps,
        datetime.utcnow().isoformat(),
        agent["id"],
    ]
    db.execute(
        """
        UPDATE agents
        SET loadavg = ?, mem_used_mb = ?, mem_total_mb = ?, cpu_percent = ?,
            rx_bytes = ?, tx_bytes = ?, rx_mbps = ?, tx_mbps = ?, last_seen = ?
        WHERE id = ?
        """,
        update_fields,
    )
    if bandwidth_mbps is not None and not agent["bandwidth_manual"]:
        db.execute(
            "UPDATE agents SET bandwidth_mbps = ? WHERE id = ?",
            (bandwidth_mbps, agent["id"]),
        )
    if public_ip and not agent["agent_ip"]:
        db.execute(
            "UPDATE agents SET agent_ip = ? WHERE id = ?",
            (public_ip, agent["id"]),
        )
    db.commit()
    agent = db.execute("SELECT * FROM agents WHERE id = ?", (agent["id"],)).fetchone()
    saturated, reasons = compute_saturation(agent)
    if saturated:
        last_overload = agent["last_overload_at"]
        should_log = True
        if last_overload:
            try:
                last_dt = datetime.fromisoformat(last_overload)
                should_log = (datetime.utcnow() - last_dt).total_seconds() > 60
            except ValueError:
                should_log = True
        if should_log:
            for reason in reasons:
                db.execute(
                    "INSERT INTO overload_events (agent_id, reason, created_at) VALUES (?, ?, ?)",
                    (agent["id"], reason, datetime.utcnow().isoformat()),
                )
            db.execute(
                "UPDATE agents SET last_overload_at = ? WHERE id = ?",
                (datetime.utcnow().isoformat(), agent["id"]),
            )
            db.commit()
    if should_sync_dns():
        try:
            sync_huawei_dns_weights()
        except requests.RequestException:
            pass
    return jsonify({"status": "ok"})


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.getenv("CONTROL_PORT", "8080")))
