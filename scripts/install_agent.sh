#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/opt/cfrelay"
APP_DIR="$REPO_DIR/agent"
VENV_DIR="$REPO_DIR/.venv"

CONTROLLER=""
TOKEN=""
NODE_NAME=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --controller) CONTROLLER="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --node-name) NODE_NAME="$2"; shift 2 ;;
    *) echo "Unknown arg: $1"; exit 1 ;;
  esac
done

if [[ -z "$CONTROLLER" || -z "$TOKEN" || -z "$NODE_NAME" ]]; then
  echo "Usage: install_agent.sh --controller <url> --token <token> --node-name <name>"
  exit 1
fi

SHARED_SECRET=$(openssl rand -hex 16)

apt-get update
apt-get install -y python3 python3-venv python3-pip git haproxy curl iproute2

# enable BBR
cat >/etc/sysctl.d/99-bbr.conf <<SYS
net.core.default_qdisc=fq
net.ipv4.tcp_congestion_control=bbr
SYS
sysctl --system >/dev/null 2>&1 || true

if [[ ! -d "$REPO_DIR/.git" ]]; then
  git clone https://github.com/<your-org>/<your-repo>.git "$REPO_DIR"
else
  git -C "$REPO_DIR" pull --ff-only
fi

python3 -m venv "$VENV_DIR"
"$VENV_DIR/bin/pip" install -r "$APP_DIR/requirements.txt"

cat >/etc/systemd/system/cfrelay-agent.service <<SERVICE
[Unit]
Description=CF Relay Agent
After=network.target

[Service]
Type=simple
WorkingDirectory=$REPO_DIR
Environment=AGENT_SHARED_SECRET=$SHARED_SECRET
Environment=AGENT_PORT=18080
ExecStart=$VENV_DIR/bin/uvicorn agent.main:app --host 0.0.0.0 --port 18080
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now cfrelay-agent
systemctl enable --now haproxy

IP=$(curl -fsSL https://api.ipify.org || hostname -I | awk '{print $1}')
CPU=$(nproc)
MEM=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)

curl -fsSL -X POST "$CONTROLLER/panel/api/nodes/register" \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$TOKEN\",\"name\":\"$NODE_NAME\",\"endpoint\":\"http://$IP:18080\",\"shared_secret\":\"$SHARED_SECRET\",\"cpu_cores\":$CPU,\"memory_mb\":$MEM,\"max_bandwidth_mbps\":100}" \
  || true

echo "Agent installed and registration attempted."
