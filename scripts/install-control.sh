#!/usr/bin/env bash
set -euo pipefail

INSTALL_DIR="/opt/cf-relay-control"
CONTROL_PORT="${CONTROL_PORT:-8080}"
CONTROL_ACCESS_PATH="${CONTROL_ACCESS_PATH:-yun123}"
REPO_URL="${REPO_URL:-https://github.com/modu369/gpt.git}"
REPO_REF="${REPO_REF:-codex/fix-cloudflare_http-socket-error}"

sudo apt-get update
sudo apt-get install -y python3 python3-venv python3-pip git curl

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

if [ ! -f /root/.acme.sh/acme.sh ]; then
  curl -fsSL https://get.acme.sh | sudo bash
fi

sudo tee /etc/systemd/system/cf-relay-control.service > /dev/null <<SERVICE
[Unit]
Description=CF Relay Control Plane
After=network.target

[Service]
Type=simple
WorkingDirectory=$INSTALL_DIR
Environment=CONTROL_PORT=$CONTROL_PORT
Environment=CONTROL_ACCESS_PATH=$CONTROL_ACCESS_PATH
Environment=CONTROL_ADMIN_USER=${CONTROL_ADMIN_USER:-admin}
Environment=CONTROL_ADMIN_PASS=${CONTROL_ADMIN_PASS:-admin123}
ExecStart=$INSTALL_DIR/venv/bin/python $INSTALL_DIR/app.py
Restart=always

[Install]
WantedBy=multi-user.target
SERVICE

sudo systemctl daemon-reload
sudo systemctl enable --now cf-relay-control.service
sudo systemctl restart cf-relay-control.service

sleep 1
if ! systemctl is-active --quiet cf-relay-control.service; then
  echo "控制台服务启动失败，请查看日志：" >&2
  sudo journalctl -u cf-relay-control.service --no-pager -n 50 >&2
  exit 1
fi

echo "控制台已启动: http://<IP>:$CONTROL_PORT/$CONTROL_ACCESS_PATH"
echo "默认账号: ${CONTROL_ADMIN_USER:-admin}"
echo "默认密码: ${CONTROL_ADMIN_PASS:-admin123}"
echo "如无法访问 $CONTROL_PORT，请检查安全组/防火墙放行 TCP $CONTROL_PORT。"
