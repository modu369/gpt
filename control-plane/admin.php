<?php
/**
 * admin.php - 旗舰版 V4.0 (完整功能版)
 * * 变更日志：
 * 1. [恢复] 系统设置 Tab (修改账号/密码/Slug)。
 * 2. [恢复] 安全入口隐藏逻辑 (非 Slug 访问报 404)。
 * 3. [恢复] 右上角安全退出按钮。
 * 4. [优化] 告警中心内嵌显示，不再跳转外部文件。
 * 5. [保留] V3.0 的仪表盘图表与独立 IP 池管理。
 */

session_start();
require_once 'db.php';

// ================= 1. 配置获取与安全入口校验 =================
function get_setting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT value_json FROM settings WHERE key_name = ?");
    $stmt->execute([$key]); 
    $val = $stmt->fetchColumn(); 
    return $val ? json_decode($val, true) : $default;
}

$CONF_USER = get_setting($pdo, 'admin_user', 'admin');
$CONF_PASS = get_setting($pdo, 'admin_pass', 'admin123');
$CONF_SLUG = get_setting($pdo, 'admin_slug', 'yun123');

// 退出登录逻辑
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?slug=" . $CONF_SLUG);
    exit;
}

// 安全入口隐藏逻辑 (防扫描)
$request_uri = $_SERVER['REQUEST_URI'];
$url_slug = $_GET['slug'] ?? '';
if (!isset($_SESSION['is_admin']) && strpos($request_uri, $CONF_SLUG) === false && $url_slug !== $CONF_SLUG) {
    http_response_code(404);
    echo "404 Not Found";
    exit;
}

// ================= 2. 登录鉴权 =================
if (!isset($_SESSION['is_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['username']??'') === $CONF_USER && ($_POST['password']??'') === $CONF_PASS) {
        $_SESSION['is_admin'] = true; 
        header("Location: ".$_SERVER['REQUEST_URI']); 
        exit;
    }
    // 登录界面
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <title>登录 - CDN 控制台</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>body{background-color:#f5f5f5;display:flex;align-items:center;padding-top:40px;padding-bottom:40px;height:100vh;}.form-signin{width:100%;max-width:330px;padding:15px;margin:auto;}</style>
    </head>
    <body class="text-center">
        <main class="form-signin">
            <form method="post">
                <h1 class="h3 mb-3 fw-normal">CDN 管理后台</h1>
                <input type="text" name="username" class="form-control mb-2" placeholder="用户名" required autofocus>
                <input type="password" name="password" class="form-control mb-3" placeholder="密码" required>
                <button class="w-100 btn btn-lg btn-primary" type="submit">登录</button>
            </form>
        </main>
    </body>
    </html>
    <?php
    exit;
}

// ================= 3. 业务逻辑处理 =================
$message = '';
$active_tab = $_REQUEST['tab'] ?? 'nodes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if(isset($_POST['tab'])) $active_tab = $_POST['tab'];

    try {
        // --- 域名管理 ---
        if ($action === 'add_domain') {
            $domain = trim($_POST['domain']);
            if ($domain) {
                $pdo->prepare("INSERT INTO domains (domain) VALUES (?)")->execute([$domain]);
                $message = "<div class='alert alert-success'>域名 $domain 添加成功</div>";
            }
        }
        elseif ($action === 'del_domain') {
            $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$_POST['id']]);
            $message = "<div class='alert alert-success'>域名已删除</div>";
        }
        
        // --- 节点管理 ---
        elseif ($action === 'add_node') {
            $name = $_POST['hostname']; $ip = $_POST['ip']; $secret = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO nodes (hostname, ip_address, secret_key) VALUES (?, ?, ?)")->execute([$name, $ip, $secret]);
            $message = "<div class='alert alert-success'>节点添加成功</div>";
        }
        elseif ($action === 'del_node') {
            $pdo->prepare("DELETE FROM nodes WHERE id=?")->execute([$_POST['id']]);
        }
        elseif ($action === 'update_node_config') {
            $id = intval($_POST['id']);
            $pdo->prepare("UPDATE nodes SET traffic_limit=?, weight=?, max_bandwidth=?, max_ram=? WHERE id=?")
                ->execute([$_POST['traffic_limit'], $_POST['weight'], $_POST['max_bandwidth'], $_POST['max_ram'], $id]);
            $message = "<div class='alert alert-success'>节点配置已保存</div>";
        }
        
        // --- IP 池管理 ---
        elseif ($action === 'save_ips') {
            $raw_ips = preg_split('/[\r\n,]+/', $_POST['cf_ips_list']);
            $ips = [];
            foreach ($raw_ips as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
            $ips = array_unique($ips);
            if(empty($ips)) $ips = ["1.0.0.1"];

            $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('cf_ips', ?)")->execute([json_encode(array_values($ips))]);
            
            // 同步到 cf_ip_pool 表
            $stmtInsert = $pdo->prepare("INSERT IGNORE INTO cf_ip_pool (ip_address) VALUES (?)");
            foreach ($ips as $ip) { $stmtInsert->execute([$ip]); }
            if (!empty($ips)) {
                $placeholders = implode(',', array_fill(0, count($ips), '?'));
                $stmtDel = $pdo->prepare("DELETE FROM cf_ip_pool WHERE ip_address NOT IN ($placeholders)");
                $stmtDel->execute($ips);
            }
            $message = "<div class='alert alert-success'>IP 池已更新并同步</div>";
        }
        
        // --- DNS 配置 ---
        elseif ($action === 'save_dns_config') {
            $cfg = ['dns_provider', 'cf_email', 'cf_key', 'cf_zone_id', 'cf_record_name', 'hw_ak', 'hw_sk', 'hw_zone_id', 'hw_region'];
            foreach($cfg as $k) {
                if(isset($_POST[$k])) {
                    $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES (?, ?)")->execute([$k, json_encode(trim($_POST[$k]))]);
                }
            }
            $message = "<div class='alert alert-success'>DNS API 配置已保存</div>";
        }
        
        // --- 证书管理 ---
        elseif ($action === 'apply_cert') {
            $mode = $_POST['mode'];
            $prov = ($mode=='auto' && get_setting($pdo,'dns_provider','')=='huaweicloud') ? 'huaweicloud' : 'cloudflare';
            $pdo->prepare("INSERT INTO certificates (domain, mode, provider, auto_renew, apply_status, status_msg) VALUES (?, ?, ?, ?, 'processing', '等待处理...')")
                ->execute([$_POST['domain'], $mode, $prov, isset($_POST['auto_renew'])?1:0]);
            $message = "<div class='alert alert-info'>申请已提交</div>";
        }
        elseif ($action === 'del_cert') {
            $pdo->prepare("DELETE FROM certificates WHERE id=?")->execute([$_POST['id']]);
            $message = "<div class='alert alert-success'>证书已删除</div>";
        }
        elseif ($action === 'verify_manual_cert') {
            $pdo->prepare("UPDATE certificates SET apply_status='verifying', status_msg='等待验证...' WHERE id=?")->execute([$_POST['id']]);
            $message = "<div class='alert alert-warning'>已提交验证请求</div>";
        }
        
        // --- 告警管理 ---
        elseif ($action === 'mark_read') {
            $pdo->prepare("UPDATE node_alerts SET is_read=1 WHERE id=?")->execute([$_POST['id']]);
        }
        elseif ($action === 'mark_all_read') {
            $pdo->query("UPDATE node_alerts SET is_read=1");
            $message = "<div class='alert alert-success'>所有告警已标记为已读</div>";
        }
        
        // --- 系统设置 (新增) ---
        elseif ($action === 'save_settings') {
            if(!empty($_POST['admin_user'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_user', ?)")->execute([json_encode($_POST['admin_user'])]);
            if(!empty($_POST['admin_pass'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_pass', ?)")->execute([json_encode($_POST['admin_pass'])]);
            if(!empty($_POST['admin_slug'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_slug', ?)")->execute([json_encode($_POST['admin_slug'])]);
            
            $message = "<div class='alert alert-success'>系统设置已更新，下次登录请使用新凭据/入口</div>";
            // 刷新本地变量
            $CONF_USER = $_POST['admin_user']; $CONF_PASS = $_POST['admin_pass']; $CONF_SLUG = $_POST['admin_slug'];
        }

    } catch (Exception $e) {
        $message = "<div class='alert alert-danger'>操作失败: " . $e->getMessage() . "</div>";
    }
}

// ================= 4. 数据聚合查询 =================
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

// 默认值处理
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
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-nodes" type="button"><i class="bi bi-hdd-network"></i> 监控与节点</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ips" type="button"><i class="bi bi-clouds"></i> 中转网络</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-domains" type="button"><i class="bi bi-globe"></i> 域名管理</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-dns" type="button"><i class="bi bi-gear"></i> DNS 配置</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cert" type="button"><i class="bi bi-shield-lock"></i> SSL 证书</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-alerts" type="button"><i class="bi bi-bell"></i> 告警中心</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-settings" type="button"><i class="bi bi-gear-wide-connected"></i> 系统设置</button></li>
    </ul>

    <div class="tab-content">

        <div class="tab-pane fade" id="tab-nodes">
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
                        <thead class="table-light"><tr><th>状态</th><th>节点信息</th><th>实时负载</th><th>流量统计</th><th>策略配置</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php
                            $nodes = $pdo->query("SELECT * FROM nodes ORDER BY id DESC")->fetchAll();
                            foreach($nodes as $node):
                                $is_online = (time()-$node['last_heartbeat'])<65;
                                $cmd = "wget -O install_node.sh {$master_url}/install_node.sh && chmod +x install_node.sh && ./install_node.sh -master {$master_url} -secret {$node['secret_key']}";
                            ?>
                            <tr>
                                <td>
                                    <span class="status-dot <?= $is_online?'bg-online':'bg-offline' ?>"></span>
                                    <?= $is_online ? '<span class="text-success small">在线</span>' : '<span class="text-danger small">离线</span>' ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($node['hostname']) ?></strong><br>
                                    <small class="text-muted font-monospace"><?= $node['ip_address'] ?></small><br>
                                    <button class="btn btn-sm btn-link p-0 small" onclick="copyCmd('<?= $cmd ?>')">📋 复制安装命令</button>
                                </td>
                                <td>
                                    <div class="small">CPU: <span class="<?= $node['cpu_usage']>80?'text-danger':'' ?>"><?= $node['cpu_usage'] ?>%</span></div>
                                    <div class="small">RAM: <?= $node['ram_usage'] ?> MB</div>
                                </td>
                                <td>
                                    <div class="small">已用: <?= round($node['traffic_used']/1073741824,2) ?> GB</div>
                                    <div class="small text-muted">带宽: <?= $node['current_bandwidth'] ?> Mbps</div>
                                </td>
                                <td style="min-width: 250px;">
                                    <form method="post" class="row g-1">
                                        <input type="hidden" name="tab" value="nodes">
                                        <input type="hidden" name="action" value="update_node_config">
                                        <input type="hidden" name="id" value="<?= $node['id'] ?>">
                                        <div class="col-4" title="调度权重"><input type="number" name="weight" value="<?= $node['weight'] ?>" class="form-control form-control-sm" placeholder="权重"></div>
                                        <div class="col-4" title="带宽限制"><input type="number" name="max_bandwidth" value="<?= $node['max_bandwidth'] ?>" class="form-control form-control-sm" placeholder="限速"></div>
                                        <div class="col-4" title="内存限制"><input type="number" name="max_ram" value="<?= $node['max_ram'] ?>" class="form-control form-control-sm" placeholder="内存"></div>
                                        <div class="col-12 mt-1"><button class="btn btn-sm btn-outline-secondary w-100" style="--bs-btn-padding-y: .25rem;">保存配置</button></div>
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

        <div class="tab-pane fade" id="tab-ips">
            <div class="row">
                <div class="col-md-5">
                    <div class="card h-100">
                        <div class="card-header bg-primary text-white"><i class="bi bi-pencil-square"></i> IP 池配置</div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="tab" value="ips">
                                <input type="hidden" name="action" value="save_ips">
                                <div class="mb-3">
                                    <label class="form-label">Cloudflare 优选 IP 列表</label>
                                    <textarea name="cf_ips_list" class="form-control font-monospace" rows="12"><?= implode("\n", get_setting($pdo,'cf_ips',["1.0.0.1"])) ?></textarea>
                                </div>
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

        <div class="tab-pane fade" id="tab-domains">
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

        <div class="tab-pane fade" id="tab-dns">
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
                            <label class="form-label fw-bold">调度记录名称 (RR)</label>
                            <div class="input-group w-50"><input type="text" name="cf_record_name" class="form-control" value="<?= htmlspecialchars(get_setting($pdo,'cf_record_name','cdn')) ?>"><span class="input-group-text">.yourdomain.com</span></div>
                        </div>
                        <button class="btn btn-warning"><i class="bi bi-save"></i> 保存接口配置</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-cert">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <span>SSL 证书自动化</span>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCertModal">+ 申请证书</button>
                </div>
                <div class="card-body">
                    <table class="table align-middle">
                        <thead><tr><th>域名</th><th>状态</th><th>验证模式</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach($pdo->query("SELECT * FROM certificates ORDER BY id DESC")->fetchAll() as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['domain']) ?></strong><br><small class="text-muted"><?= $c['status_msg'] ?></small></td>
                                <td>
                                    <?php if($c['status'] == 1): ?>
                                        <span class="badge bg-success">已签发</span>
                                        <div class="small text-muted">过期: <?= date('Y-m-d', $c['expire_time']) ?></div>
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
                                <td><?= $c['mode']=='auto'?'API 自动':'手动 DNS' ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('删?');">
                                        <input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="del_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button class="btn btn-sm btn-danger">删除</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-alerts">
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

        <div class="tab-pane fade" id="tab-settings">
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
    var triggerEl = document.querySelector('button[data-bs-target="#tab-' + activeTab + '"]');
    if (triggerEl) { (new bootstrap.Tab(triggerEl)).show(); }
    
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
