from __future__ import annotations

import argparse
import os
import socket
import re
import shutil
import subprocess
import time
from pathlib import Path
from typing import Iterable

import requests

CONTROL_URL = os.getenv("CONTROL_URL", "http://127.0.0.1:8080")
AGENT_TOKEN = os.getenv("AGENT_TOKEN", "")
SYNC_INTERVAL = int(os.getenv("SYNC_INTERVAL", "5"))
RUNTIME_SOCKET = os.getenv("RUNTIME_SOCKET", "/run/haproxy/admin.sock")
DOMAINS_MAP = Path(os.getenv("DOMAINS_MAP", "/etc/haproxy/maps/domains.map"))
MAX_SERVERS = int(os.getenv("MAX_SERVERS", "20"))
NET_IFACE = os.getenv("NET_IFACE", "eth0")
MAX_BANDWIDTH_MBPS = os.getenv("MAX_BANDWIDTH_MBPS", "")

_LAST_NET: tuple[int, int, float] | None = None
_LAST_CPU: tuple[int, int] | None = None


def fetch_config() -> dict:
    response = requests.get(
        f"{CONTROL_URL}/api/config",
        headers={"X-Agent-Token": AGENT_TOKEN},
        timeout=5,
    )
    response.raise_for_status()
    return response.json()


def read_mem_used_mb() -> float:
    mem_total = 0
    mem_available = 0
    with open("/proc/meminfo", "r", encoding="utf-8") as handle:
        for line in handle:
            if line.startswith("MemTotal"):
                mem_total = int(line.split()[1])
            elif line.startswith("MemAvailable"):
                mem_available = int(line.split()[1])
    if mem_total == 0:
        return 0.0
    used_kb = mem_total - mem_available
    return round(used_kb / 1024, 2)


def read_mem_total_mb() -> float:
    mem_total = 0
    with open("/proc/meminfo", "r", encoding="utf-8") as handle:
        for line in handle:
            if line.startswith("MemTotal"):
                mem_total = int(line.split()[1])
                break
    return round(mem_total / 1024, 2)


def read_loadavg() -> str:
    with open("/proc/loadavg", "r", encoding="utf-8") as handle:
        return handle.read().strip().split(" ")[0]


def read_net_bytes(interface: str) -> tuple[int, int]:
    with open("/proc/net/dev", "r", encoding="utf-8") as handle:
        for line in handle:
            if line.strip().startswith(f"{interface}:"):
                data = line.split(":")[1].split()
                rx_bytes = int(data[0])
                tx_bytes = int(data[8])
                return rx_bytes, tx_bytes
    return 0, 0


def read_cpu_percent() -> float:
    global _LAST_CPU
    with open("/proc/stat", "r", encoding="utf-8") as handle:
        parts = handle.readline().split()
    if len(parts) < 5:
        return 0.0
    total = sum(int(x) for x in parts[1:])
    idle = int(parts[4])
    if _LAST_CPU is None:
        _LAST_CPU = (total, idle)
        return 0.0
    last_total, last_idle = _LAST_CPU
    total_delta = total - last_total
    idle_delta = idle - last_idle
    _LAST_CPU = (total, idle)
    if total_delta <= 0:
        return 0.0
    usage = (total_delta - idle_delta) / total_delta * 100
    return round(usage, 2)


def read_net_rate(interface: str, rx_bytes: int, tx_bytes: int) -> tuple[float, float]:
    global _LAST_NET
    now = time.time()
    if _LAST_NET is None:
        _LAST_NET = (rx_bytes, tx_bytes, now)
        return 0.0, 0.0
    last_rx, last_tx, last_time = _LAST_NET
    elapsed = max(now - last_time, 1)
    _LAST_NET = (rx_bytes, tx_bytes, now)
    rx_rate = (rx_bytes - last_rx) * 8 / elapsed / 1_000_000
    tx_rate = (tx_bytes - last_tx) * 8 / elapsed / 1_000_000
    return round(rx_rate, 2), round(tx_rate, 2)


def post_metrics() -> None:
    rx_bytes, tx_bytes = read_net_bytes(NET_IFACE)
    rx_mbps, tx_mbps = read_net_rate(NET_IFACE, rx_bytes, tx_bytes)
    public_ip = None
    try:
        response = requests.get("https://api.ipify.org", timeout=3)
        if response.ok:
            public_ip = response.text.strip()
    except requests.RequestException:
        public_ip = None
    payload = {
        "loadavg": read_loadavg(),
        "mem_used_mb": read_mem_used_mb(),
        "mem_total_mb": read_mem_total_mb(),
        "cpu_percent": read_cpu_percent(),
        "rx_bytes": rx_bytes,
        "tx_bytes": tx_bytes,
        "rx_mbps": rx_mbps,
        "tx_mbps": tx_mbps,
        "bandwidth_mbps": float(MAX_BANDWIDTH_MBPS) if MAX_BANDWIDTH_MBPS else None,
        "public_ip": public_ip,
    }
    requests.post(
        f"{CONTROL_URL}/api/metrics",
        headers={"X-Agent-Token": AGENT_TOKEN},
        json=payload,
        timeout=5,
    )


def write_domains(domains: Iterable[str]) -> None:
    DOMAINS_MAP.parent.mkdir(parents=True, exist_ok=True)
    patterns = normalize_domains(domains)
    DOMAINS_MAP.write_text("\n".join(patterns) + ("\n" if patterns else ""))


def normalize_domains(domains: Iterable[str]) -> list[str]:
    unique = sorted(set(domain.strip() for domain in domains if domain.strip()))
    if not unique:
        return [".*"]
    return [f"^{re.escape(domain)}$" for domain in unique]


def update_acl(domains: Iterable[str]) -> None:
    patterns = normalize_domains(domains)
    send_runtime("clear acl allowed_host")
    send_runtime("clear acl allowed_sni")
    for pattern in patterns:
        send_runtime(f"add acl allowed_host {pattern}")
        send_runtime(f"add acl allowed_sni {pattern}")


def send_runtime(cmd: str) -> str:
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as sock:
        sock.connect(RUNTIME_SOCKET)
        sock.sendall(cmd.encode("utf-8") + b"\n")
        sock.shutdown(socket.SHUT_WR)
        data = sock.recv(65535)
    return data.decode("utf-8", errors="ignore")


def ensure_runtime_socket() -> None:
    socket_path = Path(RUNTIME_SOCKET)
    if socket_path.exists() and not socket_path.is_socket():
        print(f"Runtime socket path is not a socket: {socket_path}")
        if socket_path.is_dir():
            shutil.rmtree(socket_path)
        else:
            socket_path.unlink()
        subprocess.run(["systemctl", "restart", "haproxy"], check=False)
        for _ in range(10):
            if socket_path.exists() and socket_path.is_socket():
                break
            time.sleep(1)


def update_servers(cf_ips: list[dict]) -> None:
    servers = cf_ips[:MAX_SERVERS]
    for idx in range(1, MAX_SERVERS + 1):
        server_name = f"cf{idx}"
        if idx <= len(servers):
            ip = servers[idx - 1]["ip"]
            weight = servers[idx - 1].get("weight", 100)
            send_runtime(f"set server cloudflare_http/{server_name} addr {ip} port 80")
            send_runtime(f"set server cloudflare_https/{server_name} addr {ip} port 443")
            send_runtime(f"set server cloudflare_http/{server_name} weight {weight}")
            send_runtime(f"set server cloudflare_https/{server_name} weight {weight}")
            send_runtime(f"set server cloudflare_http/{server_name} state ready")
            send_runtime(f"set server cloudflare_https/{server_name} state ready")
        else:
            send_runtime(f"set server cloudflare_http/{server_name} state maint")
            send_runtime(f"set server cloudflare_https/{server_name} state maint")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="sync once and exit")
    args = parser.parse_args()

    if not AGENT_TOKEN:
        raise SystemExit("AGENT_TOKEN is required")

    while True:
        try:
            config = fetch_config()
            domains = config.get("domains", [])
            write_domains(domains)
            ensure_runtime_socket()
            try:
                update_acl(domains)
            except (OSError, socket.error):
                pass
            update_servers(config.get("cf_ips", []))
            post_metrics()
        except requests.RequestException as exc:
            print(f"Failed to sync config: {exc}")
        except (OSError, socket.error) as exc:
            print(f"Runtime socket error: {exc}")
        if args.once:
            break
        time.sleep(SYNC_INTERVAL)


if __name__ == "__main__":
    main()
