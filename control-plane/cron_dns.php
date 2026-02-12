<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function get_conf(PDO $pdo, string $key): string
{
    $stmt = $pdo->prepare('SELECT value_json FROM settings WHERE key_name = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null) {
        return '';
    }
    $decoded = json_decode((string)$value, true);
    return is_string($decoded) ? trim($decoded) : '';
}

function log_alert(PDO $pdo, array $node, string $type, string $value, string $msg): void
{
    $stmt = $pdo->prepare('SELECT id FROM node_alerts WHERE node_id = ? AND type = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1');
    $stmt->execute([(int)$node['id'], $type]);
    if ($stmt->fetch()) {
        return;
    }

    $ins = $pdo->prepare('INSERT INTO node_alerts (node_id, node_name, type, value, message) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([(int)$node['id'], (string)$node['hostname'], $type, $value, $msg]);
}

function cf_request(string $method, string $url, array $headers, ?array $payload = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('Cloudflare 请求失败: ' . $err);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Cloudflare 响应不是合法 JSON');
    }

    return $decoded;
}

$cfEmail = get_conf($pdo, 'cf_email');
$cfKey = get_conf($pdo, 'cf_key');
$cfZone = get_conf($pdo, 'cf_zone_id');
$cfName = get_conf($pdo, 'cf_record_name');

if ($cfEmail === '' || $cfKey === '' || $cfZone === '' || $cfName === '') {
    fwrite(STDOUT, "Cloudflare 配置不完整，跳过。\n");
    exit(0);
}

$nodes = $pdo->query('SELECT * FROM nodes WHERE status = 1 ORDER BY id ASC')->fetchAll();
$targetIPs = [];
$eligible = [];

fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] 开始智能调度...\n");

foreach ($nodes as $node) {
    $name = (string)$node['hostname'];
    $ip = (string)$node['ip_address'];

    if (time() - (int)$node['last_heartbeat'] > 60) {
        fwrite(STDOUT, " - [{$name}] 离线 (跳过)\n");
        continue;
    }

    $limitBytes = (int)$node['traffic_limit'] * 1024 * 1024 * 1024;
    $usedBytes = (float)$node['traffic_used'];
    if ($limitBytes > 0 && $usedBytes > ($limitBytes * 0.95)) {
        fwrite(STDOUT, " - [{$name}] 流量耗尽 (跳过)\n");
        log_alert($pdo, $node, 'Traffic', round($usedBytes / 1024 / 1024 / 1024, 2) . 'GB', '流量即将耗尽(>95%)');
        continue;
    }

    $weight = max(0, min(100, (int)($node['weight'] ?? 100)));
    $cpu = (float)($node['cpu_usage'] ?? 0);
    $dynamicWeight = $weight;
    if ($cpu > 95) {
        $dynamicWeight = 0;
        log_alert($pdo, $node, 'CPU', $cpu . '%', 'CPU超载，触发强制熔断');
    } elseif ($cpu > 80) {
        $dynamicWeight = max(0, (int)floor($weight * 0.5));
        log_alert($pdo, $node, 'CPU', $cpu . '%', 'CPU高负载，权重降低');
    }

    $maxBandwidth = (int)($node['max_bandwidth'] ?? 0);
    $currentBandwidth = (int)($node['current_bandwidth'] ?? 0);
    if ($maxBandwidth > 0) {
        $bwUsagePct = ($currentBandwidth / $maxBandwidth) * 100;
        if ($bwUsagePct > 95) {
            $dynamicWeight = 0;
            fwrite(STDOUT, " - [{$name}] 带宽跑满 ({$currentBandwidth}/{$maxBandwidth} Mbps) -> 强制熔断
");
            log_alert($pdo, $node, 'Bandwidth', round($bwUsagePct, 2) . '%', '带宽已跑满，暂停解析');
        } elseif ($bwUsagePct > 80) {
            $dynamicWeight = max(0, (int)floor($dynamicWeight * 0.3));
            fwrite(STDOUT, " - [{$name}] 带宽拥堵 ({$currentBandwidth}/{$maxBandwidth} Mbps) -> 权重降低
");
            log_alert($pdo, $node, 'Bandwidth', round($bwUsagePct, 2) . '%', '带宽高负载，权重降低');
        }
    }

    $eligible[] = array_merge($node, ['dynamic_weight' => $dynamicWeight]);
    $rand = random_int(1, 100);
    if ($dynamicWeight >= $rand) {
        $targetIPs[] = $ip;
        fwrite(STDOUT, " - [{$name}] 权重 {$dynamicWeight} (随机 {$rand}) -> 入选 ✅\n");
    } else {
        fwrite(STDOUT, " - [{$name}] 权重 {$dynamicWeight} (随机 {$rand}) -> 轮空 ⏸️\n");
    }
}

if (empty($targetIPs)) {
    fwrite(STDOUT, "⚠️ 随机算法导致空池，启动兜底...\n");
    $best = null;
    $bestWeight = -1;
    foreach ($eligible as $node) {
        $dw = (int)$node['dynamic_weight'];
        if ($dw > $bestWeight) {
            $bestWeight = $dw;
            $best = $node;
        }
    }

    if ($best !== null) {
        $targetIPs[] = (string)$best['ip_address'];
        fwrite(STDOUT, ' -> 兜底选中: ' . (string)$best['hostname'] . "\n");
    } else {
        fwrite(STDOUT, "❌ 没有在线可用节点，停止更新 DNS。\n");
        exit(0);
    }
}

$targetIPs = array_values(array_unique($targetIPs));

$headers = [
    'X-Auth-Email: ' . $cfEmail,
    'X-Auth-Key: ' . $cfKey,
    'Content-Type: application/json',
];

try {
    $queryURL = sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records?type=A&name=%s', rawurlencode($cfZone), rawurlencode($cfName));
    $resp = cf_request('GET', $queryURL, $headers);
    if (empty($resp['success'])) {
        throw new RuntimeException('CF API Error: ' . json_encode($resp['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    $current = [];
    foreach (($resp['result'] ?? []) as $record) {
        if (!isset($record['content'], $record['id'])) {
            continue;
        }
        $current[(string)$record['content']] = (string)$record['id'];
    }

    $toAdd = array_diff($targetIPs, array_keys($current));
    $toDel = array_diff(array_keys($current), $targetIPs);

    foreach ($toDel as $ip) {
        fwrite(STDOUT, " -> DNS 删除: {$ip}\n");
        $id = $current[$ip];
        cf_request('DELETE', sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records/%s', rawurlencode($cfZone), rawurlencode($id)), $headers);
    }

    foreach ($toAdd as $ip) {
        fwrite(STDOUT, " -> DNS 添加: {$ip}\n");
        cf_request('POST', sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records', rawurlencode($cfZone)), $headers, [
            'type' => 'A',
            'name' => $cfName,
            'content' => $ip,
            'ttl' => 60,
            'proxied' => false,
        ]);
    }

    fwrite(STDOUT, "调度完成。\n");
} catch (Throwable $e) {
    fwrite(STDERR, '调度失败: ' . $e->getMessage() . "\n");
    exit(1);
}
