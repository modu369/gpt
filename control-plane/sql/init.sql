CREATE DATABASE IF NOT EXISTS cf_proxy_master;
USE cf_proxy_master;

CREATE TABLE IF NOT EXISTS `nodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL COMMENT '节点名称',
  `ip_address` varchar(45) NOT NULL COMMENT '节点公网IP',
  `secret_key` varchar(64) NOT NULL COMMENT '通讯密钥',
  `status` tinyint(1) DEFAULT 1 COMMENT '1启用 0停用',
  `last_heartbeat` int(11) DEFAULT 0 COMMENT '最后心跳时间戳',
  `cpu_usage` float DEFAULT 0 COMMENT 'CPU占用率',
  `ram_usage` float DEFAULT 0 COMMENT '内存占用率',
  `traffic_limit` int(11) DEFAULT 0 COMMENT '流量限制(GB)',
  `traffic_used` bigint(20) DEFAULT 0 COMMENT '已用流量(Bytes)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `secret_key` (`secret_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL COMMENT '用户绑定的域名',
  `node_group_id` int(11) DEFAULT 0 COMMENT '分组ID(预留)',
  `ssl_status` tinyint(1) DEFAULT 0 COMMENT 'SSL证书状态',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` varchar(50) NOT NULL,
  `value_json` json NOT NULL,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL COMMENT '证书域名',
  `cert_body` text COMMENT '公钥内容 (fullchain.cer)',
  `key_body` text COMMENT '私钥内容 (domain.key)',
  `expire_time` int(11) DEFAULT 0 COMMENT '过期时间戳',
  `status` tinyint(1) DEFAULT 0 COMMENT '0:待验证 1:已签发',
  `dns_challenge` varchar(255) DEFAULT '' COMMENT '待验证的TXT记录值',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `nodes` (`id`, `hostname`, `ip_address`, `secret_key`, `status`, `last_heartbeat`, `cpu_usage`, `ram_usage`, `traffic_limit`, `traffic_used`)
VALUES (1, 'Node-01-US', '1.2.3.4', 'my-secret-token-123', 1, 0, 0, 0, 0, 0);

INSERT IGNORE INTO `domains` (`id`, `domain`, `node_group_id`, `ssl_status`)
VALUES (1, 'test.yourdomain.com', 0, 0), (2, 'api.client.com', 0, 0);

INSERT INTO `settings` (`key_name`, `value_json`)
VALUES ('cf_ips', JSON_ARRAY('104.16.123.96', '172.64.80.1', '162.159.128.1'))
ON DUPLICATE KEY UPDATE value_json = VALUES(value_json);

INSERT INTO `settings` (`key_name`, `value_json`) VALUES
('admin_user', JSON_QUOTE('admin')),
('admin_pass', JSON_QUOTE('admin123')),
('admin_slug', JSON_QUOTE('yun123'))
ON DUPLICATE KEY UPDATE value_json = VALUES(value_json);


INSERT INTO `settings` (`key_name`, `value_json`) VALUES
('dns_provider', JSON_QUOTE('cloudflare')),
('cf_email', JSON_QUOTE('')),
('cf_key', JSON_QUOTE('')),
('cf_zone_id', JSON_QUOTE('')),
('cf_record_name', JSON_QUOTE('cdn'))
ON DUPLICATE KEY UPDATE value_json = VALUES(value_json);
