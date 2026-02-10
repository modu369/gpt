#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/opt/cfrelay"
APP_DIR="$REPO_DIR/controller"
VENV_DIR="$REPO_DIR/.venv"
REPO_URL="https://github.com/modu369/gpt.git"
REPO_BRANCH="codex/develop-high-performance-cloudflare-ip-forwarding-system-qbi51d"

read -rp "Admin username [admin]: " ADMIN_USER
ADMIN_USER=${ADMIN_USER:-admin}
read -rsp "Admin password [admin123]: " ADMIN_PASSWORD
echo
ADMIN_PASSWORD=${ADMIN_PASSWORD:-admin123}
read -rp "Admin path [panel]: " ADMIN_PATH
ADMIN_PATH=${ADMIN_PATH:-panel}

apt-get update
apt-get install -y python3 python3-venv python3-pip git curl

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

# Optional firewall opening for Debian hosts using ufw/firewalld
if command -v ufw >/dev/null 2>&1; then
  ufw allow 8080/tcp >/dev/null 2>&1 || true
fi
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
  firewall-cmd --permanent --add-port=8080/tcp >/dev/null 2>&1 || true
  firewall-cmd --reload >/dev/null 2>&1 || true
fi

systemctl daemon-reload
systemctl enable --now cfrelay-controller

# readiness check, fail fast if service is broken
for _ in $(seq 1 20); do
  if curl -fsS "http://127.0.0.1:8080/$ADMIN_PATH/healthz" >/dev/null 2>&1; then
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

if ! curl -fsS "http://127.0.0.1:8080/$ADMIN_PATH/healthz" >/dev/null 2>&1; then
  echo "ERROR: Controller health check failed at /$ADMIN_PATH/healthz"
  systemctl status cfrelay-controller --no-pager || true
  journalctl -u cfrelay-controller -n 80 --no-pager || true
  echo "Hint: check logs above first; also verify TCP/8080 is allowed in host firewall and cloud security group"
  exit 1
fi


# verify visual panel is actually deployed (not old plain instruction page)
PAGE_HTML="$(curl -fsS "http://127.0.0.1:8080/$ADMIN_PATH" || true)"
if ! printf '%s' "$PAGE_HTML" | grep -q "CF Relay 管理后台登录"; then
  echo "ERROR: visual admin login page not detected at /$ADMIN_PATH"
  echo "Current page snippet:"
  printf '%s
' "$PAGE_HTML" | head -n 20
  echo "Hint: branch content may be outdated; expected file: controller/static/admin/index.html"
  exit 1
fi

IP=$(hostname -I | awk '{print $1}')
echo "Controller installed:"
echo "Panel:  http://$IP:8080/$ADMIN_PATH"
echo "API:    http://$IP:8080/$ADMIN_PATH/api"
echo "Docs:   http://$IP:8080/$ADMIN_PATH/api/docs"
echo "Health: http://$IP:8080/$ADMIN_PATH/healthz"
