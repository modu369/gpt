#!/usr/bin/env bash
set -euo pipefail
REPO_URL=${REPO_URL:-https://github.com/your-org/cfrelay.git}
INSTALL_DIR=${INSTALL_DIR:-/opt/cfrelay}

apt-get update
apt-get install -y git curl golang ca-certificates
rm -rf "$INSTALL_DIR"
git clone "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"
go mod tidy
go build -o /usr/local/bin/cfrelay-master ./cmd/master
install -m 644 systemd/cfrelay-master.service /etc/systemd/system/cfrelay-master.service
systemctl daemon-reload
systemctl enable --now cfrelay-master

echo "master installed. edit /etc/systemd/system/cfrelay-master.service then: systemctl restart cfrelay-master"
