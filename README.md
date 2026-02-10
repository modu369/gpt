# Cloudflare IP 转发系统（Master / Agent）

本版本重点：高性能转发 + 鉴权 + 真实IP透传 + 多节点调度控制 + 证书工单补齐。

## 1) Debian12 一键安装 / 卸载

### 主控安装
```bash
PROJECT_SUBDIR=develop-high-performance-cloudflare-ip-forwarding-system curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/develop-high-performance-cloudflare-ip-forwarding-system/scripts/install-master.sh | bash
```

### 被控安装（需主控 enrollment key）
```bash
MASTER_URL='http://<master-ip>:8080/<marker>' \
ENROLL_KEY='change-me' \
NODE_ID='relay-bj-01' \
PROJECT_SUBDIR=develop-high-performance-cloudflare-ip-forwarding-system \
curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/develop-high-performance-cloudflare-ip-forwarding-system/scripts/install-agent.sh | bash
```

### 卸载
```bash
curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/develop-high-performance-cloudflare-ip-forwarding-system/scripts/uninstall.sh | bash
```


> 安装脚本已内置多层容错：
> - 优先尝试 `REPO_BRANCH`；若不存在会自动回退默认分支，不会直接失败；
> - 自动识别项目目录（`$PROJECT_SUBDIR` / `codex/$PROJECT_SUBDIR` / 仓库根目录 / 自动扫描）；
> - 仅建议保留 `PROJECT_SUBDIR=develop-high-performance-cloudflare-ip-forwarding-system` 以加快定位。

## 2) 主控/被控分离 + 可视化后台

- 后台隐藏入口：`http://<ip>:<port>/<marker>/login`
- 登录成功后：`/<marker>/dashboard`
- 证书工单页：`/<marker>/certs`
- 可通过 systemd 环境变量定制端口、marker、管理员账号密码。

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

## 9) 证书管理（本轮补齐）

- 支持 HTTP/DNS 两种证书工单创建。
- HTTP工单：主控生成 challenge 文件并同步到所有 Agent，Agent 自动落盘到 `/.well-known/acme-challenge/`。
- DNS工单：主控生成 `_acme-challenge.<domain>` 与 TXT 值，用户配置后可在工单接口触发本地校验。
- 工单支持重试：触发后会同步到所有节点并再次尝试。
- Agent 仍支持 certbot webroot 自动申请与 renew（`AGENT_CERTBOT=1`）。

## 10) 华为云国际站 DNS 权重调度

- 提供配置与执行接口：`/api/admin/dns/huawei`
- 按节点负载计算权重，暂停或故障节点自动剔除。
- 当前采用可用的 endpoint 网关执行方式（问题1保持不变）。

详见 `docs/huawei-dns-weight-sync.md`。

## 11) 立即生效

- 主控配置下发通过 Agent 3 秒轮询获取，实时热更新，无需重启服务进程。
- 安装脚本会直接拉起 systemd 服务并立即生效。


项目地址：`https://github.com/modu369/gpt/tree/codex/develop-high-performance-cloudflare-ip-forwarding-system`
