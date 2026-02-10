#!/usr/bin/env bash
set -euo pipefail

REPO_URL=${REPO_URL:-https://github.com/modu369/gpt.git}
REPO_BRANCH=${REPO_BRANCH:-}
PROJECT_SUBDIR=${PROJECT_SUBDIR:-develop-high-performance-cloudflare-ip-forwarding-system}
INSTALL_DIR=${INSTALL_DIR:-/opt/cfrelay}

if [[ -z "${MASTER_URL:-}" || -z "${ENROLL_KEY:-}" || -z "${NODE_ID:-}" ]]; then
  echo "usage: MASTER_URL=... ENROLL_KEY=... NODE_ID=... bash install-agent.sh"
  exit 1
fi

apt-get update
apt-get install -y git curl golang ca-certificates ethtool certbot speedtest-cli
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

if [[ ! -f "$PROJECT_DIR/cmd/agent/main.go" ]]; then
  echo "[ERROR] cmd/agent/main.go not found under PROJECT_DIR=$PROJECT_DIR"
  echo "[HINT] set PROJECT_SUBDIR to the correct subdirectory, or keep empty when project is repo root"
  exit 1
fi

cd "$PROJECT_DIR"
go build -o /usr/local/bin/cfrelay-agent ./cmd/agent
install -m 644 systemd/cfrelay-agent.service /etc/systemd/system/cfrelay-agent.service

sed -i "s|__MASTER_URL__|$MASTER_URL|g" /etc/systemd/system/cfrelay-agent.service
sed -i "s|__ENROLL_KEY__|$ENROLL_KEY|g" /etc/systemd/system/cfrelay-agent.service
sed -i "s|__NODE_ID__|$NODE_ID|g" /etc/systemd/system/cfrelay-agent.service

bash scripts/optimize-bbr.sh
systemctl daemon-reload
systemctl enable --now cfrelay-agent

echo "agent installed"
