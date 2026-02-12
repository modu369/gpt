<?php
// cron_dns.php - 智能调度器 (V3.0 最终高可用版)
// 新增特性: 单节点豁免保护、全员过载兜底、扩容告警

require_once __DIR__ . '/db.php';

// 1. 获取配置
function get_conf($pdo, $k) {
    $s = $pdo->prepare("SELECT value_json FROM settings WHERE key_name=?");
    $s->execute([$k]); $v=$s->fetchColumn(); return $v?json_decode($v,true):'';
}

$CF_EMAIL = get_conf($pdo, 'cf_email');
$CF_KEY   = get_conf($pdo, 'cf_key');
$CF_ZONE  = get_conf($pdo, 'cf_zone_id');
$CF_NAME  = get_conf($pdo, 'cf_record_name');

if (!$CF_EMAIL || !$CF_KEY || !$CF_ZONE || !$CF_NAME) die("Cloudflare 配置不完整。\n");

// 2. 获取所有开启的节点
$nodes = $pdo->query("SELECT * FROM nodes WHERE status=1")->fetchAll();
$candidates = []; // 存活候选池 (只要活着且流量没欠费，就算候选)

echo "[" . date('Y-m-d H:i:s') . "] 开始高可用调度...\n";

// === 阶段一：硬性筛选 (离线/欠费) ===
foreach ($nodes as $node) {
    $name = $node['hostname'];
    
    // 1. 在线状态检查
    if (time() - $node['last_heartbeat'] > 60) {
        echo " - [$name] 🔴 离线 (跳过)\n";
        continue;
    }

    // 2. 月流量限额检查 (硬指标，超了必须停)
    $limit_bytes = $node['traffic_limit'] * 1024 * 1024 * 1024;
    if ($limit_bytes > 0 && $node['traffic_used'] > ($limit_bytes * 0.95)) {
        echo " - [$name] 🔴 月流量耗尽 (跳过)\n";
        continue;
    }

    // 加入候选池
    $candidates[] = $node;
}

$candidate_count = count($candidates);
$final_target_ips = [];

if ($candidate_count == 0) {
    die("❌ 严重错误：全网无可用节点 (全部离线或欠费)！停止 DNS 更新。\n");
}

// === 阶段二：负载策略决策 ===

if ($candidate_count == 1) {
    // 【场景 A：单节点保护模式】
    $node = $candidates[0];
    echo "🛡️ 单节点保护模式：仅有一台存活节点 [{$node['hostname']}]。\n";
    echo "   -> 忽略 CPU/带宽负载限制，强制解析，防止断网。\n";
    
    // 即使它 CPU 100% 也要上
    $final_target_ips[] = $node['ip_address'];
    
    // 如果它确实负载很高，还是记录一条警告给管理员看，但不下线
    check_load_and_alert($pdo, $node, true);

} else {
    // 【场景 B：多节点负载均衡模式】
    echo "⚖️ 多节点集群模式 (在线: $candidate_count) -> 启动智能筛选...\n";
    
    foreach ($candidates as $node) {
        // 执行严格的负载检查
        if (check_load_and_alert($pdo, $node, false)) {
            // 通过检查，根据权重随机入选
            $base_weight = intval($node['weight']);
            // 简单概率: 权重 100 -> 100% 入选
            if ($base_weight >= mt_rand(1, 100)) {
                $final_target_ips[] = $node['ip_address'];
                echo "   -> [{$node['hostname']}] 🟢 状态健康 (权重命中) -> 入选\n";
            } else {
                echo "   -> [{$node['hostname']}] 🟡 状态健康 (权重轮空) -> 轮空\n";
            }
        } else {
            echo "   -> [{$node['hostname']}] 🔴 负载过高 -> 暂时剔除\n";
        }
    }

    // 【场景 C：全员过载兜底】
    // 如果筛选完，发现 0 个节点入选 (说明所有节点都挂了/忙了)
    if (empty($final_target_ips)) {
        echo "⚠️ 紧急警报：所有节点均过载或权重未命中！\n";
        echo "⚠️ 启动 [全员兜底] 策略：将所有存活节点强制加入 DNS，共同分担流量。\n";
        
        // 记录一条系统级严重告警
        log_alert($pdo, 0, 'Cluster', '100%', "集群严重超载！所有节点($candidate_count)均已满载，请立即增加节点！");
        
        foreach ($candidates as $node) {
            $final_target_ips[] = $node['ip_address'];
        }
    }
}

// --------------------------------------------------------
// 辅助函数：负载检查与告警
// 返回 true 表示健康，false 表示过载
function check_load_and_alert($pdo, $node, $is_single_mode) {
    $overloaded = false;
    
    // 1. CPU 检查
    if ($node['cpu_usage'] > 95) {
        log_alert($pdo, $node['id'], 'CPU', $node['cpu_usage'].'%', 'CPU 爆满');
        $overloaded = true;
    }
    
    // 2. 带宽检查
    if ($node['max_bandwidth'] > 0) {
        $bw_pct = ($node['current_bandwidth'] / $node['max_bandwidth']) * 100;
        if ($bw_pct > 95) {
            log_alert($pdo, $node['id'], 'Bandwidth', $bw_pct.'%', '带宽跑满');
            $overloaded = true;
        }
    }

    // 如果是单节点模式，虽然报了警，但依然返回 true (允许解析)
    if ($is_single_mode) return true;

    return !$overloaded;
}

// 辅助函数：写入告警 (30分钟防抖)
function log_alert($pdo, $node_id, $type, $val, $msg) {
    // node_id = 0 代表系统级告警
    $stmt = $pdo->prepare("SELECT id FROM node_alerts WHERE node_id=? AND type=? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $stmt->execute([$node_id, $type]);
    if (!$stmt->fetch()) {
        // 获取名字
        $name = 'SYSTEM';
        if ($node_id > 0) {
            $s = $pdo->prepare("SELECT hostname FROM nodes WHERE id=?");
            $s->execute([$node_id]); $name = $s->fetchColumn();
        }
        
        $pdo->prepare("INSERT INTO node_alerts (node_id, node_name, type, value, message) VALUES (?, ?, ?, ?, ?)")
            ->execute([$node_id, $name, $type, $val, $msg]);
        echo "   ! 触发告警: $msg\n";
    }
}

// --------------------------------------------------------
// 3. 对接 Cloudflare API (执行更新)
// (这部分代码保持不变，负责将 $final_target_ips 推送到 CF)

$headers = [
    "X-Auth-Email: $CF_EMAIL",
    "X-Auth-Key: $CF_KEY",
    "Content-Type: application/json"
];

$url = "https://api.cloudflare.com/client/v4/zones/$CF_ZONE/dns_records?type=A&name=$CF_NAME";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!isset($res['success']) || !$res['success']) die("CF API Error\n");

$current_records = [];
foreach ($res['result'] as $record) {
    if ($record['name'] == $CF_NAME || $record['name'] == $CF_NAME . "." . $res['result'][0]['zone_name']) {
        $current_records[$record['content']] = $record['id'];
    }
}

$ips_to_add = array_diff($final_target_ips, array_keys($current_records));
$ips_to_del = array_diff(array_keys($current_records), $final_target_ips);

// 执行删除
foreach ($ips_to_del as $ip) {
    echo " -> 🗑️ DNS 删除: $ip\n";
    $rid = $current_records[$ip];
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$CF_ZONE/dns_records/$rid");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch); curl_close($ch);
}

// 执行添加
foreach ($ips_to_add as $ip) {
    echo " -> ➕ DNS 添加: $ip\n";
    $data = ['type'=>'A', 'name'=>$CF_NAME, 'content'=>$ip, 'ttl'=>60, 'proxied'=>false];
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$CF_ZONE/dns_records");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch); curl_close($ch);
}

echo "✅ 调度完成。\n";
