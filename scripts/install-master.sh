#!/usr/bin/env bash
set -euo pipefail

REPO_URL=${REPO_URL:-https://github.com/modu369/gpt.git}
# 允许为空；若指定但不存在会自动回退
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

echo "[INFO] cloning repository..."
git clone "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"

# 候选ref按顺序尝试：
# 1) REPO_BRANCH
# 2) REPO_BRANCH/PROJECT_SUBDIR（适配带斜杠分支）
# 3) 直接使用默认分支（不切换）
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
# 1) 显式子目录
if [[ -d "$INSTALL_DIR/$PROJECT_SUBDIR" && -f "$INSTALL_DIR/$PROJECT_SUBDIR/cmd/master/main.go" ]]; then
  PROJECT_DIR="$INSTALL_DIR/$PROJECT_SUBDIR"
fi
# 2) 常见 codex 子路径
if [[ -z "$PROJECT_DIR" && -d "$INSTALL_DIR/codex/$PROJECT_SUBDIR" && -f "$INSTALL_DIR/codex/$PROJECT_SUBDIR/cmd/master/main.go" ]]; then
  PROJECT_DIR="$INSTALL_DIR/codex/$PROJECT_SUBDIR"
fi
# 3) 仓库根目录
if [[ -z "$PROJECT_DIR" && -f "$INSTALL_DIR/cmd/master/main.go" ]]; then
  PROJECT_DIR="$INSTALL_DIR"
fi
# 4) 自动扫描（兜底）
if [[ -z "$PROJECT_DIR" ]]; then
  CANDIDATE=$(find "$INSTALL_DIR" -maxdepth 5 -type f -path '*/cmd/master/main.go' | head -n 1 || true)
  if [[ -n "$CANDIDATE" ]]; then
    PROJECT_DIR=$(dirname "$(dirname "$CANDIDATE")")
  fi
fi

if [[ -z "$PROJECT_DIR" || ! -f "$PROJECT_DIR/cmd/master/main.go" ]]; then
  echo "[ERROR] cmd/master/main.go not found"
  echo "[HINT] checked PROJECT_SUBDIR='$PROJECT_SUBDIR' and auto-scan under $INSTALL_DIR"
  exit 1
fi

echo "[INFO] project dir: $PROJECT_DIR"
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
