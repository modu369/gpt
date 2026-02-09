#!/usr/bin/env bash
set -euo pipefail

sudo systemctl disable --now cf-relay-control.service || true
sudo rm -f /etc/systemd/system/cf-relay-control.service
sudo systemctl daemon-reload
sudo rm -rf /opt/cf-relay-control

echo "控制端已卸载。"
