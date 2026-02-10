#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/opt/cfrelay"
APP_DIR="$REPO_DIR/controller"
VENV_DIR="$REPO_DIR/.venv"

read -rp "Admin username [admin]: " ADMIN_USER
ADMIN_USER=${ADMIN_USER:-admin}
read -rsp "Admin password [admin123]: " ADMIN_PASSWORD
echo
ADMIN_PASSWORD=${ADMIN_PASSWORD:-admin123}
read -rp "Admin path [panel]: " ADMIN_PATH
ADMIN_PATH=${ADMIN_PATH:-panel}

apt-get update
apt-get install -y python3 python3-venv python3-pip git

if [[ ! -d "$REPO_DIR/.git" ]]; then
  git clone https://github.com/<your-org>/<your-repo>.git "$REPO_DIR"
else
  git -C "$REPO_DIR" pull --ff-only
fi

python3 -m venv "$VENV_DIR"
"$VENV_DIR/bin/pip" install -r "$APP_DIR/requirements.txt"

cat >/etc/systemd/system/cfrelay-controller.service <<SERVICE
[Unit]
Description=CF Relay Controller
After=network.target

[Service]
Type=simple
WorkingDirectory=$REPO_DIR
Environment=ADMIN_USER=$ADMIN_USER
Environment=ADMIN_PASSWORD=$ADMIN_PASSWORD
Environment=ADMIN_PATH=$ADMIN_PATH
Environment=CONTROLLER_PORT=8080
ExecStart=$VENV_DIR/bin/uvicorn controller.main:app --host 0.0.0.0 --port 8080
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now cfrelay-controller

echo "Controller installed: http://$(hostname -I | awk '{print $1}'):8080/$ADMIN_PATH/api"
