USE cf_proxy_master;

ALTER TABLE nodes ADD COLUMN IF NOT EXISTS traffic_limit_enable tinyint DEFAULT 0 COMMENT '流量限制开关';
ALTER TABLE nodes ADD COLUMN IF NOT EXISTS traffic_count_mode tinyint DEFAULT 0 COMMENT '0双向 1单向';
ALTER TABLE nodes ADD COLUMN IF NOT EXISTS traffic_alert_pct int DEFAULT 5 COMMENT '剩余流量暂停阈值%';
