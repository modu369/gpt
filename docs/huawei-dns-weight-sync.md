# 华为云国际站 DNS 权重自动调度

## 已实现能力

主控 `POST /<marker>/api/admin/dns/huawei` 可执行一次调度计算：

1. 汇总每个节点实时负载（CPU/内存/带宽占比）。
2. 计算 `score = 100 - max(cpu, mem, bwPercent)`。
3. `score <= 0` 或 `node.paused=true` 的节点不参与解析池。
4. 输出各节点权重映射，供华为云 DNS Recordset 更新。

## 配置接口

- `GET /api/admin/dns/huawei`：查看配置。
- `PUT /api/admin/dns/huawei`：保存配置。
- `POST /api/admin/dns/huawei`：执行一次同步并返回权重结果。

配置字段：
- `enabled`
- `endpoint`
- `zone_id`
- `recordset_id`
- `access_key`
- `secret_key`
- `scheduler_cname`

## 下一步（可继续）

- 补充华为云 API HMAC 签名请求。
- 将权重计算结果写入 A/AAAA/CNAME Recordset 的 `weight`。
- 增加失败重试与回滚。
