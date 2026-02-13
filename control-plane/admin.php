<?php
/**
 * admin.php - 旗舰版 V6.3 (修复 Tab 切换与 Nginx 兼容性)
 * * 包含功能：
 * 1. 节点管理：显示 CPU 核心数、最大带宽上限。
 * 2. SSL证书：支持 Let's Encrypt/ZeroSSL 切换，强制续费。
 * 3. 完整模块：IP池、域名、DNS配置、告警中心、系统设置。
 * 4. 体验优化：自动刷新、PRG防重提交。
 * 5. 修复：Tab 链接显式指向脚本文件，解决 Nginx try_files 吞参问题。
 */

session_start();
require_once 'db.php';

// ================= 基础函数 =================
function get_setting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT value_json FROM settings WHERE key_name = ?");
    $stmt->execute([$key]); 
    $val = $stmt->fetchColumn(); 
    return $val ? json_decode($val, true) : $default;
}

function trigger_cert_cron(): void {
    $script = __DIR__ . '/cron_cert.php';
    $php = PHP_BINARY ?: '/usr/bin/php';
    $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' >/dev/null 2>&1 &';
    exec($cmd);
}

$CONF_USER = get_setting($pdo, 'admin_user', 'admin');
$CONF_PASS = get_setting($pdo, 'admin_pass', 'admin123');
$CONF_SLUG = get_setting($pdo, 'admin_slug', 'yun123');

// 退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['SCRIPT_NAME'] . "?slug=" . $CONF_SLUG);
    exit;
}

// 安全入口校验
$request_uri = $_SERVER['REQUEST_URI'];
$url_slug = $_GET['slug'] ?? '';
if (!isset($_SESSION['is_admin']) && strpos($request_uri, $CONF_SLUG) === false && $url_slug !== $CONF_SLUG) {
    http_response_code(404);
    echo "404 Not Found";
    exit;
}

// 登录鉴权
if (!isset($_SESSION['is_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['username']??'') === $CONF_USER && ($_POST['password']??'') === $CONF_PASS) {
        $_SESSION['is_admin'] = true; 
        header("Location: " . $_SERVER['REQUEST_URI']); 
        exit;
    }
    ?>
    <!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{display:flex;align-items:center;padding-top:40px;height:100vh;background:#f5f5f5}.form-signin{width:100%;max-width:330px;margin:auto;padding:15px}</style></head><body class="text-center"><main class="form-signin"><form method="post"><h1 class="h3 mb-3 fw-normal">CDN Admin</h1><input type="text" name="username" class="form-control mb-2" placeholder="User" required autofocus><input type="password" name="password" class="form-control mb-3" placeholder="Pass" required><button class="w-100 btn btn-lg btn-primary" type="submit">Sign in</button></form></main></body></html>
    <?php
    exit;
}

// ================= 业务逻辑处理 =================
$message = '';
$active_tab = $_GET['tab'] ?? 'nodes';

if (isset($_SESSION['flash_msg'])) {
    $message = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if(isset($_POST['tab'])) $active_tab = $_POST['tab'];
    $temp_msg = '';

    try {
        // --- 域名管理 ---
        if ($action === 'add_domain') {
            $domain = trim($_POST['domain']);
            if ($domain) {
                $pdo->prepare("INSERT INTO domains (domain) VALUES (?)")->execute([$domain]);
                $temp_msg = "<div class='alert alert-success'>域名 $domain 添加成功</div>";
            }
        }
        elseif ($action === 'del_domain') {
            $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$_POST['id']]);
            $temp_msg = "<div class='alert alert-success'>域名已删除</div>";
        }
        
        // --- 节点管理 ---
        elseif ($action === 'add_node') {
            $name = $_POST['hostname']; $ip = $_POST['ip']; $secret = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO nodes (hostname, ip_address, secret_key) VALUES (?, ?, ?)")->execute([$name, $ip, $secret]);
            $temp_msg = "<div class='alert alert-success'>节点添加成功</div>";
        }
        elseif ($action === 'del_node') {
            $pdo->prepare("DELETE FROM nodes WHERE id=?")->execute([$_POST['id']]);
        }
        elseif ($action === 'update_node_config') {
            $id = intval($_POST['id']);
            $traffic_enable = isset($_POST['traffic_limit_enable']) ? 1 : 0;
            
            $pdo->prepare("UPDATE nodes SET 
                weight=?, 
                max_bandwidth=?, 
                traffic_limit=?, 
                traffic_limit_enable=?, 
                traffic_count_mode=?, 
                traffic_alert_pct=? 
                WHERE id=?")
                ->execute([
                    intval($_POST['weight'] ?? 0),
                    intval($_POST['max_bandwidth'] ?? 0),
                    intval($_POST['traffic_limit'] ?? 0),
                    $traffic_enable,
                    intval($_POST['traffic_count_mode'] ?? 0),
                    max(1, intval($_POST['traffic_alert_pct'] ?? 5)),
                    $id
                ]);
            $temp_msg = "<div class='alert alert-success'>节点策略已更新</div>";
        }
        
        // --- IP 池管理 ---
        elseif ($action === 'save_ips') {
            $raw_ips = preg_split('/[\r\n,]+/', $_POST['cf_ips_list']);
            $ips = array_filter(array_unique($raw_ips), function($ip){ return filter_var(trim($ip), FILTER_VALIDATE_IP); });
            if(empty($ips)) $ips = ["1.0.0.1"];
            $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('cf_ips', ?)")->execute([json_encode(array_values($ips))]);
            $pdo->prepare("INSERT IGNORE INTO cf_ip_pool (ip_address) VALUES (?)")->execute([implode("'),('", $ips)]);
            $temp_msg = "<div class='alert alert-success'>IP 池已更新</div>";
        }
        
        // --- DNS 配置 ---
        elseif ($action === 'save_dns_config') {
            $cfg = ['dns_provider', 'cf_email', 'cf_key', 'cf_zone_id', 'cf_record_name', 'hw_ak', 'hw_sk', 'hw_zone_id', 'hw_region'];
            foreach($cfg as $k) {
                if(isset($_POST[$k])) {
                    $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES (?, ?)")->execute([$k, json_encode(trim($_POST[$k]))]);
                }
            }
            $temp_msg = "<div class='alert alert-success'>DNS 配置已保存</div>";
        }
        
        // --- CA 设置 ---
        elseif ($action === 'save_cert_config') {
            $provider = ($_POST['cert_ca_provider'] ?? 'letsencrypt') === 'zerossl' ? 'zerossl' : 'letsencrypt';
            $email = trim((string)($_POST['cert_ca_email'] ?? ''));
            $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES (?, ?)")->execute(['cert_ca_provider', json_encode($provider)]);
            $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES (?, ?)")->execute(['cert_ca_email', json_encode($email)]);
            $temp_msg = "<div class='alert alert-success'>证书颁发机构设置已保存</div>";
        }

        // --- 证书操作 ---
        elseif ($action === 'apply_cert') {
            $mode = $_POST['mode'];
            $prov = ($mode=='auto' && get_setting($pdo,'dns_provider','')=='huaweicloud') ? 'huaweicloud' : 'cloudflare';
            $pdo->prepare("INSERT INTO certificates (domain, mode, provider, auto_renew, apply_status, status_msg) VALUES (?, ?, ?, ?, 'processing', '等待处理...')")
                ->execute([$_POST['domain'], $mode, $prov, isset($_POST['auto_renew'])?1:0]);
            trigger_cert_cron(); 
            $temp_msg = "<div class='alert alert-info'>申请已提交，后台处理中...</div>";
        }
        elseif ($action === 'verify_manual_cert') {
            $pdo->prepare("UPDATE certificates SET apply_status='verifying', status_msg='等待验证...' WHERE id=?")->execute([$_POST['id']]);
            trigger_cert_cron(); 
            $temp_msg = "<div class='alert alert-warning'>验证请求已提交...</div>";
        }
        elseif ($action === 'force_renew_cert') {
            $pdo->prepare("UPDATE certificates SET apply_status='force_renew', status_msg='等待强制续费...' WHERE id=?")->execute([$_POST['id']]);
            trigger_cert_cron();
            $temp_msg = "<div class='alert alert-warning'>强制续费请求已提交...</div>";
        }
        elseif ($action === 'del_cert') {
            $pdo->prepare("DELETE FROM certificates WHERE id=?")->execute([$_POST['id']]);
            $temp_msg = "<div class='alert alert-success'>证书已删除</div>";
        }
        
        // --- 告警 ---
        elseif ($action === 'mark_read') {
            $pdo->prepare("UPDATE node_alerts SET is_read=1 WHERE id=?")->execute([$_POST['id']]);
        }
        elseif ($action === 'mark_all_read') {
            $pdo->query("UPDATE node_alerts SET is_read=1");
            $temp_msg = "<div class='alert alert-success'>所有告警已标记为已读</div>";
        }
        
        // --- 系统设置 ---
        elseif ($action === 'save_settings') {
            if(!empty($_POST['admin_user'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_user', ?)")->execute([json_encode($_POST['admin_user'])]);
            if(!empty($_POST['admin_pass'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_pass', ?)")->execute([json_encode($_POST['admin_pass'])]);
            if(!empty($_POST['admin_slug'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_slug', ?)")->execute([json_encode($_POST['admin_slug'])]);
            $temp_msg = "<div class='alert alert-success'>系统设置已更新</div>";
            $CONF_USER = $_POST['admin_user']; $CONF_PASS = $_POST['admin_pass']; $CONF_SLUG = $_POST['admin_slug'];
        }

    } catch (Exception $e) {
        $temp_msg = "<div class='alert alert-danger'>操作失败: " . $e->getMessage() . "</div>";
    }

    if (!empty($temp_msg)) $_SESSION['flash_msg'] = $temp_msg;
    // 使用 SCRIPT_NAME 确保跳转正确
    header("Location: " . $_SERVER['SCRIPT_NAME'] . "?slug=" . $CONF_SLUG . "&tab=" . $active_tab);
    exit;
}

// ================= 数据查询 =================
$stats = $pdo->query("SELECT 
    COUNT(*) as total_nodes,
    SUM(CASE WHEN last_heartbeat > unix_timestamp()-65 THEN 1 ELSE 0 END) as online_nodes,
    SUM(ram_usage) as used_ram,
    SUM(max_ram) as total_ram,
    SUM(current_bandwidth) as used_bw,
    SUM(max_bandwidth) as total_bw,
    AVG(NULLIF(cpu_usage,0)) as avg_cpu
    FROM nodes WHERE status=1")->fetch();

$unread_alert_count = $pdo->query("SELECT count(*) FROM node_alerts WHERE is_read=0")->fetchColumn();

// 默认值
$total_nodes = $stats['total_nodes'] ?: 0;
$online_nodes = $stats['online_nodes'] ?: 0;
$offline_nodes = $total_nodes - $online_nodes;
$used_ram = round($stats['used_ram'] ?: 0, 1);
$total_ram = round($stats['total_ram'] ?: 0, 1);
$free_ram = max(0, $total_ram - $used_ram);
$used_bw = round($stats['used_bw'] ?: 0, 1);
$total_bw = round($stats['total_bw'] ?: 0, 1);
$free_bw = max(0, $total_bw - $used_bw);
$avg_cpu = round($stats['avg_cpu'] ?: 0, 1);
$free_cpu = 100 - $avg_cpu;

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$master_url = $protocol . $_SERVER['HTTP_HOST'];
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>CDN 智能控制台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .status-dot {height:10px;width:10px;border-radius:50%;display:inline-block;margin-right:5px;}
        .bg-online{background:#198754;box-shadow: 0 0 5px #198754;}
        .bg-offline{background:#dc3545;box-shadow: 0 0 5px #dc3545;}
        .nav-tabs .nav-link { color: #495057; }
        .nav-tabs .nav-link.active { font-weight: bold; border-top: 3px solid #0d6efd; color: #0d6efd; }
        .card { border: none; box-shadow: 0 2px 4px rgba(0,0,0,.05); }
        .chart-container { position: relative; height: 120px; width: 100%; display: flex; justify-content: center; }
        .stat-val { font-size: 1.2rem; font-weight: bold; }
        .stat-label { font-size: 0.8rem; color: #6c757d; }
    </style>
</head>
<body class="bg-light pb-5">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="#"><i class="bi bi-lightning-charge-fill"></i> CDN 智能调度系统</a>
        <div class="d-flex align-items-center gap-3">
            <?php if($unread_alert_count > 0): ?>
                <span class="badge bg-danger rounded-pill">⚠️ <?= $unread_alert_count ?> 告警</span>
            <?php endif; ?>
            <div class="text-white small">User: <?= htmlspecialchars($CONF_USER) ?></div>
            <a href="?logout=1" class="btn btn-sm btn-outline-light" onclick="return confirm('确定退出登录?')">退出</a>
        </div>
    </div>
</nav>

<div class="container">
    <?= $message ?>

    <ul class="nav nav-tabs mb-3" id="mainTab" role="tablist">
        <li class="nav-item"><a class="nav-link <?= $active_tab=='nodes'?'active':'' ?>" href="<?= $_SERVER['SCRIPT_NAME'] ?>?slug=<?= $CONF_SLUG ?>&tab=nodes"><i class="bi bi-hdd-network"></i> 监控与节点</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='ips'?'active':'' ?>" href="<?= $_SERVER['SCRIPT_NAME'] ?>?slug=<?= $CONF_SLUG ?>&tab=ips"><i class="bi bi-clouds"></i> 中转网络</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='domains'?'active':'' ?>" href="<?= $_SERVER['SCRIPT_NAME'] ?>?slug=<?= $CONF_SLUG ?>&tab=domains"><i class="bi bi-globe"></i> 域名管理</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='dns'?'active':'' ?>" href="<?= $_SERVER['SCRIPT_NAME'] ?>?slug=<?= $CONF_SLUG ?>&tab=dns"><i class="bi bi-gear"></i> DNS 配置</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='cert'?'active':'' ?>" href="<?= $_SERVER['SCRIPT_NAME'] ?>?slug=<?= $CONF_SLUG ?>&tab=cert"><i class="bi bi-shield-lock"></i> SSL 证书</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='alerts'?'active':'' ?>" href="<?= $_SERVER['SCRIPT_NAME'] ?>?slug=<?= $CONF_SLUG ?>&tab=alerts"><i class="bi bi-bell"></i> 告警中心</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='settings'?'active':'' ?>" href="<?= $_SERVER['SCRIPT_NAME'] ?>?slug=<?= $CONF_SLUG ?>&tab=settings"><i class="bi bi-gear-wide-connected"></i> 系统设置</a></li>
    </ul>

    <div class="tab-content">

        <div class="tab-pane <?= $active_tab=='nodes'?'active':'fade' ?>" id="tab-nodes">
            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6"><div class="card p-3 h-100 text-center"><div class="chart-container mb-2"><canvas id="chartNodes"></canvas></div><div class="stat-val"><?= $online_nodes ?> / <?= $total_nodes ?></div><div class="stat-label">可用节点</div></div></div>
                <div class="col-md-3 col-6"><div class="card p-3 h-100 text-center"><div class="chart-container mb-2"><canvas id="chartCpu"></canvas></div><div class="stat-val"><?= $avg_cpu ?>%</div><div class="stat-label">平均 CPU</div></div></div>
                <div class="col-md-3 col-6"><div class="card p-3 h-100 text-center"><div class="chart-container mb-2"><canvas id="chartRam"></canvas></div><div class="stat-val"><?= $used_ram ?> <small class="text-muted">/ <?= $total_ram ?: '∞' ?> MB</small></div><div class="stat-label">总内存</div></div></div>
                <div class="col-md-3 col-6"><div class="card p-3 h-100 text-center"><div class="chart-container mb-2"><canvas id="chartBw"></canvas></div><div class="stat-val"><?= $used_bw ?> <small class="text-muted">/ <?= $total_bw ?: '∞' ?> Mbps</small></div><div class="stat-label">总带宽</div></div></div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">边缘节点列表</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addNodeModal"><i class="bi bi-plus-lg"></i> 新增节点</button>
            </div>
            <div class="card">
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>状态</th><th>节点信息</th><th>资源负载</th><th>流量统计</th><th width="35%">策略配置</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php
                            $nodes = $pdo->query("SELECT * FROM nodes ORDER BY id DESC")->fetchAll();
                            foreach($nodes as $node):
                                $is_online = (time()-$node['last_heartbeat'])<65;
                                $cmd = "wget -O install_node.sh {$master_url}/install_node.sh && chmod +x install_node.sh && ./install_node.sh -master {$master_url}/api -secret {$node['secret_key']}";
                                $limit_gb = (int)($node['traffic_limit'] ?? 0);
                                $used_gb = round(((float)$node['traffic_used'])/1073741824, 2);
                                $pct = ($limit_gb > 0) ? round(($used_gb / $limit_gb) * 100, 1) : 0;
                                $threshold_pct = max(1, (int)($node['traffic_alert_pct'] ?? 5));
                                $is_paused = ((int)($node['traffic_limit_enable'] ?? 0) === 1 && $limit_gb > 0 && (100 - $pct) < $threshold_pct);
                            ?>
                            <tr class="<?= $is_paused ? 'table-warning' : '' ?>">
                                <td>
                                    <span class="status-dot <?= $is_online?'bg-online':'bg-offline' ?>"></span>
                                    <?= $is_online ? '<span class="text-success small">在线</span>' : '<span class="text-danger small">离线</span>' ?>
                                    <?php if($is_paused): ?><br><span class="badge bg-warning text-dark">流量耗尽</span><?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($node['hostname']) ?></strong><br>
                                    <small class="text-muted font-monospace"><?= $node['ip_address'] ?></small><br>
                                    <button class="btn btn-sm btn-link p-0 small" onclick="copyCmd('<?= $cmd ?>')">📋 复制安装命令</button>
                                </td>
                                <td>
                                    <div class="small">
                                        CPU: <span class="<?= $node['cpu_usage']>80?'text-danger':'' ?>"><?= $node['cpu_usage'] ?>%</span> 
                                        <span class="text-muted">/ <?= $node['cpu_cores'] ?: '?' ?> C</span>
                                    </div>
                                    <div class="small">RAM: <?= $node['ram_usage'] ?> / <?= $node['max_ram'] ?: '?' ?> MB</div>
                                </td>
                                <td>
                                    <div class="small">已用: <?= $used_gb ?> GB</div>
                                    <?php if((int)($node['traffic_limit_enable'] ?? 0) === 1): ?>
                                        <div class="progress" style="height:5px; width:110px;">
                                            <div class="progress-bar <?= $pct>90?'bg-danger':'' ?>" style="width: <?= min(100, $pct) ?>%"></div>
                                        </div>
                                        <small class="text-muted">限额: <?= $limit_gb ?> GB</small>
                                    <?php else: ?>
                                        <small class="text-muted">无限制</small>
                                    <?php endif; ?>
                                    <div class="small text-muted">
                                        带宽: <?= $node['current_bandwidth'] ?> 
                                        <span class="text-muted">/ <?= $node['max_bandwidth'] ?: '?' ?> Mbps</span>
                                    </div>
                                </td>
                                <td style="min-width: 360px;">
                                    <form method="post" class="row g-2 align-items-center">
                                        <input type="hidden" name="tab" value="nodes">
                                        <input type="hidden" name="action" value="update_node_config">
                                        <input type="hidden" name="id" value="<?= $node['id'] ?>">
                                        <div class="col-6">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">权重</span>
                                                <input type="number" name="weight" value="<?= $node['weight'] ?>" class="form-control text-center">
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">限速Mbps</span>
                                                <input type="number" name="max_bandwidth" value="<?= $node['max_bandwidth'] ?>" class="form-control text-center">
                                            </div>
                                        </div>
                                        <div class="col-12 d-flex gap-2 align-items-center bg-light p-1 rounded border">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" name="traffic_limit_enable" value="1" <?= (int)($node['traffic_limit_enable'] ?? 0) === 1 ? 'checked' : '' ?>>
                                                <label class="form-check-label small">开启流控</label>
                                            </div>
                                            <select name="traffic_count_mode" class="form-select form-select-sm py-0" style="width:auto">
                                                <option value="0" <?= (int)($node['traffic_count_mode'] ?? 0)===0?'selected':'' ?>>双向计费 (进+出)</option>
                                                <option value="1" <?= (int)($node['traffic_count_mode'] ?? 0)===1?'selected':'' ?>>单向计费 (仅出)</option>
                                            </select>
                                        </div>
                                        <div class="col-7">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">月流量GB</span>
                                                <input type="number" name="traffic_limit" value="<?= $node['traffic_limit'] ?>" class="form-control text-center">
                                            </div>
                                        </div>
                                        <div class="col-5">
                                            <div class="input-group input-group-sm" title="剩余流量低于此百分比时自动暂停">
                                                <span class="input-group-text">阈值%</span>
                                                <input type="number" name="traffic_alert_pct" value="<?= $node['traffic_alert_pct'] ?? 5 ?>" class="form-control text-center">
                                            </div>
                                        </div>
                                        <div class="col-12"><button class="btn btn-sm btn-outline-primary w-100">💾 保存策略</button></div>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('删?');">
                                        <input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="del_node"><input type="hidden" name="id" value="<?= $node['id'] ?>">
                                        <button class="btn btn-sm btn-link text-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane <?= $active_tab=='ips'?'active':'fade' ?>" id="tab-ips">
            <div class="row">
                <div class="col-md-5">
                    <div class="card h-100">
                        <div class="card-header bg-primary text-white"><i class="bi bi-pencil-square"></i> IP 池配置</div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="tab" value="ips">
                                <input type="hidden" name="action" value="save_ips">
                                <div class="mb-3"><label class="form-label">Cloudflare 优选 IP 列表</label><textarea name="cf_ips_list" class="form-control font-monospace" rows="12"><?= implode("\n", get_setting($pdo,'cf_ips',["1.0.0.1"])) ?></textarea></div>
                                <button class="btn btn-primary w-100"><i class="bi bi-save"></i> 保存并同步</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-activity"></i> IP 实时监控状态</div>
                        <div class="card-body table-responsive">
                            <table class="table table-sm table-striped">
                                <thead><tr><th>IP 地址</th><th>状态</th><th>延迟</th><th>连败</th><th>最后检测</th></tr></thead>
                                <tbody>
                                    <?php
                                    $pool = $pdo->query("SELECT * FROM cf_ip_pool ORDER BY status DESC, latency ASC")->fetchAll();
                                    foreach($pool as $p):
                                    ?>
                                    <tr>
                                        <td class="font-monospace"><?= $p['ip_address'] ?></td>
                                        <td><?= $p['status']==1 ? '<span class="badge bg-success">正常</span>' : '<span class="badge bg-danger">宕机</span>' ?></td>
                                        <td><?= $p['latency'] ?> ms</td>
                                        <td><?= $p['fail_count'] ?></td>
                                        <td class="small text-muted"><?= $p['last_check'] ? date('H:i:s', $p['last_check']) : '-' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane <?= $active_tab=='domains'?'active':'fade' ?>" id="tab-domains">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>域名白名单</span>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDomainModal">+ 添加域名</button>
                </div>
                <div class="card-body">
                    <table class="table">
                        <thead><tr><th>ID</th><th>域名</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach($pdo->query("SELECT * FROM domains ORDER BY id DESC")->fetchAll() as $d): ?>
                            <tr>
                                <td><?= $d['id'] ?></td>
                                <td><strong><?= htmlspecialchars($d['domain']) ?></strong></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('删?');">
                                        <input type="hidden" name="tab" value="domains"><input type="hidden" name="action" value="del_domain"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <button class="btn btn-sm btn-danger">移除</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane <?= $active_tab=='dns'?'active':'fade' ?>" id="tab-dns">
            <div class="card border-warning">
                <div class="card-header bg-warning-subtle text-dark"><i class="bi bi-gear-fill"></i> 智能 DNS 调度接口配置</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="tab" value="dns">
                        <input type="hidden" name="action" value="save_dns_config">
                        <div class="mb-4">
                            <label class="form-label fw-bold">DNS 服务提供商</label>
                            <select name="dns_provider" class="form-select w-50" onchange="toggleDns(this.value)">
                                <option value="cloudflare" <?= get_setting($pdo,'dns_provider','')=='cloudflare'?'selected':'' ?>>Cloudflare (API)</option>
                                <option value="huaweicloud" <?= get_setting($pdo,'dns_provider','')=='huaweicloud'?'selected':'' ?>>华为云 DNS (Huawei Cloud)</option>
                            </select>
                        </div>
                        <div id="cfForm" class="row g-3">
                            <div class="col-12"><h6 class="text-primary">Cloudflare 配置</h6></div>
                            <div class="col-md-4"><label class="form-label">Email</label><input type="text" name="cf_email" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'cf_email','')) ?>"></div>
                            <div class="col-md-4"><label class="form-label">Global API Key</label><input type="password" name="cf_key" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'cf_key','')) ?>"></div>
                            <div class="col-md-4"><label class="form-label">Zone ID</label><input type="text" name="cf_zone_id" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'cf_zone_id','')) ?>"></div>
                        </div>
                        <div id="hwForm" class="row g-3" style="display:none">
                            <div class="col-12"><h6 class="text-danger">华为云配置</h6></div>
                            <div class="col-md-3"><label class="form-label">AK</label><input type="text" name="hw_ak" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'hw_ak','')) ?>"></div>
                            <div class="col-md-3"><label class="form-label">SK</label><input type="password" name="hw_sk" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'hw_sk','')) ?>"></div>
                            <div class="col-md-3"><label class="form-label">Zone ID</label><input type="text" name="hw_zone_id" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'hw_zone_id','')) ?>"></div>
                            <div class="col-md-3"><label class="form-label">Region</label><input type="text" name="hw_region" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'hw_region','ap-southeast-1')) ?>"></div>
                        </div>
<div class="mb-3 mt-4">
    <label class="form-label fw-bold">调度域名 (完整域名)</label>
    <div class="input-group w-50">
        <span class="input-group-text">FQDN</span>
        <input type="text" name="cf_record_name" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'cf_record_name','cdn.example.com')) ?>" placeholder="例如: cdn.yourdomain.com">
    </div>
    <div class="form-text">请务必填写完整的用于调度的域名，例如 <code>cdn.kalaimg.top</code></div>
</div>
                        <button class="btn btn-warning"><i class="bi bi-save"></i> 保存接口配置</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="tab-pane <?= $active_tab=='cert'?'active':'fade' ?>" id="tab-cert">
            
            <div class="card mb-3 border-info">
                <div class="card-header bg-info-subtle"><i class="bi bi-sliders"></i> 证书颁发机构 (CA) 设置</div>
                <div class="card-body">
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="tab" value="cert">
                        <input type="hidden" name="action" value="save_cert_config">
                        <div class="col-md-4">
                            <label class="form-label">CA 提供商</label>
                            <select name="cert_ca_provider" class="form-select">
                                <option value="letsencrypt" <?= get_setting($pdo,'cert_ca_provider','letsencrypt')==='letsencrypt'?'selected':'' ?>>Let's Encrypt (默认/无需账户)</option>
                                <option value="zerossl" <?= get_setting($pdo,'cert_ca_provider','letsencrypt')==='zerossl'?'selected':'' ?>>ZeroSSL (需邮箱)</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">账号邮箱 (ZeroSSL 必填)</label>
                            <input type="email" name="cert_ca_email" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'cert_ca_email','')) ?>" placeholder="email@example.com">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-info w-100">保存设置</button>
                        </div>
                        <div class="col-12"><small class="text-muted">注意：ZeroSSL 必须提供有效邮箱进行注册。Let's Encrypt 默认无需邮箱。修改后对新申请生效。</small></div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span>SSL 证书自动化</span>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCertModal">+ 申请证书</button>
                </div>
                <div class="card-body">
                    <table class="table align-middle">
                        <thead><tr><th>域名</th><th>状态</th><th>到期时间</th><th>下次续签</th><th>验证模式</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach($pdo->query("SELECT * FROM certificates ORDER BY id DESC")->fetchAll() as $c): 
                                $exp = (int)$c['expire_time'];
                                $renewDate = $c['auto_renew'] ? date('Y-m-d', $exp - 10 * 86400) : '<span class="text-muted">手动</span>';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['domain']) ?></strong><br><small class="text-muted"><?= $c['status_msg'] ?></small></td>
                                <td>
                                    <?php if($c['status'] == 1): ?>
                                        <span class="badge bg-success">已签发</span>
                                    <?php elseif($c['apply_status']=='wait_verify'): ?>
                                        <div class="alert alert-warning p-2 mb-0 small border-warning">
                                            <strong>TXT 记录:</strong><br>
                                            <code class="user-select-all"><?= $c['dns_txt_domain'] ?></code><br>
                                            <code class="user-select-all"><?= $c['dns_txt_value'] ?></code>
                                            <form method="post" class="mt-2">
                                                <input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="verify_manual_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button class="btn btn-sm btn-success w-100">已添加，验证!</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= $c['apply_status'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $exp > 0 ? date('Y-m-d', $exp) : '-' ?></td>
                                <td><?= $renewDate ?></td>
                                <td><?= $c['mode']=='auto'?'API 自动':'手动 DNS' ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="force_renew_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button class="btn btn-outline-primary" onclick="return confirm('确定强制重签吗？')">续费</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="del_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button class="btn btn-outline-danger" onclick="return confirm('删?');">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane <?= $active_tab=='alerts'?'active':'fade' ?>" id="tab-alerts">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center bg-danger bg-opacity-10 text-danger">
                    <span>⚠️ 系统告警记录</span>
                    <?php if($unread_alert_count>0): ?>
                    <form method="post">
                        <input type="hidden" name="tab" value="alerts"><input type="hidden" name="action" value="mark_all_read">
                        <button class="btn btn-sm btn-outline-danger">全部标记已读</button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="card-body table-responsive">
                    <table class="table">
                        <thead><tr><th>时间</th><th>节点</th><th>类型</th><th>内容</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php 
                            $alerts=$pdo->query("SELECT * FROM node_alerts ORDER BY id DESC LIMIT 50")->fetchAll(); 
                            if(count($alerts)==0) echo "<tr><td colspan='5' class='text-center text-muted py-4'>无告警记录</td></tr>"; 
                            foreach($alerts as $a): 
                            ?>
                            <tr class="<?= $a['is_read']?'':'table-warning' ?>">
                                <td><?= $a['created_at'] ?></td>
                                <td><?= htmlspecialchars($a['node_name']) ?></td>
                                <td><span class="badge bg-secondary"><?= $a['type'] ?></span></td>
                                <td><?= htmlspecialchars($a['message']) ?></td>
                                <td>
                                    <?php if(!$a['is_read']): ?>
                                    <form method="post"><input type="hidden" name="tab" value="alerts"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-link">标为已读</button></form>
                                    <?php else: echo '<span class="text-muted small">已读</span>'; endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane <?= $active_tab=='settings'?'active':'fade' ?>" id="tab-settings">
            <div class="card" style="max-width:500px; margin:0 auto">
                <div class="card-header"><i class="bi bi-gear-wide-connected"></i> 系统安全设置</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="tab" value="settings">
                        <input type="hidden" name="action" value="save_settings">
                        <div class="mb-3">
                            <label class="form-label">管理员账号</label>
                            <input type="text" name="admin_user" class="form-control" value="<?= htmlspecialchars($CONF_USER) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">管理员密码</label>
                            <input type="text" name="admin_pass" class="form-control" value="<?= htmlspecialchars($CONF_PASS) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">安全入口 Slug</label>
                            <div class="input-group">
                                <span class="input-group-text">/admin.php?slug=</span>
                                <input type="text" name="admin_slug" class="form-control" value="<?= htmlspecialchars($CONF_SLUG) ?>" required>
                            </div>
                            <div class="form-text text-muted">修改后请务必记住，否则将无法访问后台 (防扫描)</div>
                        </div>
                        <button class="btn btn-danger w-100">更新设置</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="addNodeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">新增节点</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="add_node"><div class="mb-3"><label class="form-label">节点名称</label><input type="text" name="hostname" class="form-control" required></div><div class="mb-3"><label class="form-label">公网 IP</label><input type="text" name="ip" class="form-control" required></div></div><div class="modal-footer"><button class="btn btn-primary">确定</button></div></form></div></div>
<div class="modal fade" id="addDomainModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">添加域名</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="domains"><input type="hidden" name="action" value="add_domain"><div class="mb-3"><label class="form-label">域名</label><input type="text" name="domain" class="form-control" required></div></div><div class="modal-footer"><button class="btn btn-primary">确定</button></div></form></div></div>
<div class="modal fade" id="addCertModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">申请证书</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="apply_cert"><div class="mb-3"><label class="form-label">域名</label><input type="text" name="domain" class="form-control" required></div><div class="mb-3"><label class="form-label">验证模式</label><select name="mode" class="form-select"><option value="auto">API 自动验证</option><option value="manual">手动 DNS TXT</option></select></div><div class="form-check"><input type="checkbox" name="auto_renew" class="form-check-input" checked><label class="form-check-label">自动续费</label></div></div><div class="modal-footer"><button class="btn btn-primary">提交</button></div></form></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var activeTab = '<?= $active_tab ?>'; 
    var triggerEl = document.querySelector('a[href*="tab=' + activeTab + '"]');
    if (triggerEl) { (new bootstrap.Tab(triggerEl)).show(); }
    
    // [新增] 自动刷新逻辑 (只刷新列表内容)
    if(activeTab === 'cert' || activeTab === 'nodes') {
        setInterval(() => {
            fetch(window.location.href)
            .then(r => r.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                if(activeTab === 'cert') {
                    const newBody = doc.querySelector('#tab-cert tbody');
                    if(newBody) document.querySelector('#tab-cert tbody').innerHTML = newBody.innerHTML;
                } else if(activeTab === 'nodes') {
                    const newBody = doc.querySelector('#tab-nodes tbody');
                    if(newBody) document.querySelector('#tab-nodes tbody').innerHTML = newBody.innerHTML;
                }
            });
        }, 3000); // 3秒刷新
    }

    var dnsSelect = document.querySelector('select[name="dns_provider"]');
    if(dnsSelect) toggleDns(dnsSelect.value);

    // 绘制图表
    const commonOpt = { maintainAspectRatio: false, cutout: '70%', plugins: { legend: { display: false }, tooltip: { enabled: false } } };
    new Chart(document.getElementById('chartNodes'), { type: 'doughnut', data: { labels: ['OK', 'Down'], datasets: [{ data: [<?= $online_nodes ?>, <?= $offline_nodes ?>], backgroundColor: ['#198754', '#dc3545'], borderWidth: 0 }] }, options: commonOpt });
    new Chart(document.getElementById('chartCpu'), { type: 'doughnut', data: { labels: ['Used', 'Free'], datasets: [{ data: [<?= $avg_cpu ?>, <?= $free_cpu ?>], backgroundColor: ['#0d6efd', '#e9ecef'], borderWidth: 0 }] }, options: commonOpt });
    new Chart(document.getElementById('chartRam'), { type: 'doughnut', data: { labels: ['Used', 'Free'], datasets: [{ data: [<?= $used_ram ?>, <?= $free_ram ?>], backgroundColor: ['#6610f2', '#e9ecef'], borderWidth: 0 }] }, options: commonOpt });
    new Chart(document.getElementById('chartBw'), { type: 'doughnut', data: { labels: ['Used', 'Free'], datasets: [{ data: [<?= $used_bw ?>, <?= $free_bw ?>], backgroundColor: ['#fd7e14', '#e9ecef'], borderWidth: 0 }] }, options: commonOpt });
});
function toggleDns(val) {
    document.getElementById('hwForm').style.display = (val === 'huaweicloud' ? 'flex' : 'none');
    document.getElementById('cfForm').style.display = (val === 'cloudflare' ? 'flex' : 'none');
}
function copyCmd(text) {
    var t = document.createElement("textarea"); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t);
    alert('✅ 安装命令已复制');
}
</script>
</body>
</html>
