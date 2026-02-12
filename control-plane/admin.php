<?php
session_start();
require_once 'db.php';

// 1. 获取当前 Tab
$active_tab = $_REQUEST['tab'] ?? 'nodes';

// 配置获取函数
function get_setting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT value_json FROM settings WHERE key_name = ?");
    $stmt->execute([$key]); $val = $stmt->fetchColumn(); return $val ? json_decode($val, true) : $default;
}

// 2. 鉴权逻辑
$CONF_USER = get_setting($pdo, 'admin_user', 'admin');
$CONF_PASS = get_setting($pdo, 'admin_pass', 'admin123');
$CONF_SLUG = get_setting($pdo, 'admin_slug', 'yun123');

$request_uri = $_SERVER['REQUEST_URI'];
$url_slug = $_GET['slug'] ?? '';
if (!isset($_SESSION['is_admin']) && strpos($request_uri, $CONF_SLUG) === false && $url_slug !== $CONF_SLUG) {
    http_response_code(404); echo "404 Not Found"; exit;
}

if (!isset($_SESSION['is_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['username']??'') === $CONF_USER && ($_POST['password']??'') === $CONF_PASS) {
            $_SESSION['is_admin'] = true; header("Location: " . $_SERVER['REQUEST_URI']); exit;
        } else { $login_error = "账号或密码错误"; }
    }
    ?>
    <!DOCTYPE html><html><head><title>Login</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{background:#f0f2f5;display:flex;align-items:center;justify-content:center;height:100vh}.card{width:100%;max-width:400px;padding:2rem}</style></head><body><div class="card shadow"><h4 class="text-center mb-4 text-primary">CDN 智能控制台</h4><?php if(isset($login_error)): ?><div class="alert alert-danger"><?= $login_error ?></div><?php endif; ?><form method="post"><div class="mb-3"><label>账号</label><input type="text" name="username" class="form-control" required></div><div class="mb-4"><label>密码</label><input type="password" name="password" class="form-control" required></div><button class="btn btn-primary w-100">登录</button></form></div></body></html>
    <?php exit;
}

// 3. 数据处理与表单提交
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_tab = $_POST['tab'] ?? 'nodes';

    if ($action === 'add_domain') { try { $pdo->prepare("INSERT INTO domains (domain) VALUES (?)")->execute([trim($_POST['domain'])]); $message="<div class='alert alert-success'>添加成功</div>"; } catch(Exception $e){ $message="<div class='alert alert-danger'>失败</div>"; } }
    elseif ($action === 'del_domain') { $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$_POST['id']]); }
    elseif ($action === 'add_node') { $pdo->prepare("INSERT INTO nodes (hostname, ip_address, secret_key) VALUES (?, ?, ?)")->execute([$_POST['hostname'], $_POST['ip'], bin2hex(random_bytes(16))]); }
    elseif ($action === 'del_node') { $pdo->prepare("DELETE FROM nodes WHERE id=?")->execute([$_POST['id']]); }
    elseif ($action === 'update_node_config') { $pdo->prepare("UPDATE nodes SET traffic_limit=?, weight=?, max_bandwidth=?, max_ram=? WHERE id=?")->execute([$_POST['traffic_limit'], $_POST['weight'], $_POST['max_bandwidth'], $_POST['max_ram'], $_POST['id']]); $message = "<div class='alert alert-success'>配置保存成功</div>"; }
    elseif ($action === 'save_dns') {
        $keys = ['dns_provider', 'cf_email', 'cf_key', 'cf_zone_id', 'cf_record_name', 'hw_ak', 'hw_sk', 'hw_zone_id', 'hw_region'];
        foreach($keys as $k) if(isset($_POST[$k])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES (?, ?)")->execute([$k, json_encode($_POST[$k])]);
        $ips = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $_POST['cf_ips_list']))); if(empty($ips)) $ips=["1.0.0.1"];
        $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('cf_ips', ?)")->execute([json_encode(array_values($ips))]);
        $message = "<div class='alert alert-success'>DNS 配置已保存</div>";
    }
    elseif ($action === 'apply_cert') { $mode = $_POST['mode']; $prov = ($mode=='auto' && get_setting($pdo,'dns_provider','')=='huaweicloud')?'huaweicloud':'cloudflare'; $pdo->prepare("INSERT INTO certificates (domain, mode, provider, auto_renew, apply_status, status_msg) VALUES (?, ?, ?, ?, 'processing', '等待处理')")->execute([$_POST['domain'], $mode, $prov, isset($_POST['auto_renew'])?1:0]); }
    elseif ($action === 'del_cert') { $pdo->prepare("DELETE FROM certificates WHERE id=?")->execute([$_POST['id']]); }
    elseif ($action === 'verify_manual_cert') { $pdo->prepare("UPDATE certificates SET apply_status='verifying', status_msg='验证中...' WHERE id=?")->execute([$_POST['id']]); }
    elseif ($action === 'save_settings') {
        if(!empty($_POST['admin_user'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_user', ?)")->execute([json_encode($_POST['admin_user'])]);
        if(!empty($_POST['admin_pass'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_pass', ?)")->execute([json_encode($_POST['admin_pass'])]);
        if(!empty($_POST['admin_slug'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_slug', ?)")->execute([json_encode($_POST['admin_slug'])]);
        $message = "<div class='alert alert-success'>设置已更新</div>";
        $CONF_USER = $_POST['admin_user']; $CONF_PASS = $_POST['admin_pass']; $CONF_SLUG = $_POST['admin_slug'];
    }
    $active_tab = $redirect_tab;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>CDN 智能控制台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .status-dot {height:10px;width:10px;border-radius:50%;display:inline-block;}
        .bg-online{background:#198754}.bg-offline{background:#dc3545}
        .nav-link.active { font-weight: bold; border-top: 3px solid #0d6efd !important; color: #0d6efd !important; background: white !important; }
        .stat-card { transition: all 0.3s; min-height: 140px; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .stat-number { font-size: 2.5rem; font-weight: bold; color: #0d6efd; line-height: 1.2; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow">
    <div class="container">
        <span class="navbar-brand fw-bold">⚡ CDN 智能调度控制台</span>
        <div class="text-white small">管理员: <?= htmlspecialchars($CONF_USER) ?></div>
    </div>
</nav>

<div class="container">
    <?= $message ?>
    
    <ul class="nav nav-tabs mb-4 border-bottom-0" id="mainTab">
        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-nodes" data-bs-toggle="tab">📊 仪表盘与节点</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-domains" data-bs-toggle="tab">🌐 域名管理</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-dns" data-bs-toggle="tab">☁️ DNS 配置</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-cert" data-bs-toggle="tab">🔒 证书管理</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-settings" data-bs-toggle="tab">⚙️ 系统设置</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade" id="tab-nodes">
            
            <?php 
            // === 统计计算 ===
            $nodes = $pdo->query("SELECT * FROM nodes ORDER BY id DESC")->fetchAll();
            $node_count = count($nodes);
            $online_count = 0;
            
            $total_cpu_sum = 0;
            $total_ram_used = 0;
            $total_ram_max = 0;
            $total_bw_used = 0;
            $total_bw_max = 0;

            foreach($nodes as $n) {
                $is_online = (time() - $n['last_heartbeat']) < 60;
                if ($is_online) {
                    $online_count++;
                    $total_cpu_sum += $n['cpu_usage'];
                    $total_ram_used += $n['ram_usage'];
                    $total_ram_max += $n['max_ram'];
                    $total_bw_used += $n['current_bandwidth'];
                    $total_bw_max += $n['max_bandwidth'];
                }
            }
            
            // 平均 CPU (仅计算在线节点)
            $avg_cpu = $online_count > 0 ? round($total_cpu_sum / $online_count, 1) : 0;
            // 内存百分比
            $ram_pct = $total_ram_max > 0 ? round(($total_ram_used / $total_ram_max) * 100) : 0;
            $ram_free = $total_ram_max - $total_ram_used;
            // 带宽百分比
            $bw_pct = $total_bw_max > 0 ? round(($total_bw_used / $total_bw_max) * 100) : 0;
            $bw_free = $total_bw_max - $total_bw_used;
            ?>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex flex-column justify-content-center align-items-center text-center">
                            <h6 class="text-muted text-uppercase mb-2">节点状态</h6>
                            <div class="stat-number"><?= $online_count ?> <span class="text-muted fs-5">/ <?= $node_count ?></span></div>
                            <small class="text-success fw-bold">● 在线可用</small>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center position-relative">
                            <h6 class="text-muted mb-2">平均 CPU 负载</h6>
                            <div style="height: 120px;">
                                <canvas id="chartCpu"></canvas>
                            </div>
                            <div class="mt-1 fw-bold text-primary"><?= $avg_cpu ?>%</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-muted mb-2">总运行内存 (MB)</h6>
                            <div style="height: 120px;">
                                <canvas id="chartRam"></canvas>
                            </div>
                            <div class="mt-1 small text-muted">已用: <?= $total_ram_used ?> / 总: <?= $total_ram_max ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card h-100 border-0 shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-muted mb-2">总宽带负载 (Mbps)</h6>
                            <div style="height: 120px;">
                                <canvas id="chartBw"></canvas>
                            </div>
                            <div class="mt-1 small text-muted">实时: <?= $total_bw_used ?> / 上限: <?= $total_bw_max ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold text-dark">节点管理</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addNodeModal">+ 新增节点</button>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted"><tr><th class="ps-3">状态</th><th>主机名/IP</th><th>负载监控</th><th>累计流量</th><th>参数配置 (权重/限速/限流)</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php foreach($nodes as $node): $is_online = (time()-$node['last_heartbeat'])<60; ?>
                            <tr>
                                <td class="ps-3"><span class="status-dot <?= $is_online?'bg-online':'bg-offline' ?>" title="<?= $is_online?'在线':'离线' ?>"></span></td>
                                <td><strong><?= htmlspecialchars($node['hostname']) ?></strong><br><small class="text-muted"><?= $node['ip_address'] ?></small></td>
                                <td>
                                    <small class="text-muted">CPU: <?= $node['cpu_usage'] ?>% | RAM: <?= $node['ram_usage'] ?>MB</small>
                                    <div class="progress" style="height:4px;width:120px"><div class="progress-bar bg-info" style="width:<?= $node['cpu_usage'] ?>%"></div></div>
                                </td>
                                <td>
                                    <strong><?= round($node['traffic_used']/1073741824, 2) ?> GB</strong>
                                    <?php if($node['traffic_limit']>0): ?>
                                    <div class="progress mt-1" style="height:4px;width:80px"><div class="progress-bar bg-success" style="width:<?= min(100, $node['traffic_used']/($node['traffic_limit']*1073741824)*100) ?>%"></div></div>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width: 250px;">
                                    <form method="post" class="d-flex gap-1">
                                        <input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="update_node_config"><input type="hidden" name="id" value="<?= $node['id'] ?>">
                                        <input type="number" name="weight" value="<?= $node['weight'] ?>" class="form-control form-control-sm" placeholder="权" title="权重 (0-100)">
                                        <input type="number" name="max_bandwidth" value="<?= $node['max_bandwidth'] ?>" class="form-control form-control-sm" placeholder="速" title="带宽上限 (Mbps)">
                                        <input type="number" name="max_ram" value="<?= $node['max_ram'] ?>" class="form-control form-control-sm" placeholder="存" title="内存上限 (MB)">
                                        <input type="number" name="traffic_limit" value="<?= $node['traffic_limit'] ?>" class="form-control form-control-sm" placeholder="流" title="流量限制 (GB, 0不限)">
                                        <button class="btn btn-outline-primary btn-sm px-2">💾</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('删?');"><input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="del_node"><input type="hidden" name="id" value="<?= $node['id'] ?>"><button class="btn btn-sm btn-link text-danger">删</button></form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-domains">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between"><h5>域名白名单</h5><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDomainModal">+ 添加域名</button></div>
                <div class="card-body">
                    <div class="alert alert-light border">ℹ️ 只有白名单内的域名允许通过节点访问，否则返回 403。</div>
                    <ul class="list-group list-group-flush">
                        <?php $domains=$pdo->query("SELECT * FROM domains ORDER BY id DESC")->fetchAll(); foreach($domains as $d): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?= htmlspecialchars($d['domain']) ?> <small class="text-muted ms-2"><?= $d['created_at'] ?></small></span>
                            <form method="post" onsubmit="return confirm('移?');"><input type="hidden" name="tab" value="domains"><input type="hidden" name="action" value="del_domain"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-sm btn-outline-danger">移除</button></form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-dns">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h5>DNS 调度配置</h5></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="tab" value="dns"><input type="hidden" name="action" value="save_dns">
                        <div class="mb-3"><label class="fw-bold">服务商</label><select name="dns_provider" class="form-select" onchange="toggleDns(this.value)"><option value="cloudflare" <?= get_setting($pdo,'dns_provider','')=='cloudflare'?'selected':'' ?>>Cloudflare</option><option value="huaweicloud" <?= get_setting($pdo,'dns_provider','')=='huaweicloud'?'selected':'' ?>>Huawei Cloud</option></select></div>
                        <div id="cfForm" class="p-3 border rounded mb-3 bg-white"><h6 class="text-primary">Cloudflare 配置</h6><div class="row g-2"><div class="col-4"><input type="text" name="cf_email" class="form-control" placeholder="Email" value="<?= get_setting($pdo,'cf_email','') ?>"></div><div class="col-4"><input type="password" name="cf_key" class="form-control" placeholder="API Key" value="<?= get_setting($pdo,'cf_key','') ?>"></div><div class="col-4"><input type="text" name="cf_zone_id" class="form-control" placeholder="Zone ID" value="<?= get_setting($pdo,'cf_zone_id','') ?>"></div></div></div>
                        <div id="hwForm" class="p-3 border rounded mb-3 bg-white" style="display:none"><h6 class="text-danger">华为云 配置</h6><div class="row g-2"><div class="col-3"><input type="text" name="hw_ak" class="form-control" placeholder="AK" value="<?= get_setting($pdo,'hw_ak','') ?>"></div><div class="col-3"><input type="password" name="hw_sk" class="form-control" placeholder="SK" value="<?= get_setting($pdo,'hw_sk','') ?>"></div><div class="col-3"><input type="text" name="hw_zone_id" class="form-control" placeholder="ZoneID" value="<?= get_setting($pdo,'hw_zone_id','') ?>"></div><div class="col-3"><input type="text" name="hw_region" class="form-control" placeholder="Region" value="<?= get_setting($pdo,'hw_region','ap-southeast-1') ?>"></div></div></div>
                        <div class="mb-3"><label>Record Name</label><input type="text" name="cf_record_name" class="form-control" value="<?= get_setting($pdo,'cf_record_name','cdn') ?>"></div>
                        <div class="mb-3"><label>中转 IP 池 (换行分隔)</label><?php $ips=json_decode(get_setting($pdo,'cf_ips','["1.0.0.1"]'),true); ?><textarea name="cf_ips_list" class="form-control" rows="2"><?= implode("\n", $ips) ?></textarea></div>
                        <button class="btn btn-success">保存配置</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-cert">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white d-flex justify-content-between"><h5>SSL 证书</h5><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCertModal">+ 申请证书</button></div>
                <div class="card-body table-responsive">
                    <table class="table">
                        <thead><tr><th>域名</th><th>状态</th><th>模式</th><th>有效期</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php $certs=$pdo->query("SELECT * FROM certificates ORDER BY id DESC")->fetchAll(); foreach($certs as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['domain']) ?><br><small class="text-muted"><?= $c['status_msg'] ?></small></td>
                                <td><?php if($c['apply_status']=='wait_verify'): ?><div class="alert alert-warning p-1 mb-0" style="font-size:0.8rem">TXT: <?= $c['dns_txt_value'] ?><form method="post"><input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="verify_manual_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-success btn-sm w-100 mt-1">验证</button></form></div><?php else: echo $c['apply_status']; endif; ?></td>
                                <td><?= $c['mode'] ?></td>
                                <td><?= intval(($c['expire_time']-time())/86400) ?> 天</td>
                                <td><form method="post" onsubmit="return confirm('删?');"><input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="del_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-link text-danger">删</button></form></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-settings">
            <div class="card shadow-sm border-0" style="max-width: 600px; margin: 0 auto;">
                <div class="card-header bg-white"><h5>⚙️ 系统设置</h5></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="save_settings">
                        <div class="mb-3"><label class="form-label">管理员账号</label><input type="text" name="admin_user" class="form-control" value="<?= htmlspecialchars($CONF_USER) ?>" required></div>
                        <div class="mb-3"><label class="form-label">管理员密码</label><input type="text" name="admin_pass" class="form-control" value="<?= htmlspecialchars($CONF_PASS) ?>" required></div>
                        <div class="mb-3"><label class="form-label">安全入口 (URL Slug)</label><div class="input-group"><span class="input-group-text">/</span><input type="text" name="admin_slug" class="form-control" value="<?= htmlspecialchars($CONF_SLUG) ?>" required></div></div>
                        <button class="btn btn-danger w-100">保存系统设置</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addNodeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">加节点</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="add_node"><input type="text" name="hostname" class="form-control mb-2" placeholder="名称" required><input type="text" name="ip" class="form-control" placeholder="IP" required></div><div class="modal-footer"><button class="btn btn-primary">确定</button></div></form></div></div>
<div class="modal fade" id="addDomainModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">加域名</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="domains"><input type="hidden" name="action" value="add_domain"><input type="text" name="domain" class="form-control" placeholder="example.com" required></div><div class="modal-footer"><button class="btn btn-primary">确定</button></div></form></div></div>
<div class="modal fade" id="addCertModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">申证书</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="apply_cert"><input type="text" name="domain" class="form-control mb-2" placeholder="域名" required><select name="mode" class="form-select mb-2"><option value="auto">自动</option><option value="manual">手动</option></select><input type="checkbox" name="auto_renew" checked> 自动续费</div><div class="modal-footer"><button class="btn btn-primary">提交</button></div></form></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var activeTab = '<?= $active_tab ?>';
    var el = document.querySelector('button[data-bs-target="#tab-' + activeTab + '"]');
    if(el) new bootstrap.Tab(el).show();
    toggleDns(document.querySelector('select[name="dns_provider"]').value);
    
    // 渲染环形图
    renderRing('chartCpu', <?= $avg_cpu ?>, '#0d6efd', 'CPU');
    renderRing('chartRam', <?= $ram_pct ?>, '#6610f2', 'RAM');
    renderRing('chartBw',  <?= $bw_pct ?>,  '#fd7e14', 'BW');
});

function toggleDns(val) {
    document.getElementById('hwForm').style.display = (val==='huaweicloud'?'flex':'none');
    document.getElementById('cfForm').style.display = (val==='cloudflare'?'flex':'none');
}

function renderRing(id, pct, color, label) {
    new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: {
            labels: ['已用', '空闲'],
            datasets: [{
                data: [pct, 100-pct],
                backgroundColor: [color, '#e9ecef'],
                borderWidth: 0
            }]
        },
        options: {
            cutout: '75%', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { enabled: false } }
        }
    });
}
</script>
</body>
</html>
