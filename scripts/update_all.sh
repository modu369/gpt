#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/opt/cfrelay"

if [[ ! -d "$REPO_DIR/.git" ]]; then
  echo "Not installed: $REPO_DIR"
  exit 1
fi

git -C "$REPO_DIR" pull --ff-only

if systemctl is-active --quiet cfrelay-controller; then
  /opt/cfrelay/.venv/bin/pip install -r /opt/cfrelay/controller/requirements.txt
  systemctl restart cfrelay-controller
fi

if systemctl is-active --quiet cfrelay-agent; then
  /opt/cfrelay/.venv/bin/pip install -r /opt/cfrelay/agent/requirements.txt
  systemctl restart cfrelay-agent
  systemctl reload haproxy || true
fi

echo "Update completed."
