#!/usr/bin/env bash
set -euo pipefail

CONTROL_URL=""
TOKEN=""
REPO_URL="${REPO_URL:-https://github.com/modu369/gpt.git}"
REPO_REF="${REPO_REF:-codex/develop-high-performance-cloudflare-proxy-system-6h0ek0}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --control-url)
      CONTROL_URL="$2"
      shift 2
      ;;
    --token)
      TOKEN="$2"
      shift 2
      ;;
    *)
      echo "Unknown arg: $1" >&2
      exit 1
      ;;
  esac
done

if [[ -z "$CONTROL_URL" || -z "$TOKEN" ]]; then
  echo "Usage: install-agent.sh --control-url <url> --token <token>" >&2
  exit 1
fi

sudo apt-get update
sudo apt-get install -y haproxy python3 python3-venv python3-pip git speedtest-cli openssl

WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT
git clone --depth 1 --branch "$REPO_REF" "$REPO_URL" "$WORK_DIR"

if [ ! -d "$WORK_DIR/agent" ] || [ ! -d "$WORK_DIR/configs" ]; then
  echo "agent/configs directory not found in repo. Check REPO_URL/REPO_REF." >&2
  exit 1
fi

sudo mkdir -p /etc/haproxy/maps /etc/haproxy/certs
sudo touch /etc/haproxy/maps/domains.map
if ! ls /etc/haproxy/certs/*.pem >/dev/null 2>&1; then
  sudo openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -subj "/CN=relay" \
    -keyout /etc/haproxy/certs/selfsigned.key \
    -out /etc/haproxy/certs/selfsigned.crt
  sudo cat /etc/haproxy/certs/selfsigned.key /etc/haproxy/certs/selfsigned.crt > /etc/haproxy/certs/selfsigned.pem
fi
sudo chown -R haproxy:haproxy /etc/haproxy/certs /etc/haproxy/maps
sudo chmod 750 /etc/haproxy/certs /etc/haproxy/maps
sudo chmod 640 /etc/haproxy/certs/*.pem /etc/haproxy/maps/domains.map
sudo cp "$WORK_DIR/configs/haproxy.cfg" /etc/haproxy/haproxy.cfg

sudo systemctl enable --now haproxy

INSTALL_DIR="/opt/cf-relay-agent"
if [ -d "$INSTALL_DIR" ]; then
  sudo rm -rf "$INSTALL_DIR"
fi

sudo mkdir -p "$INSTALL_DIR"
sudo cp -r "$WORK_DIR/agent/"* "$INSTALL_DIR/"

sudo python3 -m venv "$INSTALL_DIR/venv"
sudo "$INSTALL_DIR/venv/bin/pip" install --upgrade pip
sudo "$INSTALL_DIR/venv/bin/pip" install -r "$INSTALL_DIR/requirements.txt"

BBR_CONF="/etc/sysctl.d/99-cf-relay-bbr.conf"
sudo modprobe tcp_bbr || true
echo "net.core.default_qdisc=fq" | sudo tee "$BBR_CONF" > /dev/null
echo "net.ipv4.tcp_congestion_control=bbr" | sudo tee -a "$BBR_CONF" > /dev/null
sudo sysctl -p "$BBR_CONF" > /dev/null

ENV_FILE="/etc/default/cf-relay-agent"
MAX_BANDWIDTH_MBPS="${MAX_BANDWIDTH_MBPS:-}"
if [ -z "$MAX_BANDWIDTH_MBPS" ] && command -v speedtest-cli >/dev/null 2>&1; then
  SPEEDTEST_RESULT=$(speedtest-cli --simple 2>/dev/null | awk -F': ' '/Download/ {print $2}' | awk '{print $1}')
  if [ -n "$SPEEDTEST_RESULT" ]; then
    MAX_BANDWIDTH_MBPS="$SPEEDTEST_RESULT"
  fi
fi
sudo tee "$ENV_FILE" > /dev/null <<ENV
CONTROL_URL=$CONTROL_URL
AGENT_TOKEN=$TOKEN
MAX_BANDWIDTH_MBPS=${MAX_BANDWIDTH_MBPS}
ENV

sudo tee /etc/systemd/system/cf-relay-agent.service > /dev/null <<SERVICE
[Unit]
Description=CF Relay Agent
After=network.target haproxy.service

[Service]
Type=simple
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=$ENV_FILE
Environment=RUNTIME_SOCKET=/run/haproxy/admin.sock
ExecStart=$INSTALL_DIR/venv/bin/python $INSTALL_DIR/agent.py
Restart=always

[Install]
WantedBy=multi-user.target
SERVICE

sudo systemctl daemon-reload
sudo systemctl enable --now cf-relay-agent.service
sudo systemctl restart cf-relay-agent.service

echo "Agent 已启动并开始同步配置。"
