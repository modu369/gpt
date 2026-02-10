# Cloudflare Relay Orchestrator（主控+被控）

> 一个可一键部署的 **Cloudflare IP 转发（反代）控制平面 + 节点执行平面** 基线实现。

## 现阶段能力（本仓库实现）

- 主控端（Controller）
  - FastAPI + SQLite 可视化 API（可接前端）
  - 账号密码鉴权（JWT）
  - 自定义后台入口路径（`/your-tag`）
  - 节点注册令牌签发与节点管理
  - 域名白名单管理
  - Cloudflare 优选 IP 池管理
  - 将配置实时下发到节点（HTTP Push）
- 被控端（Agent）
  - 接收主控配置并本地原子落盘
  - 周期性健康检查 Cloudflare IP（TCP 检测）
  - 自动生成并热重载 HAProxy 配置
  - 支持 80/443 入站，按白名单放行
  - 对非白名单域名直接拒绝
  - 向上游传递 `X-Forwarded-For / X-Real-IP`
- 一键脚本
  - Debian 12 主控端安装 / 更新 / 卸载
  - Debian 12 被控端安装 / 更新 / 卸载
  - 被控端安装自动开启 BBR

## 重要说明（真实 IP）

Cloudflare 非企业版不支持标准 Proxy Protocol 透传客户端源地址。该实现采用 **HTTP 层头部透传**：

- Relay → Cloudflare 添加 `X-Forwarded-For` / `X-Real-IP`
- 你的源站需按业务信任链读取对应头部

> 对“端到端 TCP 级别真实源地址透传至 Cloudflare 边缘”场景，在非企业版条件下无法完全达成。

## 目录

- `controller/` 主控 API
- `agent/` 节点执行器 + HAProxy 生成器
- `scripts/` 一键安装/更新/卸载脚本

## 快速开始

### 1) 主控安装（Debian 12）

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/<your-org>/<your-repo>/main/scripts/install_controller.sh)
```

安装后默认：

- API: `http://<controller-ip>:8080/<ADMIN_PATH>/api`
- 初始账号密码由环境变量传入（脚本会要求填写）

### 2) 签发节点安装令牌

登录后调用：

```bash
POST /<ADMIN_PATH>/api/node-tokens
```

### 3) 被控安装（Debian 12）

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/<your-org>/<your-repo>/main/scripts/install_agent.sh) \
  --controller http://<controller-ip>:8080 \
  --token <node-install-token> \
  --node-name relay-bj-01
```

## API 概览（节选）

- `POST /{admin_path}/api/auth/login`
- `POST /{admin_path}/api/node-tokens`
- `GET /{admin_path}/api/nodes`
- `POST /{admin_path}/api/whitelist`
- `POST /{admin_path}/api/cf-ips`
- `POST /{admin_path}/api/sync`

## 华为云 DNS 权重调度

本仓库提供扩展点（`controller/services/huawei_dns.py`）用于接入华为云国际站 DNS API，
你可在同步任务里按节点 CPU/内存/带宽利用率动态调整权重，自动增删解析记录。

> 因每个租户 IAM/区域/权限模型不同，默认以可运行骨架 + 签名占位实现，落地时补齐 AK/SK 与 zone 配置即可。

## 证书策略

- 通过 `acme.sh` 预置 HTTP 验证目录
- 证书自动申请/续期任务入口已预留（`agent/cert_manager.py`）
- 未签发证书前，保持 challenge 路径可访问

## 性能建议

- HAProxy 使用 keep-alive、连接复用、健康检查
- 推荐内核参数 + BBR
- 生产建议上 OpenResty/HAProxy 多进程绑定 CPU，配合 eBPF 观测

## 免责声明

该项目为可扩展基础实现，建议在生产前进行压测、审计、故障演练与灰度发布。
