<?php
// cron_dns.php - V9.3 (智能负载均衡 + 告警中心集成 + 单节点兜底保护)
require_once __DIR__ . '/db.php';

function get_conf($pdo, $k) {
    $s = $pdo->prepare("SELECT value_json FROM settings WHERE key_name=?");
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v ? json_decode($v, true) : '';
}

function debug($msg) {
    echo "[" . date('H:i:s') . "] $msg\n";
}

// [新增] 写入告警到数据库 (带防刷屏逻辑)
function check_and_alert($pdo, $node, $type, $valStr, $msg) {
    // 1. 检查是否已有同类型未读告警 (防止每分钟刷屏)
    $stmt = $pdo->prepare("SELECT id FROM node_alerts WHERE node_id=? AND type=? AND is_read=0 LIMIT 1");
    $stmt->execute([$node['id'], $type]);
    if ($stmt->fetch()) {
        return; // 已有未读告警，跳过
    }

    // 2. 写入新告警
    debug("!!! 触发告警: {$node['hostname']} - $msg");
    $stmt = $pdo->prepare("INSERT INTO node_alerts (node_id, node_name, type, value, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$node['id'], $node['hostname'], $type, $valStr, $msg]);
}

// 华为云驱动
class HuaweiDriver {
    private $ak, $sk, $zid, $reg;
    public function __construct($a, $s, $z, $r) { 
        $this->ak = $a; $this->sk = $s; $this->zid = $z; $this->reg = $r; 
        debug("初始化华为云驱动: Region={$r}");
    }
    
    public function sync($name, $active_nodes) {
        $ip_list = [];
        foreach ($active_nodes as $n) {
            $ip_list[] = $n['ip'];
        }

        $payload = [
            'zone_id' => $this->zid, 
            'region' => $this->reg,
            'record_name' => $name,
            'record_type' => 'A',
            'record_ips' => $ip_list, 
            'ttl' => 1 
        ];

        // 这里的 empty check 理论上不会触发，因为外面有兜底，但保留作为最后防线
        if (empty($ip_list)) {
            debug("⚠️ 严重: 解析列表为空，为防止断网，跳过 API 更新");
            return;
        }
        
        $tmp = '/tmp/hw_dns.json';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        debug("执行更新 -> IP列表: " . implode(", ", $ip_list));

        $cmd = "export CLOUD_SDK_AK='" . addslashes($this->ak) . "' && export CLOUD_SDK_SK='" . addslashes($this->sk) . "' && python3 " . escapeshellarg(__DIR__ . '/hw_dns_pusher.py') . ' ' . escapeshellarg($tmp) . " 2>&1";
        echo shell_exec($cmd);
    }
}

// === 主逻辑 ===
debug("Starting DNS scheduler (V9.3)...");

$provider = get_conf($pdo, 'dns_provider');
$rec_name = get_conf($pdo, 'cf_record_name');
if (!$rec_name || $provider !== 'huaweicloud') {
    debug("未配置域名或非华为云模式，退出。");
    exit;
}

$nodes = $pdo->query("SELECT * FROM nodes WHERE status=1")->fetchAll();
$active_group = [];

// === [配置] 负载均衡阈值 ===
$THRESHOLD_CPU = 90;      // CPU > 90%
$THRESHOLD_RAM_PCT = 95;  // 内存 > 95%
$THRESHOLD_BW_PCT = 95;   // 带宽 > 95%

foreach ($nodes as $n) {
    $name = $n['hostname'];
    
    // 1. 离线检测
    if (time() - (int)$n['last_heartbeat'] > 65) {
        debug("[$name] 排除: 离线 (Last heartbeat > 65s)");
        check_and_alert($pdo, $n, 'Offline', 'Down', '节点失去联系超过 65 秒');
        continue;
    }

    // 2. 流量耗尽检测
    if ((int)($n['traffic_limit_enable']??0) === 1 && (int)($n['traffic_limit']??0) > 0) {
        $usedPct = ((float)$n['traffic_used'] / ((int)$n['traffic_limit']*1073741824)) * 100;
        if ((100 - $usedPct) < (int)($n['traffic_alert_pct']??5)) {
            debug("[$name] 排除: 流量耗尽");
            check_and_alert($pdo, $n, 'Traffic', round($usedPct, 1).'%', '流量包已耗尽，节点暂停解析');
            continue;
        }
    }

    // 3. 权重检测
    if (intval($n['weight']) <= 0) {
        debug("[$name] 排除: 权重为 0");
        continue;
    }

    // === 4. 负载熔断检测 (带告警) ===
    
    // A. CPU
    if ($n['cpu_usage'] > $THRESHOLD_CPU) {
        debug("[$name] 熔断: CPU {$n['cpu_usage']}%");
        check_and_alert($pdo, $n, 'Load_CPU', $n['cpu_usage'].'%', "CPU 负载过高 (>{$THRESHOLD_CPU}%)，暂停解析");
        continue;
    }

    // B. 内存
    if ($n['max_ram'] > 0) {
        $ramPct = ($n['ram_usage'] / $n['max_ram']) * 100;
        if ($ramPct > $THRESHOLD_RAM_PCT) {
            debug("[$name] 熔断: RAM {$ramPct}%");
            check_and_alert($pdo, $n, 'Load_RAM', round($ramPct, 1).'%', "内存不足 (>{$THRESHOLD_RAM_PCT}%)，暂停解析");
            continue;
        }
    }

    // C. 带宽
    if ($n['max_bandwidth'] > 0) {
        $bwPct = ($n['current_bandwidth'] / $n['max_bandwidth']) * 100;
        if ($bwPct > $THRESHOLD_BW_PCT) {
            debug("[$name] 熔断: BW {$bwPct}%");
            check_and_alert($pdo, $n, 'Load_BW', $n['current_bandwidth'].'Mbps', "带宽已跑满 (>{$THRESHOLD_BW_PCT}%)，暂停解析。请考虑增加节点！");
            continue;
        }
    }

    // 通过所有检查
    $active_group[] = ['ip' => $n['ip_address']];
}

// === 5. 兜底保护 (防止断网) ===
// 如果所有节点都因为过载或离线被排除了，导致解析列表为空
if (empty($active_group)) {
    debug("⚠️ 紧急: 可用节点数为 0！触发兜底机制，强制启用所有在线节点。");
    
    // 插入一条系统级告警
    $stmt = $pdo->prepare("INSERT INTO node_alerts (node_name, type, message) SELECT 'System', 'Critical', '所有节点均不可用(过载或离线)，系统已强制恢复解析以维持服务！' FROM DUAL WHERE NOT EXISTS (SELECT id FROM node_alerts WHERE type='Critical' AND is_read=0 AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE))");
    $stmt->execute();

    foreach ($nodes as $n) {
        // 只要心跳还在(5分钟内)，就强制拉起来，不管负载多高
        if (time() - (int)$n['last_heartbeat'] < 300) {
            $active_group[] = ['ip' => $n['ip_address']];
        }
    }
}

$driver = new HuaweiDriver((string)get_conf($pdo, 'hw_ak'), (string)get_conf($pdo, 'hw_sk'), (string)get_conf($pdo, 'hw_zone_id'), (string)get_conf($pdo, 'hw_region'));
$driver->sync($rec_name, $active_group);

debug("完成.");
?>
