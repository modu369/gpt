#!/usr/bin/env bash
set -euo pipefail
systemctl disable --now cfrelay-master 2>/dev/null || true
systemctl disable --now cfrelay-agent 2>/dev/null || true
rm -f /etc/systemd/system/cfrelay-master.service /etc/systemd/system/cfrelay-agent.service
systemctl daemon-reload
rm -f /usr/local/bin/cfrelay-master /usr/local/bin/cfrelay-agent
rm -rf /opt/cfrelay

echo "uninstalled"
