# 华为云国际站 DNS 权重自动调度

## 功能

主控支持：
- `GET /<marker>/api/admin/dns/huawei` 查看配置
- `PUT /<marker>/api/admin/dns/huawei` 保存配置
- `POST /<marker>/api/admin/dns/huawei` 执行同步

## 权重算法

对每个节点计算：

`score = 100 - max(cpu%, mem%, bandwidth%)`

其中 `bandwidth% = current_bandwidth / max_bandwidth * 100`。

过滤规则：
- `score <= 0` 的节点剔除
- `node.config.paused = true` 的节点剔除

剩余节点的 score 作为权重。

## 自动化执行

主控后台协程每 12 秒执行一次：
1. 每月重置暂停状态（新月自动恢复节点）。
2. 重新计算节点权重。
3. 如果配置了 `endpoint`，向 endpoint POST 权重结果。

请求头：
- `X-Access-Key`
- `X-Secret-Key`

请求体示例：
```json
{
  "zone_id": "...",
  "recordset_id": "...",
  "scheduler_cname": "relay.example.com",
  "weights": {
    "relay-bj-01": 81,
    "relay-sh-01": 74
  }
}
```

> 说明：不同华为云账户/API网关可能需要额外签名流程，可在 endpoint 网关内完成签名转发。
