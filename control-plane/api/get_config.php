<?php
// api/get_config.php - V7.1 紧急修复版
// 修复: 恢复白名单查询，确保域名访问正常；强制修正 CF IP 格式

header('Content-Type: application/json');
require_once '../db.php';

try {
    // 1. 获取 CF 中转 IP (强制数组格式)
    $stmt = $pdo->prepare("SELECT value_json FROM settings WHERE key_name = 'cf_ips'");
    $stmt->execute();
    $cf_ips_json = $stmt->fetchColumn();

    $cf_ips = [];
    if ($cf_ips_json) {
        $decoded = json_decode($cf_ips_json, true);
        if (is_array($decoded)) {
            $cf_ips = $decoded;
        } else {
            // 容错: 如果不是数组，转为数组
            $clean = trim($cf_ips_json, '"\'[] ');
            if ($clean) $cf_ips = [$clean];
        }
    }
    // 保底: 如果数据库为空，使用 1.0.0.1
    if (empty($cf_ips)) $cf_ips = ["1.0.0.1"];


    // 2. 获取域名白名单 (关键修复!)
    // 查询 domains 表中的所有域名
    $stmt = $pdo->query("SELECT domain FROM domains");
    $whitelist = $stmt->fetchAll(PDO::FETCH_COLUMN);


    // 3. 获取证书列表 (恢复证书同步)
    // 仅同步已签发(status=1)且未过期的证书
    $stmt = $pdo->query("SELECT domain, cert_body, key_body, expire_time FROM certificates WHERE status = 1");
    $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // 4. 构建返回数据
    $response = [
        'version'   => time(), // 版本号
        'whitelist' => $whitelist, // 恢复白名单
        'cf_ips'    => $cf_ips,    // 修复后的 IP 列表
        'certs'     => $certs      // 恢复证书
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
