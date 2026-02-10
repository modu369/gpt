#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/opt/cfrelay"
APP_DIR="$REPO_DIR/controller"
VENV_DIR="$REPO_DIR/.venv"
REPO_URL="https://github.com/modu369/gpt.git"
REPO_BRANCH="codex/implement-management-backend-updates"

ADMIN_USER="admin"
ADMIN_PASSWORD="admin123"
ADMIN_PATH="yun123"
CONTROLLER_PORT="8080"

apt-get update
apt-get install -y python3 python3-venv python3-pip git curl

if [[ ! -d "$REPO_DIR/.git" ]]; then
  git clone -b "$REPO_BRANCH" --single-branch "$REPO_URL" "$REPO_DIR"
else
  git -C "$REPO_DIR" fetch origin "refs/heads/$REPO_BRANCH:refs/remotes/origin/$REPO_BRANCH"
  git -C "$REPO_DIR" checkout -B "$REPO_BRANCH" "origin/$REPO_BRANCH"
  git -C "$REPO_DIR" reset --hard "origin/$REPO_BRANCH"
fi

python3 -m venv "$VENV_DIR"
"$VENV_DIR/bin/pip" install --upgrade pip
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
Environment=CONTROLLER_PORT=$CONTROLLER_PORT
ExecStart=$VENV_DIR/bin/uvicorn controller.main:app --host 0.0.0.0 --port $CONTROLLER_PORT
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
SERVICE

if command -v ufw >/dev/null 2>&1; then
  ufw allow ${CONTROLLER_PORT}/tcp >/dev/null 2>&1 || true
fi
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-port=${CONTROLLER_PORT}/tcp >/dev/null 2>&1 || true
  firewall-cmd --reload >/dev/null 2>&1 || true
fi

systemctl daemon-reload
systemctl enable --now cfrelay-controller

for _ in $(seq 1 30); do
  if curl -fsS "http://127.0.0.1:${CONTROLLER_PORT}/${ADMIN_PATH}/healthz" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if ! systemctl is-active --quiet cfrelay-controller; then
  echo "ERROR: cfrelay-controller service is not active"
  systemctl status cfrelay-controller --no-pager || true
  journalctl -u cfrelay-controller -n 80 --no-pager || true
  exit 1
fi

IP=$(hostname -I | awk '{print $1}')
echo "Controller installed/updated and now active:"
echo "Panel:  http://$IP:${CONTROLLER_PORT}/${ADMIN_PATH}"
echo "API:    http://$IP:${CONTROLLER_PORT}/${ADMIN_PATH}/api"
echo "Docs:   http://$IP:${CONTROLLER_PORT}/${ADMIN_PATH}/api/docs"
echo "Health: http://$IP:${CONTROLLER_PORT}/${ADMIN_PATH}/healthz"
