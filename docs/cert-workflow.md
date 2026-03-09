# 证书工单工作流（HTTP / DNS）

## 目标
补齐：
1. 跨所有节点同步 HTTP-01 challenge 文件。
2. 工单式 DNS 验证流程（生成记录值、用户配置、主控本地校验、重试）。

## Master 端接口

- `GET /<marker>/api/admin/certs`：工单列表。
- `POST /<marker>/api/admin/certs`：创建工单。
  - body: `{"domain":"example.com","method":"http|dns"}`
- `POST /<marker>/api/admin/certs/verify?domain=example.com`：执行本地校验。
- `POST /<marker>/api/admin/certs/retry?domain=example.com`：重试并广播到所有节点。

## HTTP 工单

- 创建工单时，Master 生成 token/content。
- Master 将 challenge 文件写入所有节点配置 `acme_challenge_files`。
- Agent 在配置同步时把 challenge 文件落盘到：
  - `<AGENT_ACME_WEBROOT>/.well-known/acme-challenge/<token>`
- Agent 的 HTTP 80 服务可直接访问 challenge 文件。

## DNS 工单

- Master 生成：
  - `dns_name = _acme-challenge.<domain>`
  - `dns_value = cfrealy-<random>`
- 用户在 DNS 平台配置 TXT 记录后，触发 verify。
- Master 本地 `LookupTXT` 校验通过则更新状态为 `local_verified`。

## 备注

- 工单状态会保存在 Master 的 `certs` 集合，并可在页面 `/<marker>/certs` 查看。
- 该流程与 Agent 自动 certbot 申请机制兼容。
