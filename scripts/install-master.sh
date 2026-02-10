#!/usr/bin/env bash
set -euo pipefail

REPO_URL=${REPO_URL:-https://github.com/modu369/gpt.git}
REPO_BRANCH=${REPO_BRANCH:-}
PROJECT_SUBDIR=${PROJECT_SUBDIR:-develop-high-performance-cloudflare-ip-forwarding-system}
INSTALL_DIR=${INSTALL_DIR:-/opt/cfrelay}
MASTER_LISTEN=${MASTER_LISTEN:-:8080}
MASTER_MARKER=${MASTER_MARKER:-tianyun123}
MASTER_ENROLL_KEY=${MASTER_ENROLL_KEY:-change-me}
MASTER_ADMIN_USER=${MASTER_ADMIN_USER:-admin}
MASTER_ADMIN_PASS=${MASTER_ADMIN_PASS:-change-me}
MASTER_ADMIN_TOKEN=${MASTER_ADMIN_TOKEN:-admin-change-me}

apt-get update
apt-get install -y git curl golang ca-certificates
rm -rf "$INSTALL_DIR"
git clone "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"

if [[ -n "$REPO_BRANCH" ]]; then
  if git rev-parse --verify "origin/$REPO_BRANCH" >/dev/null 2>&1; then
    git checkout -B "$REPO_BRANCH" "origin/$REPO_BRANCH"
  else
    echo "[WARN] branch '$REPO_BRANCH' not found, fallback to repository default branch"
  fi
fi

PROJECT_DIR="$INSTALL_DIR"
if [[ -d "$INSTALL_DIR/$PROJECT_SUBDIR" ]]; then
  PROJECT_DIR="$INSTALL_DIR/$PROJECT_SUBDIR"
elif [[ -d "$INSTALL_DIR/codex/$PROJECT_SUBDIR" ]]; then
  PROJECT_DIR="$INSTALL_DIR/codex/$PROJECT_SUBDIR"
fi

if [[ ! -f "$PROJECT_DIR/cmd/master/main.go" ]]; then
  echo "[ERROR] cmd/master/main.go not found under PROJECT_DIR=$PROJECT_DIR"
  echo "[HINT] set PROJECT_SUBDIR to the correct subdirectory, or keep empty when project is repo root"
  exit 1
fi

cd "$PROJECT_DIR"
go build -o /usr/local/bin/cfrelay-master ./cmd/master
install -m 644 systemd/cfrelay-master.service /etc/systemd/system/cfrelay-master.service
sed -i "s|__MASTER_LISTEN__|$MASTER_LISTEN|g" /etc/systemd/system/cfrelay-master.service
sed -i "s|__MASTER_MARKER__|$MASTER_MARKER|g" /etc/systemd/system/cfrelay-master.service
sed -i "s|__MASTER_ENROLL_KEY__|$MASTER_ENROLL_KEY|g" /etc/systemd/system/cfrelay-master.service
sed -i "s|__MASTER_ADMIN_USER__|$MASTER_ADMIN_USER|g" /etc/systemd/system/cfrelay-master.service
sed -i "s|__MASTER_ADMIN_PASS__|$MASTER_ADMIN_PASS|g" /etc/systemd/system/cfrelay-master.service
sed -i "s|__MASTER_ADMIN_TOKEN__|$MASTER_ADMIN_TOKEN|g" /etc/systemd/system/cfrelay-master.service
systemctl daemon-reload
systemctl enable --now cfrelay-master

echo "master installed: http://<ip>${MASTER_LISTEN}/${MASTER_MARKER}/login"
