<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function get_conf(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT value_json FROM settings WHERE key_name = ? LIMIT 1');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    if ($val === false || $val === null) {
        return $default;
    }

    $decoded = json_decode((string)$val, true);
    if (!is_string($decoded)) {
        return $default;
    }

    return trim($decoded);
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

$dnsProvider = get_conf($pdo, 'dns_provider', 'cloudflare');
if ($dnsProvider !== 'cloudflare') {
    fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] 当前仅支持 cloudflare，已跳过。\n");
    exit(0);
}

$cfEmail = get_conf($pdo, 'cf_email');
$cfKey = get_conf($pdo, 'cf_key');
$cfZone = get_conf($pdo, 'cf_zone_id');
$cfRecord = get_conf($pdo, 'cf_record_name');
if ($cfEmail === '' || $cfKey === '' || $cfZone === '' || $cfRecord === '') {
    fwrite(STDOUT, "Cloudflare 配置不完整，请在后台保存后再运行。\n");
    exit(0);
}

$nodes = $pdo->query('SELECT * FROM nodes WHERE status = 1')->fetchAll();
$healthyIPs = [];

fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] 开始调度检查...\n");
foreach ($nodes as $node) {
    $name = (string)$node['hostname'];
    $ip = (string)$node['ip_address'];
    $lastHeartbeat = (int)($node['last_heartbeat'] ?? 0);
    if (time() - $lastHeartbeat > 60) {
        fwrite(STDOUT, " - 节点 {$name} ({$ip}) 离线\n");
        continue;
    }

    $limitGB = (int)($node['traffic_limit'] ?? 0);
    $usedBytes = (float)($node['traffic_used'] ?? 0);
    if ($limitGB > 0) {
        $limitBytes = $limitGB * 1024 * 1024 * 1024;
        $threshold = $limitBytes * 0.95;
        if ($usedBytes > $threshold) {
            fwrite(STDOUT, " - 节点 {$name} ({$ip}) 流量已达95%，暂停解析\n");
            continue;
        }
    }

    $healthyIPs[] = $ip;
}

$healthyIPs = array_values(array_unique(array_filter($healthyIPs)));
fwrite(STDOUT, '当前健康 IP 池: ' . implode(', ', $healthyIPs) . "\n");
if (empty($healthyIPs)) {
    fwrite(STDOUT, "警告：没有可用节点，已跳过 DNS 更新。\n");
    exit(0);
}

$headers = [
    'X-Auth-Email: ' . $cfEmail,
    'X-Auth-Key: ' . $cfKey,
    'Content-Type: application/json',
];

$recordName = $cfRecord;
try {
    $queryURL = sprintf(
        'https://api.cloudflare.com/client/v4/zones/%s/dns_records?type=A&name=%s',
        rawurlencode($cfZone),
        rawurlencode($recordName)
    );
    $resp = cf_request('GET', $queryURL, $headers);
    if (empty($resp['success'])) {
        throw new RuntimeException('CF API 获取记录失败: ' . json_encode($resp['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    $currentRecords = [];
    foreach (($resp['result'] ?? []) as $row) {
        if (!isset($row['content'], $row['id'])) {
            continue;
        }
        $currentRecords[(string)$row['content']] = (string)$row['id'];
    }

    $toAdd = array_diff($healthyIPs, array_keys($currentRecords));
    $toDelete = array_diff(array_keys($currentRecords), $healthyIPs);

    foreach ($toDelete as $ip) {
        $id = $currentRecords[$ip];
        fwrite(STDOUT, " -> 删除 DNS IP: {$ip}\n");
        $delURL = sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records/%s', rawurlencode($cfZone), rawurlencode($id));
        cf_request('DELETE', $delURL, $headers);
    }

    foreach ($toAdd as $ip) {
        fwrite(STDOUT, " -> 添加 DNS IP: {$ip}\n");
        $addURL = sprintf('https://api.cloudflare.com/client/v4/zones/%s/dns_records', rawurlencode($cfZone));
        cf_request('POST', $addURL, $headers, [
            'type' => 'A',
            'name' => $recordName,
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
