<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify.php';

$rows = $pdo->query('SELECT * FROM cf_ip_pool ORDER BY id ASC')->fetchAll();
if (empty($rows)) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] cf_ip_pool 为空，跳过检测。\n");
    exit(0);
}

$hasChange = false;
fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] 开始检测 Cloudflare IP...\n");

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $ip = (string)$row['ip_address'];
    $prevStatus = (int)$row['status'];
    $failCount = (int)$row['fail_count'];

    $output = [];
    $code = 1;
    exec('ping -c 1 -W 1 ' . escapeshellarg($ip), $output, $code);

    $alive = ($code === 0);
    $latency = 0;
    if ($alive) {
        $joined = implode(' ', $output);
        if (preg_match('/time=([\d\.]+)/', $joined, $m)) {
            $latency = (int)round((float)$m[1]);
        }
        $failCount = 0;
        $finalStatus = 1;
        fwrite(STDOUT, " -> {$ip} [正常] 延迟: {$latency}ms\n");
    } else {
        $failCount++;
        $finalStatus = $prevStatus;
        if ($failCount >= 2) {
            $finalStatus = 0;
        }
        fwrite(STDOUT, " -> {$ip} [检测失败] 连续失败: {$failCount}\n");
    }

    $pdo->prepare('UPDATE cf_ip_pool SET status = ?, latency = ?, fail_count = ?, last_check = ? WHERE id = ?')
        ->execute([$finalStatus, $latency, $failCount, time(), $id]);

    if ($finalStatus !== $prevStatus) {
        $hasChange = true;
        $state = $finalStatus === 1 ? '恢复上线' : '检测宕机';
        fwrite(STDOUT, " !!! 状态变更: {$ip} -> {$state}\n");
    }
}

if ($hasChange) {
    fwrite(STDOUT, "触发全网同步...\n");
    $result = notify_all_nodes($pdo);
    fwrite(STDOUT, '同步结果: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n");
}

fwrite(STDOUT, "检测结束。\n");
