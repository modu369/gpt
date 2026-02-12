#!/bin/bash
set -euo pipefail

# install_node.sh - V6.0 智能负载均衡版 (自动采集硬件配置)
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
  rm -rf /etc/cf-proxy
  echo -e "${GREEN}卸载完成${PLAIN}"
  exit 0
fi

if [[ -z "${MASTER_URL}" || -z "${NODE_SECRET}" ]]; then
  echo -e "${RED}用法错误: ./install_node.sh -master http://x.x.x.x:8080/api -secret xxxx${PLAIN}"
  exit 1
fi

echo -e "${GREEN}1/6 安装依赖 (Go, Git)...${PLAIN}"
export DEBIAN_FRONTEND=noninteractive
apt update -y
apt install -y git wget tar curl ca-certificates jq

if ! command -v go >/dev/null 2>&1; then
  wget -q https://go.dev/dl/go1.22.0.linux-amd64.tar.gz -O /tmp/go1.22.0.linux-amd64.tar.gz
  rm -rf /usr/local/go
  tar -C /usr/local -xzf /tmp/go1.22.0.linux-amd64.tar.gz
fi
export PATH=$PATH:/usr/local/go/bin
if ! grep -q '/usr/local/go/bin' /root/.bashrc; then
  echo 'export PATH=$PATH:/usr/local/go/bin' >> /root/.bashrc
fi

echo -e "${GREEN}2/6 拉取源码并编译...${PLAIN}"
BUILD_DIR="/opt/cf-proxy-src"
rm -rf "${BUILD_DIR}"
git clone -b "${BRANCH}" "${REPO_URL}" "${BUILD_DIR}"
cd "${BUILD_DIR}"
/usr/local/go/bin/go mod tidy
/usr/local/go/bin/go build -ldflags "-s -w" -o /usr/local/bin/cf-proxy ./cmd/cf-proxy
chmod +x /usr/local/bin/cf-proxy

echo -e "${GREEN}3/6 硬件配置采集...${PLAIN}"
MAX_BW=0
read -r -p "是否进行网络带宽测速 (耗时约30秒)? [y/n] " run_speedtest || true
if [[ "${run_speedtest:-n}" =~ ^[yY]$ ]]; then
  echo "正在安装 speedtest-cli..."
  apt install -y speedtest-cli
  echo "正在测速，请耐心等待..."
  SPEED_LOG="$(timeout 60 speedtest-cli --simple 2>/dev/null || true)"
  if [[ -n "${SPEED_LOG}" ]]; then
    DOWN="$(echo "$SPEED_LOG" | awk '/Download/{print $2}')"
    UP="$(echo "$SPEED_LOG" | awk '/Upload/{print $2}')"
    if [[ -n "${DOWN}" && -n "${UP}" ]]; then
      DOWN_INT="$(printf "%.0f" "$DOWN" 2>/dev/null || echo 0)"
      UP_INT="$(printf "%.0f" "$UP" 2>/dev/null || echo 0)"
      if [[ "$DOWN_INT" -lt "$UP_INT" ]]; then
        MAX_BW="$DOWN_INT"
      else
        MAX_BW="$UP_INT"
      fi
      echo -e "${GREEN}测速结果: 上行 ${UP} Mbps / 下行 ${DOWN} Mbps -> 设定上限: ${MAX_BW} Mbps${PLAIN}"
    fi
  else
    echo -e "${RED}测速失败，跳过自动设置。${PLAIN}"
  fi
else
  echo "已跳过测速。"
fi

CPU_CORES="$(nproc)"
TOTAL_RAM="$(free -m | awk '/Mem:/ {print $2}')"

mkdir -p /etc/cf-proxy
cat > /etc/cf-proxy/hardware.json <<EOF
{
  "max_bw": ${MAX_BW},
  "max_ram": ${TOTAL_RAM},
  "cpu_cores": ${CPU_CORES}
}
EOF
echo -e "${GREEN}硬件信息已录入: CPU=${CPU_CORES}核, RAM=${TOTAL_RAM}MB, BW=${MAX_BW}Mbps${PLAIN}"

echo -e "${GREEN}4/6 注册 Systemd 服务...${PLAIN}"
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

echo -e "${GREEN}5/6 应用内核优化 (BBR)...${PLAIN}"
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

echo -e "${GREEN}6/6 启动服务...${PLAIN}"
systemctl daemon-reload
systemctl enable cf-proxy
systemctl restart cf-proxy

echo -e "${GREEN}节点安装成功！已启动并连接主控。${PLAIN}"
echo "查看状态: systemctl status cf-proxy --no-pager"
