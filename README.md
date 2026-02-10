# Cloudflare IP 转发系统（Master/Agent）

> 这是一个可直接在 Debian 12 上一键部署的 **高性能中转原型**：主控与被控分离、白名单鉴权、80/443 转发、Cloudflare IP 池健康检查与故障切换、TLS证书接口预留与 BBR 优化。

## 架构

- 用户 -> Agent 中转节点 -> Cloudflare 优选 IP -> 源站。
- Master 负责：节点注册、配置下发、心跳汇总、后台入口隐藏标识。
- Agent 负责：域名白名单校验、反代转发、后端 CF IP 轮询 + 健康检查、证书自动申请。

## 快速部署（Debian 12）

### 1) 主控一键安装

```bash
curl -fsSL https://raw.githubusercontent.com/your-org/cfrelay/main/scripts/install-master.sh | bash
```

安装后编辑：

```bash
systemctl edit --full cfrelay-master
# 修改 MASTER_MARKER / MASTER_ENROLL_KEY / MASTER_ADMIN_TOKEN / MASTER_LISTEN
systemctl daemon-reload && systemctl restart cfrelay-master
```

后台入口示例：

- `http://<master-ip>:8080/<marker>/healthz`
- 节点注册接口：`POST /<marker>/api/enroll`

### 2) 被控节点一键安装（需主控签发）

```bash
MASTER_URL='http://70.39.198.10:8080/tianyun123' \
ENROLL_KEY='change-me' \
NODE_ID='relay-bj-01' \
curl -fsSL https://raw.githubusercontent.com/your-org/cfrelay/main/scripts/install-agent.sh | bash
```

### 3) 一键卸载

```bash
curl -fsSL https://raw.githubusercontent.com/your-org/cfrelay/main/scripts/uninstall.sh | bash
```

## 功能实现映射

- ✅ 主控/被控分离，配置由主控下发并 3 秒内同步生效（无重启）。
- ✅ 白名单：非白名单域名直接 403 拒绝。
- ✅ 80/443：支持 HTTP/HTTPS（提供证书路径后启用 443）。
- ✅ CF 优选 IP 池：4 秒探活，宕机自动摘除，恢复自动加入。
- ✅ 长连接复用：上游连接池 + keepalive。
- ✅ 安装 Agent 时自动启用 BBR。

## 关于“真实 IP 透传到 Cloudflare 再回源”

Cloudflare 非企业版不支持标准 Proxy Protocol 透传客户端 IP。当前方案在 Agent 注入：

- `X-Forwarded-For`
- `X-Real-IP`
- `X-Relay-Real-IP`

推荐在 Cloudflare Worker / Transform Rule 中把该值再写入回源头，实现业务层真实 IP 识别。

## 管理接口（示例）

- `PUT /<marker>/api/admin/node`：更新某节点配置（白名单、CF IP 池等）
- `GET /<marker>/api/admin/overview`：查看节点心跳汇总

请求头：`X-Admin-Token: <MASTER_ADMIN_TOKEN>`

## 华为云 DNS 权重调度

已提供调度设计说明：`docs/huawei-dns-weight-sync.md`。

你可在主控中根据 CPU/内存/带宽打分后调用华为云国际站 API 动态调 CNAME 权重，实现多节点智能分流。

## 当前版本边界（v0.1）

- 提供的是可运行核心链路与主控 API 原型；完整可视化大盘、证书工单页、华为云 API 实装、流量月包限额策略需在此基础上继续扩展。
- 所有配置下发均通过 Agent 轮询（3 秒）实现“近实时生效”，无需重启服务进程。
