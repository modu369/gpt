# 运维说明

## 证书管理

Relay 节点需要终止 TLS 以注入 `X-Forwarded-For`，建议使用 ACME 自动签发证书：

- 推荐使用 `acme.sh` 或 `certbot` 配合 DNS API 自动签发通配证书。
- 将证书放入 `/etc/haproxy/certs/` 目录，文件格式为 `fullchain.pem + privkey.pem` 拼接。

示例：

```bash
cat fullchain.pem privkey.pem > /etc/haproxy/certs/example.com.pem
```

## 即时生效机制

- 域名白名单与 Cloudflare IP 池由 Agent 定期拉取配置。
- Agent 使用 HAProxy Runtime API (`/run/haproxy/admin.sock`) 动态更新，不需要重启进程。
- Agent 会上报 `loadavg / 内存 / RX-TX` 指标，便于主控端调度和监控。

## 性能建议

- 配置 `net.core.somaxconn` 与 `fs.file-max` 以支持高并发。
- Relay 节点建议使用 CN2/9929 等优质线路。
- 可通过设置 `NET_IFACE=eth0` 指定监控的网卡。
