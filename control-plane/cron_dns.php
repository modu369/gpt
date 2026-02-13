<?php
// cron_dns.php - V9.1 (极速热更新版 TTL=1)
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

// 华为云驱动
class HuaweiDriver {
    private $ak, $sk, $zid, $reg;
    public function __construct($a, $s, $z, $r) { 
        $this->ak = $a; $this->sk = $s; $this->zid = $z; $this->reg = $r; 
        debug("初始化华为云驱动: Region={$r}");
    }
    
    public function sync($name, $active_nodes) {
        // 1. 提取所有合格节点的 IP
        $ip_list = [];
        foreach ($active_nodes as $n) {
            $ip_list[] = $n['ip'];
        }

        // 2. 构造数据包
        $payload = [
            'zone_id' => $this->zid, 
            'region' => $this->reg,
            'record_name' => $name,
            'record_type' => 'A',
            'record_ips' => $ip_list, // 这里包含了所有存活的IP
            'ttl' => 1 // [用户要求] 极速生效
        ];

        if (empty($ip_list)) {
            debug("警告: 没有可用节点，为防止断网，跳过更新操作");
            return;
        }
        
        $tmp = '/tmp/hw_dns.json';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        debug("当前存活 IP 列表 (" . count($ip_list) . "个): " . implode(", ", $ip_list));

        // 3. 调用 Python 执行热更新
        $cmd = "export CLOUD_SDK_AK='" . addslashes($this->ak) . "' && export CLOUD_SDK_SK='" . addslashes($this->sk) . "' && python3 " . escapeshellarg(__DIR__ . '/hw_dns_pusher.py') . ' ' . escapeshellarg($tmp) . " 2>&1";
        
        echo shell_exec($cmd);
    }
}

// === 主逻辑 ===
debug("开始智能调度...");

$provider = get_conf($pdo, 'dns_provider');
$rec_name = get_conf($pdo, 'cf_record_name');
if (!$rec_name || $provider !== 'huaweicloud') {
    debug("未配置域名或非华为云模式，退出。");
    exit;
}

$nodes = $pdo->query("SELECT * FROM nodes WHERE status=1")->fetchAll();
$active_group = [];

foreach ($nodes as $n) {
    // 1. 离线剔除 (65秒无心跳)
    if (time() - (int)$n['last_heartbeat'] > 65) continue;

    // 2. 流量耗尽剔除
    if ((int)($n['traffic_limit_enable']??0) === 1 && (int)($n['traffic_limit']??0) > 0) {
        $usedPct = ((float)$n['traffic_used'] / ((int)$n['traffic_limit']*1073741824)) * 100;
        if ((100 - $usedPct) < (int)($n['traffic_alert_pct']??5)) continue;
    }

    // 3. 简单权重筛选 (权重设为0的直接剔除)
    if (intval($n['weight']) > 0) {
        $active_group[] = ['ip' => $n['ip_address']];
    }
}

// 4. 兜底 (如果全挂了，尝试复活所有最近5分钟有心跳的)
if (empty($active_group)) {
    debug("⚠️ 触发兜底: 恢复所有节点");
    foreach ($nodes as $n) {
        if (time() - (int)$n['last_heartbeat'] < 300) $active_group[] = ['ip' => $n['ip_address']];
    }
}

$driver = new HuaweiDriver((string)get_conf($pdo, 'hw_ak'), (string)get_conf($pdo, 'hw_sk'), (string)get_conf($pdo, 'hw_zone_id'), (string)get_conf($pdo, 'hw_region'));
$driver->sync($rec_name, $active_group);

debug("调度结束.");
?>
