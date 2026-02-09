#!/usr/bin/env bash
set -euo pipefail

INSTALL_DIR="/opt/cf-relay-control"
CONTROL_PORT="${CONTROL_PORT:-8080}"
REPO_URL="${REPO_URL:-https://github.com/modu369/gpt.git}"
REPO_REF="${REPO_REF:-codex/develop-high-performance-cloudflare-proxy-system}"

sudo apt-get update
sudo apt-get install -y python3 python3-venv python3-pip git

WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT
git clone --depth 1 --branch "$REPO_REF" "$REPO_URL" "$WORK_DIR"

if [ ! -d "$WORK_DIR/control-plane" ]; then
  echo "control-plane directory not found in repo. Check REPO_URL/REPO_REF." >&2
  exit 1
fi

if [ -d "$INSTALL_DIR" ]; then
  sudo rm -rf "$INSTALL_DIR"
fi

sudo mkdir -p "$INSTALL_DIR"
sudo cp -r "$WORK_DIR/control-plane/"* "$INSTALL_DIR/"

sudo python3 -m venv "$INSTALL_DIR/venv"
sudo "$INSTALL_DIR/venv/bin/pip" install --upgrade pip
sudo "$INSTALL_DIR/venv/bin/pip" install -r "$INSTALL_DIR/requirements.txt"

sudo tee /etc/systemd/system/cf-relay-control.service > /dev/null <<SERVICE
[Unit]
Description=CF Relay Control Plane
After=network.target

[Service]
Type=simple
WorkingDirectory=$INSTALL_DIR
Environment=CONTROL_PORT=$CONTROL_PORT
Environment=CONTROL_ADMIN_USER=${CONTROL_ADMIN_USER:-admin}
Environment=CONTROL_ADMIN_PASS=${CONTROL_ADMIN_PASS:-admin123}
ExecStart=$INSTALL_DIR/venv/bin/python $INSTALL_DIR/app.py
Restart=always

[Install]
WantedBy=multi-user.target
SERVICE

sudo systemctl daemon-reload
sudo systemctl enable --now cf-relay-control.service

echo "控制台已启动: http://<IP>:$CONTROL_PORT"
