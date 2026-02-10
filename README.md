# Cloudflare IP 转发系统（Master / Agent）

本版本重点：高性能转发 + 鉴权 + 真实IP透传 + 多节点调度控制。

## 1) Debian12 一键安装 / 卸载

### 主控安装
```bash
curl -fsSL https://raw.githubusercontent.com/your-org/cfrelay/main/scripts/install-master.sh | bash
```

### 被控安装（需主控 enrollment key）
```bash
MASTER_URL='http://<master-ip>:8080/<marker>' \
ENROLL_KEY='change-me' \
NODE_ID='relay-bj-01' \
curl -fsSL https://raw.githubusercontent.com/your-org/cfrelay/main/scripts/install-agent.sh | bash
```

### 卸载
```bash
curl -fsSL https://raw.githubusercontent.com/your-org/cfrelay/main/scripts/uninstall.sh | bash
```

## 2) 主控/被控分离 + 可视化后台

- 后台隐藏入口：`http://<ip>:<port>/<marker>/login`
- 登录成功后：`/<marker>/dashboard`
- 可通过 systemd 环境变量定制端口、marker、管理员账号密码。

默认环境变量（master）：
- `MASTER_LISTEN=:8080`
- `MASTER_MARKER=tianyun123`
- `MASTER_ADMIN_USER=admin`
- `MASTER_ADMIN_PASS=change-me`
- `MASTER_ENROLL_KEY=change-me`
- `MASTER_ADMIN_TOKEN=admin-change-me`

## 3) 流量链路和协议支持

- 链路：用户 -> 中转节点 Agent -> Cloudflare 优选IP -> 源站
- 端口：80/443（如配置 `AGENT_TLS_CERT` + `AGENT_TLS_KEY` 即启用 443 终止）

## 4) 白名单 + 鉴权

- Agent 按 Host 做白名单识别；非白名单直接拒绝。
- Agent 与 Master 采用 enroll token + bearer 鉴权。
- 管理 API 支持 `X-Admin-Token` 或网页登录会话。

## 5) 真实IP透传

Agent 转发注入：
- `X-Forwarded-For`
- `X-Real-IP`
- `X-Relay-Real-IP`

建议在 Cloudflare Worker/Transform Rule 回写到源站识别头。

## 6) 优选IP健康检查 / 负载均衡

- Agent 每 4 秒探测 CF 后端 443 存活和 RTT。
- 仅在 Alive 后端中轮询。
- 主控配置变更通过 Agent 3 秒轮询热同步生效（无需重启进程）。

## 7) 多节点监控、超载告警、调度

- Agent 上报 CPU/内存/实时带宽/累计流量。
- Dashboard 提供总览圆图（CPU/MEM/BW）和节点折叠卡片。
- 记录超载事件（CPU/MEM/BW），支持告警列表。
- 支持触发单节点重测速（主控下发请求，Agent 执行 speedtest-cli）。

## 8) 流量封顶策略

每节点支持：
- 是否开启流量限制
- 限制值（GB）
- 统计模式：`both` / `rx` / `tx`
- 剩余阈值（`TrafficWarnPercent`）及超阈暂停

主控按月自动重置暂停状态（恢复节点可用）。

## 9) 证书管理

- 首次访问允许域名触发证书任务。
- 支持 certbot webroot 申请 (`AGENT_CERTBOT=1`) 与自动 renew。
- 验证文件路径由 Agent 在 `/.well-known/acme-challenge/*` 提供。
- 证书任务状态同步到主控 `/api/admin/certs`。
- 管理员可 POST 新证书任务并触发 Agent 重试。

## 10) 华为云国际站 DNS 权重调度

- 提供配置与执行接口：`/api/admin/dns/huawei`
- 按节点负载计算权重，暂停或故障节点自动剔除。
- 可配置调度 CNAME。
- 支持向 `endpoint` 发送同步请求（携带 AK/SK header）。

详见 `docs/huawei-dns-weight-sync.md`。

## 11) 立即生效

- 主控配置下发通过 Agent 3 秒轮询获取，实时热更新，无需重启服务进程。
- 安装脚本会直接拉起 systemd 服务并立即生效。
