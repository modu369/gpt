#!/bin/bash
set -euo pipefail

# install_node.sh - 被控端一键安装脚本
# 用法: ./install_node.sh -master http://x.x.x.x:8080/api -secret xxxx

REPO_URL="https://github.com/modu369/gpt.git"
BRANCH="codex/add-domain-level-traffic-statistics-report"

RED='\033[0;31m'
GREEN='\033[0;32m'
PLAIN='\033[0m'

if [[ "${EUID}" -ne 0 ]]; then
  echo -e "${RED}Error: Must be root${PLAIN}"
  exit 1
fi

MASTER_URL=""
NODE_SECRET=""
UNINSTALL="false"

while [[ $# -gt 0 ]]; do
  case "$1" in
    -master)
      MASTER_URL="${2:-}"
      shift 2
      ;;
    -secret)
      NODE_SECRET="${2:-}"
      shift 2
      ;;
    --uninstall)
      UNINSTALL="true"
      shift
      ;;
    *)
      shift
      ;;
  esac
done

if [[ "${UNINSTALL}" == "true" ]]; then
  systemctl stop cf-proxy >/dev/null 2>&1 || true
  systemctl disable cf-proxy >/dev/null 2>&1 || true
  rm -f /etc/systemd/system/cf-proxy.service
  systemctl daemon-reload
  rm -f /usr/local/bin/cf-proxy
  echo -e "${GREEN}卸载完成${PLAIN}"
  exit 0
fi

if [[ -z "${MASTER_URL}" || -z "${NODE_SECRET}" ]]; then
  echo -e "${RED}用法错误: ./install_node.sh -master http://x.x.x.x:8080/api -secret xxxx${PLAIN}"
  exit 1
fi

echo -e "${GREEN}1/5 安装依赖 (Go, Git)...${PLAIN}"
export DEBIAN_FRONTEND=noninteractive
apt update -y
apt install -y git wget tar curl ca-certificates

if ! command -v go >/dev/null 2>&1; then
  wget -q https://go.dev/dl/go1.22.0.linux-amd64.tar.gz -O /tmp/go1.22.0.linux-amd64.tar.gz
  rm -rf /usr/local/go
  tar -C /usr/local -xzf /tmp/go1.22.0.linux-amd64.tar.gz
fi
export PATH=$PATH:/usr/local/go/bin
if ! grep -q '/usr/local/go/bin' /root/.bashrc; then
  echo 'export PATH=$PATH:/usr/local/go/bin' >> /root/.bashrc
fi

echo -e "${GREEN}2/5 拉取源码并编译...${PLAIN}"
BUILD_DIR="/opt/cf-proxy-src"
rm -rf "${BUILD_DIR}"
git clone -b "${BRANCH}" "${REPO_URL}" "${BUILD_DIR}"
cd "${BUILD_DIR}"
/usr/local/go/bin/go mod tidy
/usr/local/go/bin/go build -ldflags "-s -w" -o /usr/local/bin/cf-proxy ./cmd/cf-proxy
chmod +x /usr/local/bin/cf-proxy

echo -e "${GREEN}3/5 注册 Systemd 服务...${PLAIN}"
cat > /etc/systemd/system/cf-proxy.service <<SYSTEMD
[Unit]
Description=CF-Proxy Node
After=network.target

[Service]
Type=simple
User=root
LimitNOFILE=1000000
ExecStart=/usr/local/bin/cf-proxy -master "${MASTER_URL}" -secret "${NODE_SECRET}"
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SYSTEMD

echo -e "${GREEN}4/5 应用内核优化 (BBR)...${PLAIN}"
if ! grep -q '^net.core.default_qdisc=fq$' /etc/sysctl.conf; then
  echo 'net.core.default_qdisc=fq' >> /etc/sysctl.conf
fi
if ! grep -q '^net.ipv4.tcp_congestion_control=bbr$' /etc/sysctl.conf; then
  echo 'net.ipv4.tcp_congestion_control=bbr' >> /etc/sysctl.conf
fi
sysctl -p >/dev/null 2>&1 || true

if command -v ufw >/dev/null 2>&1; then
  ufw allow 7777/tcp >/dev/null 2>&1 || true
fi

echo -e "${GREEN}5/5 启动服务...${PLAIN}"
systemctl daemon-reload
systemctl enable cf-proxy
systemctl restart cf-proxy

echo -e "${GREEN}节点安装成功！已启动并连接主控。${PLAIN}"
echo "查看状态: systemctl status cf-proxy --no-pager"
