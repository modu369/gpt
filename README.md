# Cloudflare CN Relay

该仓库提供一个“主控 + 被控转发节点”分离的 Cloudflare 反向代理系统参考实现，目标是在中国大陆使用高质量线路中转，加速 Cloudflare 访问并保障安全性。

> 说明：本项目默认使用 **HAProxy** 作为转发内核，具备高性能、低延迟、连接复用和热更新能力。主控提供可视化管理后台，被控节点通过拉取配置并即时更新 HAProxy 运行时配置实现“修改立刻生效”。

## 功能概览

- **HTTP/HTTPS 双端口**转发（80/443）。
- **域名白名单**：非白名单域名直接拒绝。
- **真实 IP 透传**：通过 `X-Forwarded-For` 注入用户真实 IP。
- **优选 IP + 负载均衡**：支持多个 Cloudflare IP，自动轮询与健康检查。
- **连接复用**：Relay 与 Cloudflare 后端保持长连接，显著降低 TLS 开销。
- **控制/被控分离**：控制面统一管理，Relay 节点自动同步配置。
- **Debian 12 一键部署**：主控与被控分别提供安装与卸载脚本。
- **节点监控**：Agent 上报负载、内存与流量计数，便于调度。

## 目录结构

```
control-plane/   # 主控后台 (Flask + SQLite)
agent/           # 被控转发节点 Agent
configs/         # HAProxy 模板与映射
scripts/         # 一键安装/卸载脚本
```

## 快速开始

### 1. 主控端安装（Debian 12）

```bash
curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/develop-high-performance-cloudflare-proxy-system/scripts/install-control.sh | \
  REPO_URL=https://github.com/modu369/gpt.git \
  REPO_REF=codex/develop-high-performance-cloudflare-proxy-system bash
```

安装完成后访问：`http://<控制机IP>:8080`

默认账号密码在安装日志中输出，也可通过环境变量配置。

### 2. 被控端安装（Debian 12）

登录主控后台，生成该节点的安装令牌 (Enroll Token)，在被控机器上执行：

```bash
curl -fsSL https://raw.githubusercontent.com/modu369/gpt/codex/develop-high-performance-cloudflare-proxy-system/scripts/install-agent.sh | \
  REPO_URL=https://github.com/modu369/gpt.git \
  REPO_REF=codex/develop-high-performance-cloudflare-proxy-system bash -s -- \
  --control-url http://<控制机IP>:8080 \
  --token <ENROLL_TOKEN>
```

### 3. 管理与配置

- 在后台添加 **域名白名单**
- 配置 **Cloudflare 优选 IP 池**
- Agent 会自动同步，HAProxy 使用运行时 socket 热更新，无需重启服务
- 控制台可查看 Relay 节点的负载与流量指标

## 运行原理（简述）

- 用户 → Relay → Cloudflare 优选 IP → 源站
- HTTPS 采用 SNI 识别域名，Relay 终止 TLS 并在转发时注入 `X-Forwarded-For`
- Relay 到 Cloudflare 后端使用连接池 + keepalive

## 安全建议

- 控制端建议放置在内网或加防火墙 ACL
- 控制端登录密码请改为强密码
- 若域名较多建议使用 DNS API 自动签发通配证书

## 免责声明

本项目为参考实现，需要结合业务场景进行安全与合规评估。
