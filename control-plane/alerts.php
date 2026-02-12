<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: admin.php');
    exit;
}

$pdo->query('UPDATE node_alerts SET is_read = 1 WHERE is_read = 0');
$list = $pdo->query('SELECT * FROM node_alerts ORDER BY id DESC LIMIT 200')->fetchAll();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>告警历史</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>🚨 节点异常历史记录</h3>
        <a href="admin.php" class="btn btn-secondary">返回控制台</a>
    </div>
    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>时间</th><th>节点</th><th>类型</th><th>数值</th><th>详情</th></tr></thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['node_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge bg-danger"><?= htmlspecialchars((string)$row['type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)$row['value'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['message'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
