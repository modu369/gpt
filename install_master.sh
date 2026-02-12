#!/bin/bash
# install_master.sh - 主控端全功能一键部署脚本 (V3.3 高可用版)
# 适配仓库: https://github.com/modu369/gpt
# 分支: codex/add-domain-level-traffic-statistics-report

# --- 错误处理 ---
set -e
trap 'echo -e "${RED}❌ 错误：脚本执行失败，请检查上方报错信息。${PLAIN}"' ERR

RED='\033[0;31m'
GREEN='\033[0;32m'
PLAIN='\033[0m'

if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}错误：必须使用 root 用户运行此脚本！${PLAIN}"
   exit 1
fi

# 1. 安装基础环境
echo -e "${GREEN}1/8 安装基础环境...${PLAIN}"
apt update -y
apt install -y nginx php-fpm php-mysql php-curl php-xml mariadb-server git unzip curl cron socat

systemctl enable cron && systemctl start cron
systemctl enable mariadb && systemctl start mariadb

PHP_VER=$(php -v | head -n 1 | cut -d " " -f 2 | cut -f1-2 -d".")

# 2. 配置数据库
echo -e "${GREEN}2/8 配置数据库...${PLAIN}"
echo "正在等待数据库启动..."
for i in {1..30}; do
    if mysqladmin ping --silent; then break; fi
    sleep 2
done

DB_PASS=$(openssl rand -hex 8)
DB_NAME="cf_proxy_master"
DB_USER="cf_master"

mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# 3. 拉取源码
echo -e "${GREEN}3/8 拉取源码...${PLAIN}"
WEB_ROOT="/var/www/html/cf-master"
mkdir -p ${WEB_ROOT}
rm -rf /tmp/repo_clone

git clone -b codex/add-domain-level-traffic-statistics-report https://github.com/modu369/gpt.git /tmp/repo_clone
cp -r /tmp/repo_clone/* ${WEB_ROOT}/
chown -R www-data:www-data ${WEB_ROOT}
chmod -R 755 ${WEB_ROOT}

# 4. 导入 SQL
echo -e "${GREEN}4/8 初始化数据表...${PLAIN}"
mysql ${DB_NAME} -e "
CREATE TABLE IF NOT EXISTS nodes (
    id int AUTO_INCREMENT PRIMARY KEY, 
    hostname varchar(100), 
    ip_address varchar(45), 
    secret_key varchar(64) UNIQUE, 
    status tinyint DEFAULT 1, 
    last_heartbeat int DEFAULT 0, 
    cpu_usage float DEFAULT 0, 
    ram_usage float DEFAULT 0,
    traffic_limit int DEFAULT 0,
    traffic_used bigint DEFAULT 0,
    weight int DEFAULT 100,
    max_bandwidth int DEFAULT 0,
    current_bandwidth int DEFAULT 0
);
CREATE TABLE IF NOT EXISTS node_alerts (
  id int AUTO_INCREMENT PRIMARY KEY,
  node_id int,
  node_name varchar(100),
  type varchar(20),
  value varchar(50),
  message text,
  is_read tinyint DEFAULT 0,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS domains (id int AUTO_INCREMENT PRIMARY KEY, domain varchar(255) UNIQUE, created_at timestamp DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS settings (key_name varchar(50) PRIMARY KEY, value_json json);
CREATE TABLE IF NOT EXISTS certificates (id int AUTO_INCREMENT PRIMARY KEY, domain varchar(255) UNIQUE, cert_body text, key_body text, expire_time int DEFAULT 0, status tinyint DEFAULT 0, dns_challenge varchar(255), created_at timestamp DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS cf_ip_pool (id int AUTO_INCREMENT PRIMARY KEY, ip_address varchar(45) UNIQUE, status tinyint DEFAULT 1, latency int DEFAULT 0, fail_count int DEFAULT 0, last_check int DEFAULT 0);

INSERT IGNORE INTO settings (key_name, value_json) VALUES 
('admin_user', '"admin"'), ('admin_pass', '"admin123"'), ('admin_slug', '"yun123"'), ('cf_ips', '["104.16.123.96"]'), ('cf_email', '""'), ('cf_key', '""'), ('cf_zone_id', '""'), ('cf_record_name', '"cdn"');
"

# 5. db.php
echo -e "${GREEN}5/8 生成配置文件...${PLAIN}"
cat > ${WEB_ROOT}/db.php <<EOF2
<?php
declare(strict_types=1);
\$host = '127.0.0.1';
\$db   = '${DB_NAME}';
\$user = '${DB_USER}';
\$pass = '${DB_PASS}';
\$charset = 'utf8mb4';
\$dsn = "mysql:host=\$host;dbname=\$db;charset=\$charset";
\$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
try {\$pdo = new PDO(\$dsn, \$user, \$pass, \$options);} catch (\PDOException \$e) {http_response_code(500);echo json_encode(['error'=>'Database connection failed']);exit;}
EOF2

# 6. Nginx
echo -e "${GREEN}6/8 配置 Nginx...${PLAIN}"
cat > /etc/nginx/conf.d/cf-master.conf <<EOF2
server {
    listen 8080;
    server_name _;
    root ${WEB_ROOT};
    index admin.php index.php;
    location / { try_files \$uri \$uri/ /admin.php?slug=\$uri; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock; }
    location ~ /\.(git|env|yml) { deny all; }
    location ~ /(db.php|database.sql) { deny all; }
    location ~ /cron_dns.php { deny all; }
    location ~ /monitor_cf.php { deny all; }
}
EOF2
rm -f /etc/nginx/sites-enabled/default
systemctl restart nginx
systemctl restart php${PHP_VER}-fpm

# 7. Crontab
echo -e "${GREEN}7/8 配置自动任务...${PLAIN}"
(crontab -l 2>/dev/null | grep -v "cf-master") | crontab -
(crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php ${WEB_ROOT}/cron_dns.php >> /var/log/cf-dns.log 2>&1") | crontab -
(crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php ${WEB_ROOT}/monitor_cf.php >> /var/log/cf-monitor.log 2>&1") | crontab -
(crontab -l 2>/dev/null; echo "0 0 1 * * mysql ${DB_NAME} -e 'UPDATE nodes SET traffic_used=0'") | crontab -

# 8. Acme.sh
echo -e "${GREEN}8/8 安装 SSL 工具...${PLAIN}"
curl https://get.acme.sh | sh
mkdir -p ${WEB_ROOT}/acme_tool ${WEB_ROOT}/cert_data
if [ -d "/root/.acme.sh" ]; then cp -r /root/.acme.sh/* ${WEB_ROOT}/acme_tool/; else git clone https://github.com/acmesh-official/acme.sh.git /tmp/acme_install && cd /tmp/acme_install && ./acme.sh --install --force && cp -r /root/.acme.sh/* ${WEB_ROOT}/acme_tool/; fi
chown -R www-data:www-data ${WEB_ROOT}
su -s /bin/bash -c "${WEB_ROOT}/acme_tool/acme.sh --register-account --server letsencrypt --home ${WEB_ROOT}/cert_data" www-data

MY_IP=$(curl -s4 ifconfig.me)
echo -e "=================================================="
echo -e "${GREEN}✅ 主控端 V3.3 高可用版 安装完成！${PLAIN}"
echo -e "管理后台: http://${MY_IP}:8080/yun123"
echo -e "账号: admin / 密码: admin123"
echo -e "=================================================="

