#!/usr/bin/env bash
set -euo pipefail

sudo systemctl disable --now cf-relay-agent.service || true
sudo rm -f /etc/systemd/system/cf-relay-agent.service
sudo systemctl daemon-reload
sudo rm -rf /opt/cf-relay-agent

sudo systemctl disable --now haproxy || true

echo "Agent 已卸载。"
