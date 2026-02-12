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
$trafficInc = max(0, $up) + max(0, $down);

$sql = 'UPDATE nodes SET last_heartbeat = ?, cpu_usage = ?, ram_usage = ?, traffic_used = traffic_used + ? WHERE secret_key = ?';
$stmt = $pdo->prepare($sql);
$stmt->execute([time(), $cpu, $ram, $trafficInc, $secret]);

if ($stmt->rowCount() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid Node Secret']);
    exit;
}

echo json_encode(['status' => 'pong']);
