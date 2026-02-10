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

echo "[INFO] cloning repository..."
git clone "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"

if [[ -n "$REPO_BRANCH" ]]; then
  if git rev-parse --verify "origin/$REPO_BRANCH" >/dev/null 2>&1; then
    git checkout -B "$REPO_BRANCH" "origin/$REPO_BRANCH"
    echo "[INFO] switched to origin/$REPO_BRANCH"
  elif git rev-parse --verify "origin/${REPO_BRANCH}/${PROJECT_SUBDIR}" >/dev/null 2>&1; then
    git checkout -B "${REPO_BRANCH}-${PROJECT_SUBDIR}" "origin/${REPO_BRANCH}/${PROJECT_SUBDIR}"
    echo "[INFO] switched to origin/${REPO_BRANCH}/${PROJECT_SUBDIR}"
  else
    echo "[WARN] branch '$REPO_BRANCH' not found, keep repository default branch"
  fi
fi

PROJECT_DIR=""
if [[ -d "$INSTALL_DIR/$PROJECT_SUBDIR" && -f "$INSTALL_DIR/$PROJECT_SUBDIR/cmd/agent/main.go" ]]; then
  PROJECT_DIR="$INSTALL_DIR/$PROJECT_SUBDIR"
fi
if [[ -z "$PROJECT_DIR" && -d "$INSTALL_DIR/codex/$PROJECT_SUBDIR" && -f "$INSTALL_DIR/codex/$PROJECT_SUBDIR/cmd/agent/main.go" ]]; then
  PROJECT_DIR="$INSTALL_DIR/codex/$PROJECT_SUBDIR"
fi
if [[ -z "$PROJECT_DIR" && -f "$INSTALL_DIR/cmd/agent/main.go" ]]; then
  PROJECT_DIR="$INSTALL_DIR"
fi
if [[ -z "$PROJECT_DIR" ]]; then
  CANDIDATE=$(find "$INSTALL_DIR" -maxdepth 5 -type f -path '*/cmd/agent/main.go' | head -n 1 || true)
  if [[ -n "$CANDIDATE" ]]; then
    PROJECT_DIR=$(dirname "$(dirname "$CANDIDATE")")
  fi
fi

if [[ -z "$PROJECT_DIR" || ! -f "$PROJECT_DIR/cmd/agent/main.go" ]]; then
  echo "[ERROR] cmd/agent/main.go not found"
  echo "[HINT] checked PROJECT_SUBDIR='$PROJECT_SUBDIR' and auto-scan under $INSTALL_DIR"
  exit 1
fi

echo "[INFO] project dir: $PROJECT_DIR"
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
