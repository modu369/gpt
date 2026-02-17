<?php

declare(strict_types=1);

$host = getenv('CF_MASTER_DB_HOST') ?: '127.0.0.1';
$db = getenv('CF_MASTER_DB_NAME') ?: 'cf_proxy_master';
$user = getenv('CF_MASTER_DB_USER') ?: 'root';
$pass = getenv('CF_MASTER_DB_PASS') ?: 'password';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
