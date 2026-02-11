# Cloudflare Relay Orchestrator（主控 + 被控）

## 本次补全内容

- 华为云国际站 DNS 权重自动调度闭环
  - 被控端每 5 秒上报 CPU/内存/带宽指标到主控
  - 主控按资源压力计算权重
  - 主控可一键触发 `dns/reconcile` 自动更新华为云 DNS 记录
- 证书中心闭环
  - 主控证书记录可视化（状态、失败原因、重试次数）
  - 主控支持手动发起申请、失败重试
  - 被控端通过 `acme.sh` 自动申请并安装证书（HTTP 验证）
  - 申请成功自动 `reload haproxy`
- P2 性能与稳定性增强
  - Agent 上游转发改为全局 `httpx.AsyncClient` 连接池复用（keepalive）
  - CF IP 健康探测升级为三层探测（ICMP + TCP:443 + TCP:80），输出健康分数
  - DNS 自动调度新增防抖阈值（debounce）避免高频震荡
  - 提供 `scripts/benchmark_relay.sh` 基线压测脚本（direct vs relay）
- P3 可视化与运维增强
  - 超载中心支持筛选、分页、确认/备注、CSV 导出
  - 首页新增可关闭的超载横幅提示（会话记忆）
  - 节点卡片支持折叠展开并内置单节点操作按钮
  - 新增审计日志接口与页面：记录设置变更、流量策略变更、超载确认/清理

## 架构

用户 -> Relay 节点（HAProxy）-> Cloudflare IP -> 源站

- 主控：FastAPI + SQLite
- 被控：FastAPI + HAProxy + acme.sh

## 关键限制说明

- Cloudflare 非企业版无法做 TCP 层 Proxy Protocol 真实源地址透传。
- 本项目采用 HTTP 头透传：`X-Forwarded-For` + `X-Real-IP`。

## 快速安装

### 主控（Debian 12）

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/implement-management-backend-updates/scripts/install_controller.sh)
```

### 被控（Debian 12）

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/implement-management-backend-updates/scripts/install_agent.sh) \
  --controller http://<controller-ip>:8080 \
  --admin-path panel \
  --token <node-install-token> \
  --node-name relay-bj-01
```

## 访问入口

- 控制台入口：`http://<controller-ip>:8080/<admin_path>`（可视化后台登录页）
- API 基础：`http://<controller-ip>:8080/<admin_path>/api`
- API 文档：`http://<controller-ip>:8080/<admin_path>/api/docs`
- 健康检查：`http://<controller-ip>:8080/<admin_path>/healthz`

## 可视化后台能力

- 登录鉴权（JWT）
- 节点总览（CPU/内存/带宽）
- 节点列表
- 域名白名单管理
- Cloudflare IP 池管理
- 华为云 DNS 配置与调度执行
- 证书列表、手动申请与重试
- 一键同步配置到全部节点

## 主要 API

- 登录：`POST /{admin_path}/api/auth/login`
- 节点令牌：`POST /{admin_path}/api/node-tokens`
- 节点列表：`GET /{admin_path}/api/nodes`
- 同步：`POST /{admin_path}/api/sync`
- 资源总览：`GET /{admin_path}/api/dashboard/overview`
- DNS 配置：`POST /{admin_path}/api/dns/config`
- DNS 调度执行：`POST /{admin_path}/api/dns/reconcile`
- 证书列表：`GET /{admin_path}/api/certificates`
- 手动申请证书：`POST /{admin_path}/api/certificates/manual`
- 证书重试：`POST /{admin_path}/api/certificates/{domain}/retry`

## 华为云 DNS 配置

可用环境变量（主控服务）：

- `HUAWEI_AK`
- `HUAWEI_SK`
- `HUAWEI_REGION`（默认 `ap-southeast-1`）
- `HUAWEI_DNS_ZONE_ID`
- `HUAWEI_DNS_RECORDSET`

未配置 SDK 或 AK/SK 时将进入 dry-run，便于先联调流程。

## P2 运行参数（可选）

主控：

- `DNS_AUTO_RECONCILE=1`
- `CERT_DNS_V2=1`
- `UI_V2=1`（控制 P3 页面增强开关）

被控（连接池）：

- `UPSTREAM_TIMEOUT_S`（默认 `15`）
- `POOL_MAX_CONNECTIONS`（默认 `200`）
- `POOL_MAX_KEEPALIVE_CONNECTIONS`（默认 `80`）
- `POOL_KEEPALIVE_EXPIRY_S`（默认 `30`）

## 压测脚本

```bash
./scripts/benchmark_relay.sh \
  --direct https://origin.example.com/health \
  --relay  https://relay.example.com/health \
  --requests 50
```

输出包含 `avg` / `p95` / 失败数，可用于对比 relay 与直连差距。


## 常见排障

- 若浏览器提示“拒绝连接”，先在主控服务器执行：
  - `systemctl status cfrelay-controller`
  - `journalctl -u cfrelay-controller -n 100 --no-pager`
- 确认云厂商安全组/防火墙已放行 `TCP/8080`。
- 若日志出现 `bcrypt` / `password cannot be longer than 72 bytes`，请更新到最新安装脚本后重装主控（已改为 `pbkdf2_sha256`，不再依赖 bcrypt 后端）。
- 如果重装后仍看到旧的简陋说明页，先执行硬刷新（Ctrl+F5）并在服务器执行：`curl -fsSL http://127.0.0.1:8080/<admin_path> | head -n 5`，应看到 `CF Relay 管理后台登录`。
