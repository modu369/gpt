from __future__ import annotations

import argparse
import os
import socket
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


def post_metrics() -> None:
    payload = {
        "loadavg": read_loadavg(),
        "mem_used_mb": read_mem_used_mb(),
        "rx_bytes": read_net_bytes(NET_IFACE)[0],
        "tx_bytes": read_net_bytes(NET_IFACE)[1],
    }
    requests.post(
        f"{CONTROL_URL}/api/metrics",
        headers={"X-Agent-Token": AGENT_TOKEN},
        json=payload,
        timeout=5,
    )


def write_domains(domains: Iterable[str]) -> None:
    DOMAINS_MAP.parent.mkdir(parents=True, exist_ok=True)
    lines = [f"{domain} 1" for domain in sorted(set(domains))]
    DOMAINS_MAP.write_text("\n".join(lines) + ("\n" if lines else ""))


def send_runtime(cmd: str) -> str:
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as sock:
        sock.connect(RUNTIME_SOCKET)
        sock.sendall(cmd.encode("utf-8") + b"\n")
        sock.shutdown(socket.SHUT_WR)
        data = sock.recv(65535)
    return data.decode("utf-8", errors="ignore")


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
            write_domains(config.get("domains", []))
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
