# Cloudflare IP 转发系统（Master / Agent）

本项目现已从骨架版升级到 **v0.2 可用版**：
- 主控/被控分离部署；
- 节点注册鉴权 + 配置热同步；
- 域名白名单、真实 IP 透传、CF 后端健康检查+轮询；
- 基础可视化后台（隐藏路径 + 登录页 + 总览页）；
- 节点 CPU/内存/流量心跳、超载事件记录；
- 流量限额暂停策略（按单向/双向）；
- 证书任务状态回传（支持 certbot 模式）；
- 华为云 DNS 权重调度接口（计算和执行入口已打通，签名细节可继续对接）。

## Debian 12 一键部署

### Master
```bash
curl -fsSL https://raw.githubusercontent.com/your-org/cfrelay/main/scripts/install-master.sh | bash
```

### Agent（必须由主控签发）
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

## 后台入口

- `http://<master-ip>:<port>/<marker>/login`
- 登录后进入 `/<marker>/dashboard`

默认环境变量（可改 systemd）：
- `MASTER_LISTEN=:8080`
- `MASTER_MARKER=tianyun123`
- `MASTER_ADMIN_USER=admin`
- `MASTER_ADMIN_PASS=change-me`
- `MASTER_ENROLL_KEY=change-me`
- `MASTER_ADMIN_TOKEN=admin-change-me`

## API 摘要

### Agent -> Master
- `POST /<marker>/api/enroll`
- `GET /<marker>/api/node/config?node_id=...`
- `POST /<marker>/api/node/heartbeat`
- `POST /<marker>/api/node/cert`

### Admin
- `PUT /<marker>/api/admin/node`
- `GET /<marker>/api/admin/overview`
- `GET/POST /<marker>/api/admin/certs`
- `GET/PUT/POST /<marker>/api/admin/dns/huawei`

## 真实 IP 方案说明

Cloudflare 非企业版不支持标准 Proxy Protocol；当前节点透传：
- `X-Forwarded-For`
- `X-Real-IP`
- `X-Relay-Real-IP`

建议在 Cloudflare Worker / Transform Rule 中把上述字段回写到源站识别头。

## 证书说明

- 默认情况下不自动申请；
- 设置 `AGENT_CERTBOT=1` 后，Agent 首次看到允许域名会尝试 `certbot certonly --standalone`；
- 结果会上报主控证书管理接口。

## 华为云国际站 DNS

主控提供了权重计算与触发接口；可基于节点 CPU/内存/带宽占用自动计算权重，用于 CNAME 调度。
详见：`docs/huawei-dns-weight-sync.md`。
