# 华为云国际站 DNS 权重自动调度（示例）

主控可根据节点负载生成权重，并调用华为云 DNS API 更新某 CNAME 记录集合。

流程：
1. 主控读取各节点 CPU/内存/带宽占用。
2. 计算健康分：`score = 100 - max(cpu%, mem%, bw%)`。
3. score <= 0 的节点从解析池移除。
4. 将 score 归一化为权重，调用 API 更新。

> 说明：该仓库当前提供接口预留，实际对接时请填入 IAM AK/SK、ZoneID、RecordSetID。
