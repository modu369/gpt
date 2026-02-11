#!/usr/bin/env bash
set -euo pipefail

# If this host is an agent, unregister node from controller first.
if [[ -f /etc/systemd/system/cfrelay-agent.service ]]; then
  CONTROLLER_API_BASE=$(awk -F= '/^Environment=CONTROLLER_API_BASE=/{print $2}' /etc/systemd/system/cfrelay-agent.service | tail -n1)
  NODE_NAME=$(awk -F= '/^Environment=NODE_NAME=/{print $2}' /etc/systemd/system/cfrelay-agent.service | tail -n1)
  AGENT_SHARED_SECRET=$(awk -F= '/^Environment=AGENT_SHARED_SECRET=/{print $2}' /etc/systemd/system/cfrelay-agent.service | tail -n1)

  if [[ -n "${CONTROLLER_API_BASE:-}" && -n "${NODE_NAME:-}" && -n "${AGENT_SHARED_SECRET:-}" ]]; then
    curl -fsS -X POST "${CONTROLLER_API_BASE%/}/nodes/${NODE_NAME}/unregister" \
      -H "X-Agent-Secret: ${AGENT_SHARED_SECRET}" \
      -H 'Content-Type: application/json' \
      -d '{}' >/dev/null 2>&1 || true
  fi
fi

systemctl disable --now cfrelay-controller 2>/dev/null || true
systemctl disable --now cfrelay-agent 2>/dev/null || true
rm -f /etc/systemd/system/cfrelay-controller.service
rm -f /etc/systemd/system/cfrelay-agent.service
systemctl daemon-reload

rm -rf /opt/cfrelay

echo "Uninstalled."
