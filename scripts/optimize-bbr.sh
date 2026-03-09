#!/usr/bin/env bash
set -euo pipefail
cat >/etc/sysctl.d/99-cfrelay-bbr.conf <<SYS
net.core.default_qdisc=fq
net.ipv4.tcp_congestion_control=bbr
SYS
sysctl --system >/dev/null
echo "BBR enabled"
