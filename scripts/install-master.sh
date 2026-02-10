#!/usr/bin/env bash
set -euo pipefail
REPO_URL=${REPO_URL:-https://github.com/modu369/gpt.git}
REPO_BRANCH=${REPO_BRANCH:-codex}
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
git clone -b "$REPO_BRANCH" "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR/$PROJECT_SUBDIR"
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
