#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/opt/cfrelay"
APP_DIR="$REPO_DIR/agent"
VENV_DIR="$REPO_DIR/.venv"
REPO_URL="https://github.com/modu369/gpt.git"
REPO_BRANCH="codex/develop-high-performance-cloudflare-ip-forwarding-system-qbi51d"

CONTROLLER=""
TOKEN=""
NODE_NAME=""
ADMIN_PATH="yun123"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --controller) CONTROLLER="$2"; shift 2 ;;
    --token) TOKEN="$2"; shift 2 ;;
    --node-name) NODE_NAME="$2"; shift 2 ;;
    --admin-path) ADMIN_PATH="$2"; shift 2 ;;
    *) echo "Unknown arg: $1"; exit 1 ;;
  esac
done

if [[ -z "$CONTROLLER" || -z "$TOKEN" || -z "$NODE_NAME" ]]; then
  echo "Usage: install_agent.sh --controller <url> --token <token> --node-name <name> [--admin-path yun123]"
  exit 1
fi

SHARED_SECRET=$(openssl rand -hex 16)

apt-get update
apt-get install -y python3 python3-venv python3-pip git haproxy curl iproute2 openssl speedtest-cli

cat >/etc/sysctl.d/99-bbr.conf <<SYS
net.core.default_qdisc=fq
net.ipv4.tcp_congestion_control=bbr
SYS
sysctl --system >/dev/null 2>&1 || true

if [[ ! -d "$REPO_DIR/.git" ]]; then
  git clone -b "$REPO_BRANCH" --single-branch "$REPO_URL" "$REPO_DIR"
else
  git -C "$REPO_DIR" fetch origin "$REPO_BRANCH"
  git -C "$REPO_DIR" checkout "$REPO_BRANCH"
  git -C "$REPO_DIR" reset --hard "origin/$REPO_BRANCH"
fi

python3 -m venv "$VENV_DIR"
"$VENV_DIR/bin/pip" install --upgrade pip
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
Environment=CONTROLLER_API_BASE=$CONTROLLER/$ADMIN_PATH/api
Environment=NODE_NAME=$NODE_NAME
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

DOWN=100
UP=100
if command -v speedtest-cli >/dev/null 2>&1; then
  OUT=$(speedtest-cli --simple 2>/dev/null || true)
  D=$(echo "$OUT" | awk '/Download/ {print int($2)}')
  U=$(echo "$OUT" | awk '/Upload/ {print int($2)}')
  [[ -n "$D" ]] && DOWN="$D"
  [[ -n "$U" ]] && UP="$U"
fi
MAX_BW=$(( DOWN < UP ? DOWN : UP ))

curl -fsSL -X POST "$CONTROLLER/$ADMIN_PATH/api/nodes/register" \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$TOKEN\",\"name\":\"$NODE_NAME\",\"endpoint\":\"http://$IP:18080\",\"shared_secret\":\"$SHARED_SECRET\",\"cpu_cores\":$CPU,\"memory_mb\":$MEM,\"max_bandwidth_mbps\":$MAX_BW}" \
  || true

echo "Agent installed/updated and now active."
