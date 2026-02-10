#!/usr/bin/env bash
set -euo pipefail

systemctl disable --now cfrelay-controller 2>/dev/null || true
systemctl disable --now cfrelay-agent 2>/dev/null || true
rm -f /etc/systemd/system/cfrelay-controller.service
rm -f /etc/systemd/system/cfrelay-agent.service
systemctl daemon-reload

rm -rf /opt/cfrelay

echo "Uninstalled."
