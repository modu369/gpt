# CF Proxy

当前仓库包含两部分：

- **Phase 2 / Control Plane (PHP + MySQL)**：主控端 API 与配置中心。
- **Phase 3 / Data Plane (Golang)**：被控端网关，启动后通过 API 拉取配置并心跳上报，支持热更新。

## Control Plane (PHP API)

目录：`control-plane/`

- `control-plane/sql/init.sql`：初始化数据库与测试数据。
- `control-plane/db.php`：PDO 连接（支持环境变量）。
- `control-plane/api/get_config.php`：节点拉取配置接口。
- `control-plane/api/heartbeat.php`：节点心跳上报接口。

### 1) 初始化数据库

```bash
mysql -uroot -p < control-plane/sql/init.sql
```

### 2) 部署 API 到 Web 根目录

例如部署到 `/var/www/html/cf-master`：

```bash
mkdir -p /var/www/html/cf-master
cp -r control-plane/* /var/www/html/cf-master/
```

### 3) 配置数据库连接

支持以下环境变量（推荐）：

- `CF_MASTER_DB_HOST`
- `CF_MASTER_DB_NAME`
- `CF_MASTER_DB_USER`
- `CF_MASTER_DB_PASS`

未设置时默认使用：`127.0.0.1 / cf_proxy_master / root / password`。

### 4) 用 curl 验证配置接口

```bash
curl -H "X-Node-Secret: my-secret-token-123" \
  http://localhost/cf-master/api/get_config.php -v
```


### 5) 启用 Web 管理后台（admin.php）

已提供单文件后台：`control-plane/admin.php`。部署时同步拷贝到 Web 根目录即可：

```bash
cp control-plane/admin.php /var/www/html/cf-master/admin.php
```

访问：`http://<your-host>/cf-master/admin.php`

- 默认密码：`admin_password_123`（可通过环境变量 `CF_MASTER_ADMIN_PASSWORD` 覆盖）。
- 功能：节点增删与在线状态、域名白名单增删、CF 优选 IP 池在线编辑。

## Data Plane (Golang, Phase 3)

> 已废弃本地 `config.json` 模式，节点必须通过主控 API 启动。

### 启动

```bash
go run ./cmd/cf-proxy \
  -master http://1.2.3.4/cf-master/api \
  -secret my-secret-token-123
```

可选参数：

- `-heartbeat-interval`：心跳/配置拉取周期，默认 `10s`。

### 运行行为

- 启动时先调用 `get_config.php`，首次配置获取失败则拒绝启动。
- 每个心跳周期：
  1. 向 `heartbeat.php` 上报内存占用与 goroutine 数；
  2. 重新调用 `get_config.php`；
  3. 若 `timestamp` 变大则热更新白名单与 CF IP 池（无需重启进程）。
- 请求转发时：白名单外域名返回 403；白名单内域名按轮询选择 CF IP，并使用目标域名作为 TLS SNI。
- 立即生效（Push）：节点额外监听 `:7777/reload`（仅接受携带 `X-Node-Secret` 的 POST）。管理后台保存配置后会并发广播此接口，实现毫秒级热更新。
- 防火墙：请在被控端放行 `7777/tcp`（仅允许主控端来源访问）。


## Nginx 建议配置（8080 + 隐藏入口）

可按如下思路部署（示例）：

```nginx
server {
    listen 8080;
    server_name _;

    root /var/www/html/cf-master;
    index admin.php index.php;

    location / {
        try_files $uri $uri/ /admin.php?slug=$uri;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

修改后执行：

```bash
systemctl restart nginx
```

默认安全入口：`http://<主控IP>:8080/yun123`。



## 证书中心（手动 DNS 验证）

### 主控端准备 acme.sh

```bash
apt install -y cron socat
systemctl enable --now cron
curl https://get.acme.sh | sh
mkdir -p /var/www/html/cf-master/cert_data
chown -R www-data:www-data /var/www/html/cf-master/cert_data
cp -r /root/.acme.sh /var/www/html/cf-master/acme_tool
chown -R www-data:www-data /var/www/html/cf-master/acme_tool
chmod +x /var/www/html/cf-master/acme_tool/acme.sh
su -s /bin/bash -c "/var/www/html/cf-master/acme_tool/acme.sh --set-default-ca --server letsencrypt --home /var/www/html/cf-master/cert_data" www-data
su -s /bin/bash -c "/var/www/html/cf-master/acme_tool/acme.sh --register-account --server letsencrypt --home /var/www/html/cf-master/cert_data" www-data
```

> 为避免默认 ZeroSSL 触发邮箱要求，后台调用 `acme.sh` 时会强制追加 `--server letsencrypt --home /var/www/html/cf-master/cert_data`。

### 证书下发流程

1. 后台“证书中心”提交域名，获取 DNS TXT 值。
2. 在 DNS 服务商添加 `_acme-challenge` TXT。
3. 回到后台点击“验证并签发”。
4. 证书入库后通过 `get_config.php` 下发到节点，节点通过 push/poll 自动热更新内存证书。

## 一键安装脚本

### 主控端（Debian 12）

```bash
wget -O install_master.sh https://raw.githubusercontent.com/modu369/gpt/codex/add-domain-level-traffic-statistics-report/install_master.sh && chmod +x install_master.sh && bash install_master.sh
```

### 被控端（由后台自动生成）

```bash
curl -O https://raw.githubusercontent.com/modu369/gpt/codex/add-domain-level-traffic-statistics-report/install_node.sh && chmod +x install_node.sh && ./install_node.sh -master http://<主控IP>:8080/api -secret <节点密钥>
```

### 被控端卸载

```bash
bash install_node.sh --uninstall
```

## DNS 智能调度（Cloudflare + 95%流量阈值）

已提供 `control-plane/cron_dns.php`，建议通过 crontab 每分钟运行一次：

```cron
* * * * * /usr/bin/php /var/www/html/cf-master/cron_dns.php >> /var/log/cf-dns.log 2>&1
```

调度规则：
- 仅纳入 `status=1` 且 60 秒内有心跳的节点。
- 若节点设置了 `traffic_limit`，当 `traffic_used` 达到 95% 阈值会自动从 DNS 池移除。
- 会同步 Cloudflare A 记录，删除不健康节点 IP、添加恢复健康节点 IP。
