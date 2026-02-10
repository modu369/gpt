#!/usr/bin/env bash
set -euo pipefail
REPO_URL=${REPO_URL:-https://github.com/modu369/gpt.git}
REPO_BRANCH=${REPO_BRANCH:-codex}
PROJECT_SUBDIR=${PROJECT_SUBDIR:-develop-high-performance-cloudflare-ip-forwarding-system}
INSTALL_DIR=${INSTALL_DIR:-/opt/cfrelay}

if [[ -z "${MASTER_URL:-}" || -z "${ENROLL_KEY:-}" || -z "${NODE_ID:-}" ]]; then
  echo "usage: MASTER_URL=... ENROLL_KEY=... NODE_ID=... bash install-agent.sh"
  exit 1
fi

apt-get update
apt-get install -y git curl golang ca-certificates ethtool certbot speedtest-cli
rm -rf "$INSTALL_DIR"
git clone -b "$REPO_BRANCH" "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR/$PROJECT_SUBDIR"
go build -o /usr/local/bin/cfrelay-agent ./cmd/agent
install -m 644 systemd/cfrelay-agent.service /etc/systemd/system/cfrelay-agent.service

sed -i "s|__MASTER_URL__|$MASTER_URL|g" /etc/systemd/system/cfrelay-agent.service
sed -i "s|__ENROLL_KEY__|$ENROLL_KEY|g" /etc/systemd/system/cfrelay-agent.service
sed -i "s|__NODE_ID__|$NODE_ID|g" /etc/systemd/system/cfrelay-agent.service

bash scripts/optimize-bbr.sh
systemctl daemon-reload
systemctl enable --now cfrelay-agent

echo "agent installed"
