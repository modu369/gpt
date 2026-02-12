<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify.php';

function postStr(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function get_setting(PDO $pdo, string $key, mixed $default): mixed
{
    $stmt = $pdo->prepare('SELECT value_json FROM settings WHERE key_name = ? LIMIT 1');
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();
    if ($raw === false || $raw === null) {
        return $default;
    }

    $decoded = json_decode((string)$raw, true);
    return $decoded ?? $default;
}

function set_setting(PDO $pdo, string $key, mixed $value): void
{
    $payload = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare('INSERT INTO settings (key_name, value_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)');
    $stmt->execute([$key, $payload]);
}

function normalize_slug(string $slug): string
{
    return strtolower(trim(trim($slug), '/'));
}

function detect_request_slug(): string
{
    $requestPath = trim(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/', '/');
    $fallback = trim((string)($_GET['slug'] ?? ''), '/');

    if ($requestPath !== '' && $requestPath !== 'admin.php') {
        return strtolower($requestPath);
    }
    if ($fallback !== '' && $fallback !== 'admin.php') {
        return strtolower($fallback);
    }

    return '';
}

function run_acme_command(string $cmd): string
{
    $output = shell_exec($cmd . ' 2>&1');
    return is_string($output) ? $output : '';
}

$CONF_USER = (string)get_setting($pdo, 'admin_user', 'admin');
$CONF_PASS = (string)get_setting($pdo, 'admin_pass', 'admin123');
$CONF_SLUG = normalize_slug((string)get_setting($pdo, 'admin_slug', 'yun123'));
if ($CONF_SLUG === '') {
    $CONF_SLUG = 'yun123';
}

$DNS_PROVIDER = (string)get_setting($pdo, 'dns_provider', 'cloudflare');
$CF_EMAIL = (string)get_setting($pdo, 'cf_email', '');
$CF_KEY = (string)get_setting($pdo, 'cf_key', '');
$CF_ZONE = (string)get_setting($pdo, 'cf_zone_id', '');
$CF_RECORD = (string)get_setting($pdo, 'cf_record_name', 'cdn');
$HW_REGION = (string)get_setting($pdo, 'hw_region', 'ap-southeast-1');
$HW_AK = (string)get_setting($pdo, 'hw_ak', '');
$HW_SK = (string)get_setting($pdo, 'hw_sk', '');
$HW_ZONE = (string)get_setting($pdo, 'hw_zone_id', '');

$urlSlug = detect_request_slug();
if (isset($_GET['logout']) || postStr('action') === 'logout') {
    session_destroy();
    header('Location: /');
    exit;
}

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    if ($urlSlug !== $CONF_SLUG) {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = postStr('username');
        $p = postStr('password');
        if (hash_equals($CONF_USER, $u) && hash_equals($CONF_PASS, $p)) {
            $_SESSION['is_admin'] = true;
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
        $error = '账号或密码错误';
    }
    ?>
    <!doctype html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>管理后台登录</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <style>body{background:#f0f2f5;display:flex;align-items:center;justify-content:center;height:100vh}.login-card{width:100%;max-width:400px;padding:2rem;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.1);background:#fff}</style>
    </head>
    <body>
    <div class="login-card">
        <h4 class="text-center mb-4">管理后台登录</h4>
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3"><label class="form-label">账号</label><input type="text" name="username" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">密码</label><input type="password" name="password" class="form-control" required></div>
            <button type="submit" class="btn btn-primary w-100">立即登录</button>
        </form>
    </div>
    </body></html>
    <?php
    exit;
}

$message = '';
$acmeBin = __DIR__ . '/acme_tool/acme.sh';
$certHome = __DIR__ . '/cert_data';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postStr('action');
    $shouldNotify = false;

    if ($action === 'update_settings') {
        $newUser = postStr('admin_user');
        $newPass = postStr('admin_pass');
        $newSlug = normalize_slug(postStr('admin_slug'));

        if ($newUser === '' || $newPass === '' || $newSlug === '') {
            $message = '<div class="alert alert-warning">账号、密码、入口标识不能为空。</div>';
        } else {
            set_setting($pdo, 'admin_user', $newUser);
            set_setting($pdo, 'admin_pass', $newPass);
            set_setting($pdo, 'admin_slug', $newSlug);
            $CONF_USER = $newUser;
            $CONF_PASS = $newPass;
            $CONF_SLUG = $newSlug;
            $message = '<div class="alert alert-success">设置已保存。新入口：<code>/'.htmlspecialchars($newSlug, ENT_QUOTES, 'UTF-8').'</code></div>';
        }
    } elseif ($action === 'add_node') {
        $hostname = postStr('hostname');
        $ip = postStr('ip');
        if ($hostname === '' || $ip === '') {
            $message = '<div class="alert alert-warning">节点名称和 IP 不能为空。</div>';
        } else {
            $secret = bin2hex(random_bytes(16));
            try {
                $pdo->prepare('INSERT INTO nodes (hostname, ip_address, secret_key) VALUES (?, ?, ?)')->execute([$hostname, $ip, $secret]);

                $isHTTPS = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                $scheme = $isHTTPS ? 'https://' : 'http://';
                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                if ($host === '') {
                    $serverName = (string)($_SERVER['SERVER_NAME'] ?? '127.0.0.1');
                    $serverPort = (string)($_SERVER['SERVER_PORT'] ?? '8080');
                    $host = $serverName . ':' . $serverPort;
                }

                $masterAPI = $scheme . $host . '/api';
                $installCmd = 'curl -O https://raw.githubusercontent.com/modu369/gpt/codex/add-domain-level-traffic-statistics-report/install_node.sh '
                    . '&& chmod +x install_node.sh '
                    . '&& ./install_node.sh -master ' . $masterAPI . ' -secret ' . $secret;
                $safeInstallCmd = htmlspecialchars($installCmd, ENT_QUOTES, 'UTF-8');
                $cmdInputID = 'cmd_' . $secret;

                $message = '<div class="alert alert-success">'
                    . '<h5 class="mb-2">✅ 节点添加成功！</h5>'
                    . '<p class="mb-2">请在被控端服务器执行以下一键安装命令：</p>'
                    . '<div class="input-group">'
                    . '<input type="text" class="form-control" id="' . $cmdInputID . '" value="' . $safeInstallCmd . '" readonly>'
                    . '<button type="button" class="btn btn-outline-secondary" onclick="copyCmd(\'' . $cmdInputID . '\')">复制</button>'
                    . '</div>'
                    . '<div class="mt-2 small text-muted">节点密钥：<code class="user-select-all">'
                    . htmlspecialchars($secret, ENT_QUOTES, 'UTF-8')
                    . '</code></div>'
                    . '</div>';
                $shouldNotify = true;
            } catch (Throwable $e) {
                $message = '<div class="alert alert-danger">节点添加失败：'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</div>';
            }
        }
    } elseif ($action === 'del_node') {
        $pdo->prepare('DELETE FROM nodes WHERE id = ?')->execute([(int)postStr('id')]);
        $message = '<div class="alert alert-success">节点已删除。</div>';
        $shouldNotify = true;
    } elseif ($action === 'add_domain') {
        $domain = strtolower(postStr('domain'));
        if ($domain === '') {
            $message = '<div class="alert alert-warning">域名不能为空。</div>';
        } else {
            try {
                $pdo->prepare('INSERT INTO domains (domain) VALUES (?)')->execute([$domain]);
                $message = '<div class="alert alert-success">域名添加成功。</div>';
                $shouldNotify = true;
            } catch (Throwable) {
                $message = '<div class="alert alert-danger">域名添加失败（可能已存在）。</div>';
            }
        }
    } elseif ($action === 'del_domain') {
        $pdo->prepare('DELETE FROM domains WHERE id = ?')->execute([(int)postStr('id')]);
        $message = '<div class="alert alert-success">域名已删除。</div>';
        $shouldNotify = true;
    } elseif ($action === 'update_ips') {
        $raw = (string)($_POST['cf_ips'] ?? '');
        $ips = array_values(array_filter(array_map(static fn(string $line): string => trim($line), explode("\n", $raw))));
        set_setting($pdo, 'cf_ips', $ips);
        $message = '<div class="alert alert-success">CF 优选 IP 池（主控监控） 池已更新。</div>';
        $shouldNotify = true;
    } elseif ($action === 'add_cf_ip') {
        $newIP = postStr('new_ip');
        if (filter_var($newIP, FILTER_VALIDATE_IP)) {
            $pdo->prepare('INSERT IGNORE INTO cf_ip_pool (ip_address) VALUES (?)')->execute([$newIP]);
            $message = '<div class="alert alert-success">CF IP 已添加。</div>';
            $shouldNotify = true;
        } else {
            $message = '<div class="alert alert-warning">请输入合法 IP 地址。</div>';
        }
    } elseif ($action === 'del_cf_ip') {
        $rowID = (int)postStr('id');
        $pdo->prepare('DELETE FROM cf_ip_pool WHERE id = ?')->execute([$rowID]);
        $message = '<div class="alert alert-success">CF IP 已删除。</div>';
        $shouldNotify = true;

    } elseif ($action === 'save_dns') {
        $DNS_PROVIDER = postStr('dns_provider') !== '' ? postStr('dns_provider') : 'cloudflare';
        $CF_EMAIL = postStr('cf_email');
        $CF_KEY = postStr('cf_key');
        $CF_ZONE = postStr('cf_zone_id');
        $CF_RECORD = postStr('cf_record_name') !== '' ? postStr('cf_record_name') : 'cdn';
        $HW_AK = postStr('hw_ak');
        $HW_SK = postStr('hw_sk');
        $HW_ZONE = postStr('hw_zone_id');
        $HW_REGION = postStr('hw_region') !== '' ? postStr('hw_region') : 'ap-southeast-1';

        set_setting($pdo, 'dns_provider', $DNS_PROVIDER);
        set_setting($pdo, 'cf_email', $CF_EMAIL);
        set_setting($pdo, 'cf_key', $CF_KEY);
        set_setting($pdo, 'cf_zone_id', $CF_ZONE);
        set_setting($pdo, 'cf_record_name', $CF_RECORD);
        set_setting($pdo, 'hw_ak', $HW_AK);
        set_setting($pdo, 'hw_sk', $HW_SK);
        set_setting($pdo, 'hw_zone_id', $HW_ZONE);
        set_setting($pdo, 'hw_region', $HW_REGION);
        $message = '<div class="alert alert-success">DNS 配置已保存。</div>';
    } elseif ($action === 'update_node_config') {
        $nodeID = (int)postStr('id');
        $limitGB = max(0, (int)postStr('traffic_limit'));
        $weight = max(0, min(100, (int)postStr('weight')));
        $maxBandwidth = max(0, (int)postStr('max_bandwidth'));
        $pdo->prepare('UPDATE nodes SET traffic_limit = ?, weight = ?, max_bandwidth = ? WHERE id = ?')->execute([$limitGB, $weight, $maxBandwidth, $nodeID]);
        $message = '<div class="alert alert-success">节点配置已更新。</div>';
    } elseif ($action === 'reset_traffic') {
        $nodeID = (int)postStr('id');
        $pdo->prepare('UPDATE nodes SET traffic_used = 0 WHERE id = ?')->execute([$nodeID]);
        $message = '<div class="alert alert-success">流量已清零。</div>';
    } elseif ($action === 'update_node_bandwidth') {
        $nodeID = (int)postStr('id');
        $bandwidthMax = max(0, (int)postStr('max_bandwidth'));
        $pdo->prepare('UPDATE nodes SET max_bandwidth = ? WHERE id = ?')->execute([$bandwidthMax, $nodeID]);
        $message = '<div class="alert alert-success">节点带宽上限已更新。</div>';
    } elseif ($action === 'cert_apply') {
        $domain = postStr('domain');
        if ($domain === '') {
            $message = '<div class="alert alert-warning">证书域名不能为空。</div>';
        } elseif (!is_file($acmeBin)) {
            $message = '<div class="alert alert-danger">未找到 acme.sh，请先按文档安装。</div>';
        } else {
            $cmd = 'export HOME=' . escapeshellarg($certHome) . ' && ' . escapeshellcmd($acmeBin)
                . ' --issue --dns -d ' . escapeshellarg($domain)
                . ' --yes-I-know-dns-manual-mode-enough-go-ahead-please --force --server letsencrypt --home '
                . escapeshellarg($certHome);
            $output = run_acme_command($cmd);
            if (preg_match("/TXT value:\s*'([^']+)'/", $output, $m)) {
                $txtValue = $m[1];
                $stmt = $pdo->prepare('INSERT INTO certificates (domain, dns_challenge, status, created_at) VALUES (?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE dns_challenge = VALUES(dns_challenge), status = 0');
                $stmt->execute([$domain, $txtValue]);
                $message = '<div class="alert alert-warning">申请已提交。请添加 TXT 记录后点击“验证并签发”。</div>';
            } else {
                $message = '<div class="alert alert-danger">申请失败，ACME 输出：<pre class="mb-0">'.htmlspecialchars($output, ENT_QUOTES, 'UTF-8').'</pre></div>';
            }
        }
    } elseif ($action === 'cert_verify') {
        $domain = postStr('domain');
        if ($domain === '') {
            $message = '<div class="alert alert-warning">缺少证书域名。</div>';
        } elseif (!is_file($acmeBin)) {
            $message = '<div class="alert alert-danger">未找到 acme.sh，请先按文档安装。</div>';
        } else {
            $cmd = 'export HOME=' . escapeshellarg($certHome) . ' && ' . escapeshellcmd($acmeBin)
                . ' --renew -d ' . escapeshellarg($domain)
                . ' --yes-I-know-dns-manual-mode-enough-go-ahead-please --server letsencrypt --home '
                . escapeshellarg($certHome);
            $output = run_acme_command($cmd);

            $cerPath = $certHome . '/' . $domain . '_ecc/fullchain.cer';
            $keyPath = $certHome . '/' . $domain . '_ecc/' . $domain . '.key';
            if (!is_file($cerPath)) {
                $cerPath = $certHome . '/' . $domain . '/fullchain.cer';
                $keyPath = $certHome . '/' . $domain . '/' . $domain . '.key';
            }

            if (is_file($cerPath) && is_file($keyPath)) {
                $certBody = (string)file_get_contents($cerPath);
                $keyBody = (string)file_get_contents($keyPath);
                $certInfo = openssl_x509_parse($certBody);
                $expireTime = (int)($certInfo['validTo_time_t'] ?? 0);

                $stmt = $pdo->prepare('UPDATE certificates SET cert_body = ?, key_body = ?, expire_time = ?, status = 1, dns_challenge = "" WHERE domain = ?');
                $stmt->execute([$certBody, $keyBody, $expireTime, $domain]);

                $message = '<div class="alert alert-success">证书签发成功，已广播节点立即更新。</div>';
                $shouldNotify = true;
            } else {
                $message = '<div class="alert alert-danger">验证失败，可能 DNS 尚未生效。ACME 输出：<pre class="mb-0">'.htmlspecialchars($output, ENT_QUOTES, 'UTF-8').'</pre></div>';
            }
        }
    }

    if ($shouldNotify) {
        $result = notify_all_nodes($pdo);
        if (isset($result['error'])) {
            $message .= '<div class="alert alert-warning mt-2">广播提醒：'.htmlspecialchars((string)$result['error'], ENT_QUOTES, 'UTF-8').'</div>';
        } else {
            $message .= '<div class="alert alert-info mt-2">已广播刷新指令：成功 '.(int)$result['ok'].' / 总计 '.(int)$result['total'].'。</div>';
        }
    }
}

$nodes = $pdo->query('SELECT * FROM nodes ORDER BY id DESC')->fetchAll();
$domains = $pdo->query('SELECT * FROM domains ORDER BY id DESC')->fetchAll();
$cfIPs = get_setting($pdo, 'cf_ips', []);
$cfIPsList = $pdo->query('SELECT * FROM cf_ip_pool ORDER BY status DESC, latency ASC, id ASC')->fetchAll();
$certsList = $pdo->query('SELECT * FROM certificates ORDER BY id DESC')->fetchAll();
$unreadCount = (int)$pdo->query('SELECT COUNT(*) FROM node_alerts WHERE is_read = 0')->fetchColumn();
if (!is_array($cfIPs)) {
    $cfIPs = [];
}

$alertHtml = '';
$totalTrafficBytes = 0.0;
$cpuTotal = 0.0;
$ramTotal = 0.0;
$onlineCount = 0;
$nodeAlerts = [];
foreach ($nodes as $nodeRow) {
    $totalTrafficBytes += (float)($nodeRow['traffic_used'] ?? 0);
    $cpu = (float)($nodeRow['cpu_usage'] ?? 0);
    $ram = (float)($nodeRow['ram_usage'] ?? 0);
    $cpuTotal += $cpu;
    $ramTotal += $ram;
    if ((time() - (int)($nodeRow['last_heartbeat'] ?? 0)) < 30) {
        $onlineCount++;
    }
    if ($cpu >= 90) {
        $nodeAlerts[] = '节点 ' . (string)$nodeRow['hostname'] . ' CPU 超载 (' . $cpu . '%)';
    }
    if ($ram >= 9000) {
        $nodeAlerts[] = '节点 ' . (string)$nodeRow['hostname'] . ' 内存告急 (' . $ram . 'MB)';
    }
}
$totalTrafficGB = round($totalTrafficBytes / 1024 / 1024 / 1024, 2);
$avgCPU = count($nodes) > 0 ? round($cpuTotal / count($nodes), 2) : 0;
$avgRAM = count($nodes) > 0 ? round($ramTotal / count($nodes), 2) : 0;

foreach ($certsList as $certRow) {
    if ((int)$certRow['status'] === 1 && ((int)$certRow['expire_time'] - time()) < 5 * 86400) {
        $days = (int)ceil(((int)$certRow['expire_time'] - time()) / 86400);
        $alertHtml .= '<div class="alert alert-danger">⚠️ 域名 <strong>'.htmlspecialchars((string)$certRow['domain'], ENT_QUOTES, 'UTF-8').'</strong> 证书还有 '.max($days, 0).' 天过期，请尽快续期。</div>';
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>系统管理 - <?= htmlspecialchars($CONF_SLUG, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>.status-dot{height:10px;width:10px;border-radius:50%;display:inline-block}.bg-online{background:#198754;box-shadow:0 0 5px #198754}.bg-offline{background:#dc3545}.nav-link.active{background:#0d6efd!important;color:#fff!important}</style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4"><div class="container"><span class="navbar-brand mb-0 h1">🛡️ 流量转发控制台</span><div class="d-flex"><span class="navbar-text me-3">管理员: <?= htmlspecialchars($CONF_USER, ENT_QUOTES, 'UTF-8') ?></span><form method="post"><input type="hidden" name="action" value="logout"><button class="btn btn-sm btn-outline-danger">退出</button></form></div></div></nav>

<div class="container">
    <?= $message ?>
    <?= $alertHtml ?>

    <?php if ($unreadCount > 0): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>⚠️ 系统检测到异常！</strong> 有 <?= $unreadCount ?> 条新的超载或熔断记录。
            <a href="alerts.php" class="btn btn-sm btn-danger ms-3">查看详情</a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($nodeAlerts)): ?>
        <div class="alert alert-danger"><strong>⚠️ 系统告警：</strong><br><?= nl2br(htmlspecialchars(implode("
", $nodeAlerts), ENT_QUOTES, 'UTF-8')) ?></div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body text-center"><h6>总流量消耗</h6><h3><?= $totalTrafficGB ?> GB</h3></div></div></div>
        <div class="col-md-9"><div class="card"><div class="card-body d-flex justify-content-around align-items-center"><div style="width:150px"><canvas id="cpuChart"></canvas></div><div style="width:150px"><canvas id="ramChart"></canvas></div><div><h5>集群健康度</h5><p class="text-muted mb-0">在线节点: <?= $onlineCount ?> / <?= count($nodes) ?></p></div></div></div></div>
    </div>

    <ul class="nav nav-pills mb-4" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-dashboard">📊 监控与节点</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-domains">🌐 域名管理</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-certs">🔒 证书中心</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-dns">☁️ DNS 调度</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-settings">🛠️ 系统设置</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-dashboard">
            <div class="row">
                <div class="col-md-9">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">节点列表（含流量限额）</h5>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addNodeModal">+ 新增</button>
                        </div>
                        <div class="card-body">
                            <table class="table table-hover align-middle">
                                <thead>
                                <tr>
                                    <th>状态</th>
                                    <th>节点信息</th>
                                    <th>负载监控 (CPU / 带宽)</th>
                                    <th>月流量 (GB)</th>
                                    <th>配置 (权重 & 带宽)</th>
                                    <th>最后心跳</th>
                                    <th>操作</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($nodes as $node):
                                    $isOnline = (time() - (int)$node['last_heartbeat']) < 30;
                                    $usedGB = round(((float)($node['traffic_used'] ?? 0)) / 1024 / 1024 / 1024, 2);
                                    $limitGB = (int)($node['traffic_limit'] ?? 0);
                                    $weight = (int)($node['weight'] ?? 100);
                                    $trafficPct = $limitGB > 0 ? min(100, (int)round(($usedGB / $limitGB) * 100)) : 0;

                                    $curBW = (int)($node['current_bandwidth'] ?? 0);
                                    $maxBW = (int)($node['max_bandwidth'] ?? 0);
                                    $displayMax = $maxBW > 0 ? $maxBW : 1000;
                                    $bwPct = (int)round(($curBW / $displayMax) * 100);
                                    if ($bwPct > 100) {
                                        $bwPct = 100;
                                    }
                                    $bwColor = $bwPct > 80 ? 'bg-danger' : 'bg-primary';
                                ?>
                                    <tr>
                                        <td><span class="status-dot <?= $isOnline ? 'bg-online' : 'bg-offline' ?>"></span></td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$node['hostname'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars((string)$node['ip_address'], ENT_QUOTES, 'UTF-8') ?></small>
                                        </td>
                                        <td>
                                            <small>CPU: <?= (float)$node['cpu_usage'] ?>%</small>
                                            <div class="progress" style="height: 4px; width: 120px; margin-bottom: 5px;">
                                                <div class="progress-bar bg-info" style="width: <?= max(0, min(100, (int)round((float)$node['cpu_usage']))) ?>%"></div>
                                            </div>

                                            <small>带宽: <?= $curBW ?> / <?= $maxBW > 0 ? $maxBW : '∞' ?> Mbps</small>
                                            <div class="progress" style="height: 4px; width: 120px;">
                                                <div class="progress-bar <?= $bwColor ?>" style="width: <?= $bwPct ?>%"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <small><?= $usedGB ?> / <?= $limitGB === 0 ? '∞' : $limitGB ?> GB</small>
                                            <div class="progress" style="height: 4px; width: 100px;">
                                                <div class="progress-bar <?= $trafficPct > 90 ? 'bg-danger' : 'bg-success' ?>" style="width: <?= $trafficPct ?>%"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <form method="post" class="d-flex flex-column gap-1" style="min-width: 200px;">
                                                <input type="hidden" name="action" value="update_node_config">
                                                <input type="hidden" name="id" value="<?= (int)$node['id'] ?>">

                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text" style="width:70px">权重</span>
                                                    <input type="number" name="weight" value="<?= $weight ?>" class="form-control" min="0" max="100">
                                                </div>

                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text" style="width:70px">限速</span>
                                                    <input type="number" name="max_bandwidth" value="<?= $maxBW ?>" class="form-control" placeholder="Mbps" min="0">
                                                </div>

                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text" style="width:70px">流量</span>
                                                    <input type="number" name="traffic_limit" value="<?= $limitGB ?>" class="form-control" placeholder="GB" min="0">
                                                </div>

                                                <button class="btn btn-sm btn-outline-primary mt-1 w-100">保存配置</button>
                                            </form>
                                        </td>
                                        <td><?= (int)$node['last_heartbeat'] ? date('H:i:s', (int)$node['last_heartbeat']) : '-' ?></td>
                                        <td>
                                            <form method="post" style="display:inline">
                                                <input type="hidden" name="action" value="reset_traffic">
                                                <input type="hidden" name="id" value="<?= (int)$node['id'] ?>">
                                                <button class="btn btn-sm btn-link text-warning">清零</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('确定删除此节点？');" style="display:inline">
                                                <input type="hidden" name="action" value="del_node">
                                                <input type="hidden" name="id" value="<?= (int)$node['id'] ?>">
                                                <button class="btn btn-sm btn-link text-danger">删除</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card"><div class="card-header">CF 优选 IP</div><div class="card-body"><form method="post"><input type="hidden" name="action" value="update_ips"><textarea name="cf_ips" class="form-control mb-2" rows="6"><?= htmlspecialchars(implode("
", $cfIPs), ENT_QUOTES, 'UTF-8') ?></textarea><button class="btn btn-success w-100">保存并同步</button></form></div></div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-domains">
            <div class="card"><div class="card-body"><form method="post" class="d-flex mb-3"><input type="hidden" name="action" value="add_domain"><input type="text" name="domain" class="form-control me-2" placeholder="example.com" required><button class="btn btn-primary">添加域名</button></form><ul class="list-group"><?php foreach ($domains as $dm): ?><li class="list-group-item d-flex justify-content-between"><?= htmlspecialchars((string)$dm['domain'], ENT_QUOTES, 'UTF-8') ?><form method="post"><input type="hidden" name="action" value="del_domain"><input type="hidden" name="id" value="<?= (int)$dm['id'] ?>"><button class="btn btn-sm btn-danger">×</button></form></li><?php endforeach; ?></ul></div></div>
        </div>

        <div class="tab-pane fade" id="tab-certs">
            <div class="card mb-4"><div class="card-header">申请新证书 (Let's Encrypt 手动 DNS)</div><div class="card-body"><form method="post" class="d-flex"><input type="hidden" name="action" value="cert_apply"><input type="text" name="domain" class="form-control me-2" placeholder="example.com 或 *.example.com" required><button class="btn btn-primary">第一步：获取 DNS 验证值</button></form></div></div>
            <div class="card"><div class="card-header">证书列表</div><div class="card-body"><table class="table table-bordered align-middle"><thead><tr><th>域名</th><th>状态</th><th>验证信息 / 有效期</th><th>操作</th></tr></thead><tbody><?php foreach ($certsList as $c): ?><tr><td><strong><?= htmlspecialchars((string)$c['domain'], ENT_QUOTES, 'UTF-8') ?></strong></td><td><?php if ((int)$c['status'] === 0): ?><span class="badge bg-warning text-dark">待验证</span><?php else: ?><span class="badge bg-success">已生效</span><?php endif; ?></td><td><?php if ((int)$c['status'] === 0): ?><div class="alert alert-secondary mb-0 p-2 small">请添加 TXT 记录：<br><strong>_acme-challenge.<?= htmlspecialchars(trim((string)$c['domain'], '*.'), ENT_QUOTES, 'UTF-8') ?></strong><br>值：<code class="user-select-all"><?= htmlspecialchars((string)$c['dns_challenge'], ENT_QUOTES, 'UTF-8') ?></code></div><?php else: ?><small>过期时间：<?= date('Y-m-d H:i', (int)$c['expire_time']) ?></small><?php if (((int)$c['expire_time'] - time()) < 5 * 86400): ?> <span class="badge bg-danger">即将过期</span><?php endif; ?><?php endif; ?></td><td><?php if ((int)$c['status'] === 0): ?><form method="post"><input type="hidden" name="action" value="cert_verify"><input type="hidden" name="domain" value="<?= htmlspecialchars((string)$c['domain'], ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-success btn-sm">第二步：验证并签发</button></form><?php else: ?><form method="post"><input type="hidden" name="action" value="cert_apply"><input type="hidden" name="domain" value="<?= htmlspecialchars((string)$c['domain'], ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-primary btn-sm">强制续期</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        </div>

        <div class="tab-pane fade" id="tab-dns">
            <div class="card">
                <div class="card-header">DNS 调度配置</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="save_dns">

                        <div class="mb-4 border-bottom pb-3">
                            <label class="form-label fw-bold">选择 DNS 服务商</label>
                            <select name="dns_provider" class="form-select" id="dnsProviderSelect" onchange="toggleDnsForm()">
                                <option value="cloudflare" <?= $DNS_PROVIDER === 'cloudflare' ? 'selected' : '' ?>>Cloudflare (免费/简单)</option>
                                <option value="huaweicloud" <?= $DNS_PROVIDER === 'huaweicloud' ? 'selected' : '' ?>>华为云 DNS (支持1秒TTL/权重)</option>
                            </select>
                        </div>

                        <div id="cfForm">
                            <h6 class="text-primary">Cloudflare 配置</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3"><label>邮箱</label><input type="text" name="cf_email" class="form-control" value="<?= htmlspecialchars($CF_EMAIL, ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="col-md-6 mb-3"><label>API Key</label><input type="password" name="cf_key" class="form-control" value="<?= htmlspecialchars($CF_KEY, ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="col-md-6 mb-3"><label>Zone ID</label><input type="text" name="cf_zone_id" class="form-control" value="<?= htmlspecialchars($CF_ZONE, ENT_QUOTES, 'UTF-8') ?>"></div>
                            </div>
                        </div>

                        <div id="hwForm" style="display:none;">
                            <h6 class="text-danger">华为云配置</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3"><label>Access Key (AK)</label><input type="text" name="hw_ak" class="form-control" value="<?= htmlspecialchars($HW_AK, ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="col-md-6 mb-3"><label>Secret Key (SK)</label><input type="password" name="hw_sk" class="form-control" value="<?= htmlspecialchars($HW_SK, ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="col-md-6 mb-3"><label>Zone ID (域名ID)</label><input type="text" name="hw_zone_id" class="form-control" value="<?= htmlspecialchars($HW_ZONE, ENT_QUOTES, 'UTF-8') ?>"></div>
                                <div class="col-md-6 mb-3"><label>区域代码</label><input type="text" name="hw_region" class="form-control" value="<?= htmlspecialchars($HW_REGION, ENT_QUOTES, 'UTF-8') ?>" placeholder="如 ap-southeast-1"></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label>调度域名前缀 (Record Name)</label>
                            <input type="text" name="cf_record_name" class="form-control" value="<?= htmlspecialchars($CF_RECORD, ENT_QUOTES, 'UTF-8') ?>" placeholder="如 cdn, 或完整域名 cdn.example.com.">
                            <small class="text-muted">注意：华为云建议填写完整域名并以点结尾，如 <code>cdn.example.com.</code></small>
                        </div>

                        <button class="btn btn-success">保存并应用</button>
                    </form>
                </div>
            </div>

            <div class="card mt-3"><div class="card-header">带宽上限配置（Mbps）</div><div class="card-body"><table class="table table-sm"><thead><tr><th>节点</th><th>当前上限</th><th>操作</th></tr></thead><tbody><?php foreach ($nodes as $n): ?><tr><td><?= htmlspecialchars((string)$n['hostname'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int)($n['max_bandwidth'] ?? 0) ?></td><td><form method="post" class="d-flex gap-1"><input type="hidden" name="action" value="update_node_bandwidth"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="number" min="0" class="form-control form-control-sm" name="max_bandwidth" value="<?= (int)($n['max_bandwidth'] ?? 0) ?>" style="width:90px"><button class="btn btn-sm btn-outline-secondary">保存</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
            <div class="alert alert-info mt-3">系统可通过 <code>cron_dns.php</code> 每分钟同步健康节点：离线或流量达到 95% 阈值会自动下线 DNS 解析。</div>
        </div>

        <div class="tab-pane fade" id="tab-settings">
            <div class="card"><div class="card-header bg-warning text-dark">⚠️ 安全设置（修改后入口会立即变更）</div><div class="card-body"><form method="post"><input type="hidden" name="action" value="update_settings"><div class="mb-3"><label>管理员账号</label><input type="text" name="admin_user" class="form-control" value="<?= htmlspecialchars($CONF_USER, ENT_QUOTES, 'UTF-8') ?>" required></div><div class="mb-3"><label>管理员密码</label><input type="text" name="admin_pass" class="form-control" value="<?= htmlspecialchars($CONF_PASS, ENT_QUOTES, 'UTF-8') ?>" required></div><div class="mb-3"><label>后台入口标识 (Slug)</label><div class="input-group"><span class="input-group-text">http://IP:8080/</span><input type="text" name="admin_slug" class="form-control" value="<?= htmlspecialchars($CONF_SLUG, ENT_QUOTES, 'UTF-8') ?>" required></div></div><button type="submit" class="btn btn-danger">保存修改</button></form></div></div>
        </div>
    </div>
</div>

<div class="modal fade" id="addNodeModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title">新增节点</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="add_node"><div class="mb-2"><label>名称</label><input type="text" name="hostname" class="form-control" required></div><div class="mb-2"><label>IP</label><input type="text" name="ip" class="form-control" required></div></div><div class="modal-footer"><button class="btn btn-primary">确定</button></div></form></div></div></div>

<script>
function copyCmd(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.value).then(function () {
            alert('命令已复制！');
        });
        return;
    }
    el.select();
    document.execCommand('copy');
    alert('命令已复制！');
}
function toggleDnsForm() {
    var el = document.getElementById('dnsProviderSelect');
    if (!el) return;
    var val = el.value;
    var cf = document.getElementById('cfForm');
    var hw = document.getElementById('hwForm');
    if (!cf || !hw) return;
    if (val === 'huaweicloud') {
        hw.style.display = 'block';
        cf.style.display = 'none';
    } else {
        hw.style.display = 'none';
        cf.style.display = 'block';
    }
}
</script>
<script>
toggleDnsForm();
new Chart(document.getElementById('cpuChart'), {
    type: 'doughnut',
    data: {labels: ['已用', '空闲'], datasets: [{data: [<?= $avgCPU ?>, <?= max(0, 100 - $avgCPU) ?>], backgroundColor: ['#dc3545', '#198754']}]},
    options: {plugins: {title: {display: true, text: '平均 CPU'}}}
});
new Chart(document.getElementById('ramChart'), {
    type: 'doughnut',
    data: {labels: ['已用', '余量'], datasets: [{data: [<?= min(100, round($avgRAM / 100)) ?>, <?= max(0, 100 - min(100, round($avgRAM / 100))) ?>], backgroundColor: ['#fd7e14', '#0d6efd']}]},
    options: {plugins: {title: {display: true, text: '平均 RAM(估算)'}}}
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
