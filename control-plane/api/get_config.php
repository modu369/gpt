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

$stmt = $pdo->prepare('SELECT id, status FROM nodes WHERE secret_key = ? LIMIT 1');
$stmt->execute([$secret]);
$node = $stmt->fetch();

if (!$node) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Invalid Node Secret']);
    exit;
}

if ((int)$node['status'] === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Node is disabled']);
    exit;
}

$domainStmt = $pdo->query('SELECT domain FROM domains ORDER BY id ASC');
$domains = $domainStmt->fetchAll(PDO::FETCH_COLUMN);

$settingStmt = $pdo->prepare("SELECT value_json FROM settings WHERE key_name = 'cf_ips' LIMIT 1");
$settingStmt->execute();
$cfIpsJson = $settingStmt->fetchColumn();
$cfIps = json_decode((string)($cfIpsJson ?: '[]'), true);
if (!is_array($cfIps)) {
    $cfIps = [];
}

$certStmt = $pdo->query('SELECT domain, cert_body, key_body FROM certificates WHERE status = 1');
$certs = $certStmt->fetchAll(PDO::FETCH_ASSOC);

$response = [
    'whitelist' => array_values($domains),
    'cf_ips' => array_values($cfIps),
    'certs' => array_values($certs),
    'http_port' => 80,
    'https_port' => 443,
    'timestamp' => time(),
];

echo json_encode($response, JSON_UNESCAPED_SLASHES);
