<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

$secret = $_SERVER['HTTP_X_NODE_SECRET'] ?? '';
if ($secret === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Missing Secret']);
    exit;
}

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw ?: '{}', true);
if (!is_array($input)) {
    $input = [];
}

$cpu = isset($input['cpu']) ? (float)$input['cpu'] : 0.0;
$ram = isset($input['ram']) ? (float)$input['ram'] : 0.0;
$up = isset($input['traffic_up']) ? (int)$input['traffic_up'] : 0;
$down = isset($input['traffic_down']) ? (int)$input['traffic_down'] : 0;
$maxBW = isset($input['max_bw']) ? (int)$input['max_bw'] : 0;
$maxRAM = isset($input['max_ram']) ? (int)$input['max_ram'] : 0;
$nodeStmt = $pdo->prepare('SELECT id, last_heartbeat, traffic_count_mode FROM nodes WHERE secret_key = ? LIMIT 1');
$nodeStmt->execute([$secret]);
$node = $nodeStmt->fetch();

if (!$node) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid Node Secret']);
    exit;
}

$trafficInc = 0;
if ((int)($node['traffic_count_mode'] ?? 0) === 1) {
    $trafficInc = max(0, $down);
} else {
    $trafficInc = max(0, $up) + max(0, $down);
}

$currentTime = time();
$lastTime = (int)($node['last_heartbeat'] ?? 0);
$timeDiff = max(1, $currentTime - $lastTime);
$totalThroughput = max(0, $up) + max(0, $down);
$currentBandwidth = (int)round(($totalThroughput * 8) / 1000 / 1000 / $timeDiff);

$sql = 'UPDATE nodes SET last_heartbeat = ?, cpu_usage = ?, ram_usage = ?, traffic_used = traffic_used + ?, current_bandwidth = ?';
$params = [$currentTime, $cpu, $ram, $trafficInc, $currentBandwidth];
if ($maxBW > 0) {
    $sql .= ', max_bandwidth = IF(max_bandwidth = 0, ?, max_bandwidth)';
    $params[] = $maxBW;
}
if ($maxRAM > 0) {
    $sql .= ', max_ram = IF(max_ram = 0, ?, max_ram)';
    $params[] = $maxRAM;
}
$sql .= ' WHERE id = ?';
$params[] = (int)$node['id'];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode(['status' => 'pong', 'bw' => $currentBandwidth]);
