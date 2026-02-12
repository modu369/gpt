#!/bin/bash
set -euo pipefail

# install_master.sh - 主控端一键部署脚本
# 适配仓库: https://github.com/modu369/gpt
# 分支: codex/add-domain-level-traffic-statistics-report

REPO_URL="https://github.com/modu369/gpt.git"
BRANCH="codex/add-domain-level-traffic-statistics-report"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
PLAIN='\033[0m'

trap 'echo -e "${RED}❌ 错误：脚本执行失败，请检查上方报错信息。${PLAIN}"' ERR

wait_for_db() {
  echo "正在等待数据库服务初始化..."
  local i
  for i in {1..30}; do
    if mysqladmin ping --silent >/dev/null 2>&1; then
      echo "数据库已就绪。"
      return 0
    fi
    echo "等待数据库启动 (${i}/30)..."
    sleep 2
  done
  echo -e "${RED}数据库在超时时间内未就绪，请检查 mariadb 服务状态。${PLAIN}"
  return 1
}

if [[ "${EUID}" -ne 0 ]]; then
  echo -e "${RED}错误：必须使用 root 用户运行此脚本！${PLAIN}"
  exit 1
fi

echo -e "${GREEN}1/8 安装基础环境...${PLAIN}"
export DEBIAN_FRONTEND=noninteractive
apt update -y
apt install -y nginx php-fpm php-mysql php-curl php-xml mariadb-server git unzip curl ca-certificates openssl cron socat

# acme.sh 依赖 cron 定时任务；最小化系统中通常默认未启用
systemctl enable --now cron >/dev/null 2>&1 || true
systemctl enable --now mariadb >/dev/null 2>&1 || true

echo -e "${GREEN}2/8 配置数据库...${PLAIN}"
wait_for_db

# 生成 16 位随机密码（避免 pipefail 下 tr|head 的 SIGPIPE 误报）
DB_PASS="$(openssl rand -hex 8)"
DB_NAME="cf_proxy_master"
DB_USER="cf_master"

mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo -e "${GREEN}3/8 拉取仓库并部署控制面...${PLAIN}"
SRC_DIR="/opt/cf-master-src"
WEB_ROOT="/var/www/html/cf-master"
rm -rf "${SRC_DIR}"
git clone -b "${BRANCH}" "${REPO_URL}" "${SRC_DIR}"
mkdir -p "${WEB_ROOT}"
cp -a "${SRC_DIR}/control-plane/." "${WEB_ROOT}/"
chown -R www-data:www-data "${WEB_ROOT}"
chmod -R 755 "${WEB_ROOT}"

if [[ -f "${WEB_ROOT}/sql/init.sql" ]]; then
  mysql "${DB_NAME}" < "${WEB_ROOT}/sql/init.sql"
else
  echo -e "${YELLOW}警告：未找到 sql/init.sql，执行内置兜底建表。${PLAIN}"
  mysql "${DB_NAME}" -e "
CREATE TABLE IF NOT EXISTS nodes (id int AUTO_INCREMENT PRIMARY KEY, hostname varchar(100), ip_address varchar(45), secret_key varchar(64) UNIQUE, status tinyint DEFAULT 1, last_heartbeat int DEFAULT 0, cpu_usage float DEFAULT 0, ram_usage float DEFAULT 0, traffic_limit int DEFAULT 0, traffic_used bigint DEFAULT 0, current_bandwidth int DEFAULT 0, max_bandwidth int DEFAULT 0, weight int DEFAULT 100);
CREATE TABLE IF NOT EXISTS domains (id int AUTO_INCREMENT PRIMARY KEY, domain varchar(255) UNIQUE, node_group_id int DEFAULT 0, ssl_status tinyint DEFAULT 0, created_at timestamp DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS settings (key_name varchar(50) PRIMARY KEY, value_json json);
CREATE TABLE IF NOT EXISTS certificates (id int AUTO_INCREMENT PRIMARY KEY, domain varchar(255) UNIQUE, cert_body text, key_body text, expire_time int DEFAULT 0, status tinyint DEFAULT 0, dns_challenge varchar(255), created_at timestamp DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS cf_ip_pool (id int AUTO_INCREMENT PRIMARY KEY, ip_address varchar(45) UNIQUE, status tinyint DEFAULT 1, latency int DEFAULT 0, fail_count int DEFAULT 0, last_check int DEFAULT 0);
CREATE TABLE IF NOT EXISTS node_alerts (id int AUTO_INCREMENT PRIMARY KEY, node_id int, node_name varchar(100), type varchar(20), value varchar(50), message text, is_read tinyint DEFAULT 0, created_at timestamp DEFAULT CURRENT_TIMESTAMP);
INSERT IGNORE INTO cf_ip_pool (ip_address) VALUES ('104.16.123.96'),('172.64.80.1'),('162.159.128.1');
INSERT IGNORE INTO settings (key_name, value_json) VALUES ('admin_user', '"admin"'), ('admin_pass', '"admin123"'), ('admin_slug', '"yun123"'), ('cf_ips', '["104.16.123.96"]'), ('dns_provider', '"cloudflare"'), ('cf_email', '""'), ('cf_key', '""'), ('cf_zone_id', '""'), ('cf_record_name', '"cdn"');
"
fi

mysql "${DB_NAME}" -e "ALTER TABLE nodes ADD COLUMN IF NOT EXISTS current_bandwidth int DEFAULT 0 COMMENT '当前实时带宽Mbps';"

echo -e "${GREEN}4/8 配置 Nginx...${PLAIN}"
PHP_SOCK="$(ls /run/php/php*-fpm.sock | head -n 1)"
if [[ -z "${PHP_SOCK}" ]]; then
  echo -e "${RED}未找到 PHP-FPM socket，请检查 php-fpm 是否安装成功。${PLAIN}"
  exit 1
fi

cat > /etc/nginx/conf.d/cf-master.conf <<NGINX
server {
    listen 8080;
    server_name _;

    root ${WEB_ROOT};
    index admin.php index.php;

    location / {
        try_files \$uri \$uri/ /admin.php?slug=\$uri;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param CF_MASTER_DB_HOST 127.0.0.1;
        fastcgi_param CF_MASTER_DB_NAME ${DB_NAME};
        fastcgi_param CF_MASTER_DB_USER ${DB_USER};
        fastcgi_param CF_MASTER_DB_PASS ${DB_PASS};
    }

    location ~ /\.(git|env|yml) {
        deny all;
    }

    location ~ /(db\.php|database\.sql|sql/init\.sql|cron_dns\.php|monitor_cf\.php) {
        deny all;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
systemctl enable --now php*-fpm mariadb nginx >/dev/null 2>&1 || true
systemctl restart php*-fpm >/dev/null 2>&1 || true
systemctl restart nginx

echo -e "${GREEN}5/8 配置自动 DNS 调度任务...${PLAIN}"
(crontab -l 2>/dev/null | grep -v "cron_dns.php" | grep -v "monitor_cf.php" || true) | crontab -
(crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php ${WEB_ROOT}/cron_dns.php >> /var/log/cf-dns.log 2>&1"; echo "* * * * * /usr/bin/php ${WEB_ROOT}/monitor_cf.php >> /var/log/cf-monitor.log 2>&1") | crontab -

echo -e "${GREEN}6/8 安装 acme.sh 并初始化无邮箱 Let\'s Encrypt 账号...${PLAIN}"
curl https://get.acme.sh | sh
mkdir -p "${WEB_ROOT}/acme_tool" "${WEB_ROOT}/cert_data"

if [[ -d /root/.acme.sh ]]; then
  cp -a /root/.acme.sh/. "${WEB_ROOT}/acme_tool/"
else
  echo -e "${YELLOW}常规安装未生成 /root/.acme.sh，改用源码安装兜底...${PLAIN}"
  rm -rf /tmp/acme_install
  git clone https://github.com/acmesh-official/acme.sh.git /tmp/acme_install
  (cd /tmp/acme_install && ./acme.sh --install --force)
  if [[ ! -d /root/.acme.sh ]]; then
    echo -e "${RED}acme.sh 安装失败，请检查网络/系统环境。${PLAIN}"
    exit 1
  fi
  cp -a /root/.acme.sh/. "${WEB_ROOT}/acme_tool/"
fi

chown -R www-data:www-data "${WEB_ROOT}"
chmod +x "${WEB_ROOT}/acme_tool/acme.sh"

su -s /bin/bash -c "${WEB_ROOT}/acme_tool/acme.sh --set-default-ca --server letsencrypt --home ${WEB_ROOT}/cert_data" www-data
su -s /bin/bash -c "${WEB_ROOT}/acme_tool/acme.sh --register-account --server letsencrypt --home ${WEB_ROOT}/cert_data" www-data

echo -e "${GREEN}7/8 写入安装信息...${PLAIN}"
cat > /root/cf-master-install.txt <<INFO
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
WEB_ROOT=${WEB_ROOT}
REPO=${REPO_URL}
BRANCH=${BRANCH}
INFO

MY_IP="$(hostname -I | awk '{print $1}')"
if [[ -z "${MY_IP}" ]]; then
  MY_IP="$(curl -s4 ifconfig.me || true)"
fi

echo -e "${GREEN}8/8 完成${PLAIN}"
echo "=================================================="
echo -e "${GREEN}主控端安装完成！${PLAIN}"
echo "管理后台: http://${MY_IP}:8080/yun123"
echo "默认账号: admin"
echo "默认密码: admin123"
echo "数据库凭据保存于: /root/cf-master-install.txt"
echo "=================================================="
