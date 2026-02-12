<?php

session_start();

require_once 'db.php';



// ================== 1. 基础配置与鉴权 ==================

$active_tab = $_REQUEST['tab'] ?? 'dashboard';



function get_setting($pdo, $key, $default) {

    $stmt = $pdo->prepare("SELECT value_json FROM settings WHERE key_name = ?");

    $stmt->execute([$key]); $val = $stmt->fetchColumn(); return $val ? json_decode($val, true) : $default;

}



$CONF_USER = get_setting($pdo, 'admin_user', 'admin');

$CONF_PASS = get_setting($pdo, 'admin_pass', 'admin123');

$CONF_SLUG = get_setting($pdo, 'admin_slug', 'yun123');



$request_uri = $_SERVER['REQUEST_URI']; $url_slug = $_GET['slug'] ?? '';

if (!isset($_SESSION['is_admin']) && strpos($request_uri, $CONF_SLUG) === false && $url_slug !== $CONF_SLUG) { http_response_code(404); echo "404 Not Found"; exit; }



if (isset($_GET['logout'])) { session_destroy(); header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?slug=" . $CONF_SLUG); exit; }



if (!isset($_SESSION['is_admin'])) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (($_POST['username']??'') === $CONF_USER && ($_POST['password']??'') === $CONF_PASS) { $_SESSION['is_admin'] = true; header("Location: " . $_SERVER['REQUEST_URI']); exit; } 

        else { $login_error = "账号或密码错误"; }

    }

    ?>

    <!DOCTYPE html><html><head><title>Login</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{background:#f0f2f5;display:flex;align-items:center;justify-content:center;height:100vh}.card{width:100%;max-width:400px;padding:2rem;box-shadow:0 10px 25px rgba(0,0,0,0.05);border:none;border-radius:12px;}</style></head><body><div class="card"><h4 class="text-center mb-4 text-primary fw-bold">CDN 智能控制台</h4><?php if(isset($login_error)): ?><div class="alert alert-danger py-2"><?= $login_error ?></div><?php endif; ?><form method="post"><div class="mb-3"><label class="form-label text-muted small">管理员账号</label><input type="text" name="username" class="form-control" required></div><div class="mb-4"><label class="form-label text-muted small">密码</label><input type="password" name="password" class="form-control" required></div><button class="btn btn-primary w-100 py-2">安全登录</button></form></div></body></html>

    <?php exit;

}



// ================== 2. 业务逻辑 ==================

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? ''; $redirect_tab = $_POST['tab'] ?? 'dashboard';

    try {

        if ($action === 'add_domain') { $pdo->prepare("INSERT INTO domains (domain) VALUES (?)")->execute([trim($_POST['domain'])]); $message="<div class='alert alert-success'>域名添加成功</div>"; }

        elseif ($action === 'del_domain') { $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$_POST['id']]); }

        elseif ($action === 'add_node') { $pdo->prepare("INSERT INTO nodes (hostname, ip_address, secret_key) VALUES (?, ?, ?)")->execute([$_POST['hostname'], $_POST['ip'], bin2hex(random_bytes(16))]); $message="<div class='alert alert-success'>节点已添加，请点击“复制命令”安装。</div>"; }

        elseif ($action === 'del_node') { $pdo->prepare("DELETE FROM nodes WHERE id=?")->execute([$_POST['id']]); }

        elseif ($action === 'update_node_config') { $pdo->prepare("UPDATE nodes SET traffic_limit=?, weight=?, max_bandwidth=?, max_ram=? WHERE id=?")->execute([$_POST['traffic_limit'], $_POST['weight'], $_POST['max_bandwidth'], $_POST['max_ram'], $_POST['id']]); $message = "<div class='alert alert-success'>节点配置已更新</div>"; }

        elseif ($action === 'save_dns') {

            $keys = ['dns_provider', 'cf_email', 'cf_key', 'cf_zone_id', 'cf_record_name', 'hw_ak', 'hw_sk', 'hw_zone_id', 'hw_region'];

            foreach($keys as $k) if(isset($_POST[$k])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES (?, ?)")->execute([$k, json_encode($_POST[$k])]);

            $ips = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $_POST['cf_ips_list']))); if(empty($ips)) $ips=["1.0.0.1"];

            $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('cf_ips', ?)")->execute([json_encode(array_values($ips))]);

            $message = "<div class='alert alert-success'>DNS 配置已保存</div>";

        }

        elseif ($action === 'apply_cert') { $mode = $_POST['mode']; $prov = ($mode=='auto' && get_setting($pdo,'dns_provider','')=='huaweicloud')?'huaweicloud':'cloudflare'; $pdo->prepare("INSERT INTO certificates (domain, mode, provider, auto_renew, apply_status, status_msg) VALUES (?, ?, ?, ?, 'processing', '等待处理')")->execute([$_POST['domain'], $mode, $prov, isset($_POST['auto_renew'])?1:0]); $message = "<div class='alert alert-info'>证书申请已提交</div>"; }

        elseif ($action === 'del_cert') { $pdo->prepare("DELETE FROM certificates WHERE id=?")->execute([$_POST['id']]); }

        elseif ($action === 'verify_manual_cert') { $pdo->prepare("UPDATE certificates SET apply_status='verifying', status_msg='验证中...' WHERE id=?")->execute([$_POST['id']]); }

        elseif ($action === 'mark_read') { $pdo->prepare("UPDATE node_alerts SET is_read=1 WHERE id=?")->execute([$_POST['id']]); }

        elseif ($action === 'mark_all_read') { $pdo->query("UPDATE node_alerts SET is_read=1"); $message = "<div class='alert alert-success'>所有告警已标记为已读</div>"; }

        elseif ($action === 'save_settings') {

            if(!empty($_POST['admin_user'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_user', ?)")->execute([json_encode($_POST['admin_user'])]);

            if(!empty($_POST['admin_pass'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_pass', ?)")->execute([json_encode($_POST['admin_pass'])]);

            if(!empty($_POST['admin_slug'])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('admin_slug', ?)")->execute([json_encode($_POST['admin_slug'])]);

            $message = "<div class='alert alert-success'>系统设置已更新</div>"; $CONF_USER = $_POST['admin_user']; $CONF_PASS = $_POST['admin_pass']; $CONF_SLUG = $_POST['admin_slug'];

        }

    } catch (Exception $e) { $message = "<div class='alert alert-danger'>操作失败: " . $e->getMessage() . "</div>"; }

    $active_tab = $redirect_tab;

}



// ================== 3. 数据渲染 ==================

$unread_alert_count = $pdo->query("SELECT count(*) FROM node_alerts WHERE is_read=0")->fetchColumn();

$nodes = $pdo->query("SELECT * FROM nodes ORDER BY id DESC")->fetchAll();

$node_count = count($nodes); $online_count = 0; $total_cpu = 0; $total_ram = 0; $max_ram = 0; $total_bw = 0; $max_bw = 0;

foreach($nodes as $n) {

    if ((time() - $n['last_heartbeat']) < 60) {

        $online_count++; $total_cpu += $n['cpu_usage'];

        $total_ram += $n['ram_usage']; $max_ram += $n['max_ram'];

        $total_bw += $n['current_bandwidth']; $max_bw += $n['max_bandwidth'];

    }

}

$avg_cpu = $online_count > 0 ? round($total_cpu / $online_count, 1) : 0;

$ram_pct = $max_ram > 0 ? round(($total_ram / $max_ram)*100) : 0;

$bw_pct = $max_bw > 0 ? round(($total_bw / $max_bw)*100) : 0;

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

$master_url = $protocol . $_SERVER['HTTP_HOST'];

?>



<!DOCTYPE html>

<html lang="zh-CN">

<head>

    <meta charset="UTF-8">

    <title>CDN 智能调度控制台</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>

        body { background-color: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }

        .status-dot {height:10px;width:10px;border-radius:50%;display:inline-block;}

        .bg-online{background:#198754;box-shadow:0 0 5px #198754}.bg-offline{background:#dc3545}

        .nav-tabs .nav-link { color: #6c757d; border: none; font-weight: 500; padding: 10px 20px; }

        .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 3px solid #0d6efd; background: transparent; font-weight: bold; }

        .nav-tabs { border-bottom: 2px solid #e9ecef; }

        .stat-card { border: none; border-radius: 10px; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.03); transition: transform 0.2s; min-height: 160px; }

        .stat-card:hover { transform: translateY(-3px); }

        .card { border: none; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 20px; }

        .card-header { background: white; border-bottom: 1px solid #f0f0f0; font-weight: bold; padding: 15px 20px; border-radius: 10px 10px 0 0 !important; }

        .table thead th { border-top: none; background: #f8f9fa; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; color: #6c757d; }

        .btn-logout { color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.9rem; }

        .btn-logout:hover { color: white; }

    </style>

</head>

<body>



<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 sticky-top">

    <div class="container">

        <span class="navbar-brand fw-bold">⚡ CDN 智能调度控制台</span>

        <div class="d-flex align-items-center gap-3">

            <?php if($unread_alert_count > 0): ?><span class="badge bg-danger rounded-pill animate__animated animate__pulse animate__infinite">⚠️ <?= $unread_alert_count ?> 告警</span><?php endif; ?>

            <span class="text-white small opacity-75">管理员: <?= htmlspecialchars($CONF_USER) ?></span>

            <a href="?logout=1" class="btn-logout" onclick="return confirm('确定退出登录?')">退出 ➔</a>

        </div>

    </div>

</nav>



<div class="container">

    <?= $message ?>

    <ul class="nav nav-tabs mb-4" id="mainTab">

        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-dashboard" data-bs-toggle="tab">📊 仪表盘</button></li>

        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-nodes" data-bs-toggle="tab">📡 节点管理</button></li>

        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-alerts" data-bs-toggle="tab">🔔 告警中心 <?php if($unread_alert_count>0) echo "<span class='badge bg-danger ms-1'>$unread_alert_count</span>"; ?></button></li>

        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-domains" data-bs-toggle="tab">🌐 域名白名单</button></li>

        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-dns" data-bs-toggle="tab">☁️ DNS 配置</button></li>

        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-cert" data-bs-toggle="tab">🔒 SSL 证书</button></li>

        <li class="nav-item"><button class="nav-link" data-bs-target="#tab-settings" data-bs-toggle="tab">⚙️ 设置</button></li>

    </ul>



    <div class="tab-content">

        <div class="tab-pane fade" id="tab-dashboard">

            <div class="row g-4 mb-4">

                <div class="col-md-3"><div class="card stat-card d-flex align-items-center justify-content-center"><div class="text-center"><h6 class="text-muted text-uppercase mb-2">节点健康度</h6><h2 class="display-4 fw-bold text-primary mb-0"><?= $online_count ?></h2><small class="text-muted">在线 / <?= $node_count ?> 总数</small><div class="mt-2"><span class="badge bg-success">● 运行中</span></div></div></div></div>

                <div class="col-md-3"><div class="card stat-card p-3"><h6 class="text-center text-muted mb-3">平均 CPU 负载</h6><div style="height:100px; position:relative"><canvas id="chartCpu"></canvas></div><div class="text-center mt-2 fw-bold"><?= $avg_cpu ?>%</div></div></div>

                <div class="col-md-3"><div class="card stat-card p-3"><h6 class="text-center text-muted mb-3">内存总占用 (MB)</h6><div style="height:100px; position:relative"><canvas id="chartRam"></canvas></div><div class="text-center mt-2 small text-muted"><?= $total_ram ?> / <?= $max_ram ?> MB</div></div></div>

                <div class="col-md-3"><div class="card stat-card p-3"><h6 class="text-center text-muted mb-3">实时总带宽 (Mbps)</h6><div style="height:100px; position:relative"><canvas id="chartBw"></canvas></div><div class="text-center mt-2 small text-muted"><?= $total_bw ?> / <?= $max_bw ?> Mbps</div></div></div>

            </div>

            

            <div class="row g-4">

                <div class="col-md-6">

                    <div class="card h-100">

                        <div class="card-header bg-white">⚡ 快捷操作</div>

                        <div class="card-body d-flex gap-2 align-items-center">

                            <button class="btn btn-outline-primary" onclick="new bootstrap.Tab(document.querySelector('button[data-bs-target=\'#tab-nodes\']')).show(); setTimeout(()=>new bootstrap.Modal(document.getElementById('addNodeModal')).show(),200)">+ 添加节点</button>

                            <button class="btn btn-outline-success" onclick="new bootstrap.Tab(document.querySelector('button[data-bs-target=\'#tab-cert\']')).show(); setTimeout(()=>new bootstrap.Modal(document.getElementById('addCertModal')).show(),200)">+ 申请证书</button>

                            <button class="btn btn-outline-dark" onclick="new bootstrap.Tab(document.querySelector('button[data-bs-target=\'#tab-settings\']')).show()">修改密码</button>

                        </div>

                    </div>

                </div>

                <div class="col-md-6">

                    <div class="card h-100">

                        <div class="card-header bg-white">ℹ️ 系统信息</div>

                        <div class="card-body small text-muted">

                            <div class="row">

                                <div class="col-6 mb-2"><strong>PHP 版本:</strong> <?= phpversion() ?></div>

                                <div class="col-6 mb-2"><strong>服务器 IP:</strong> <?= $_SERVER['SERVER_ADDR'] ?? '未知' ?></div>

                                <div class="col-6 mb-2"><strong>数据库:</strong> MariaDB/MySQL (已连接)</div>

                                <div class="col-6 mb-2"><strong>主控端 URL:</strong> <?= $master_url ?></div>

                                <div class="col-12 mt-2 pt-2 border-top"><strong>安全入口:</strong> /<?= htmlspecialchars($CONF_SLUG) ?></div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>



        <div class="tab-pane fade" id="tab-nodes">

            <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>节点列表</span><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addNodeModal">+ 新增节点</button></div><div class="card-body table-responsive p-0"><table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-3">状态</th><th>主机名/安装命令</th><th>负载情况</th><th>流量统计</th><th>流控配置 (权重/限速/限存/限流)</th><th>操作</th></tr></thead><tbody>

                <?php foreach($nodes as $node): $is_online = (time()-$node['last_heartbeat'])<60; $cmd = "wget -O install_node.sh {$master_url}/install_node.sh && chmod +x install_node.sh && ./install_node.sh -master {$master_url} -secret {$node['secret_key']}"; ?>

                <tr>

                    <td class="ps-3"><span class="status-dot <?= $is_online?'bg-online':'bg-offline' ?>" title="<?= $is_online?'在线':'离线' ?>"></span></td>

                    <td><div class="fw-bold"><?= htmlspecialchars($node['hostname']) ?></div><div class="small text-muted mb-1"><?= $node['ip_address'] ?></div><button class="btn btn-sm btn-outline-secondary py-0" style="font-size:0.75rem" onclick="copyCmd('<?= $cmd ?>')">📋 复制安装命令</button></td>

                    <td><div class="d-flex align-items-center small mb-1"><span style="width:30px">CPU</span><div class="progress flex-grow-1" style="height:4px"><div class="progress-bar bg-primary" style="width:<?= $node['cpu_usage'] ?>%"></div></div><span class="ms-1"><?= $node['cpu_usage'] ?>%</span></div><div class="d-flex align-items-center small"><span style="width:30px">RAM</span><div class="progress flex-grow-1" style="height:4px"><div class="progress-bar bg-info" style="width:<?= ($node['max_ram']>0 ? ($node['ram_usage']/$node['max_ram']*100) : 0) ?>%"></div></div><span class="ms-1"><?= $node['ram_usage'] ?>MB</span></div></td>

                    <td><div class="fw-bold"><?= round($node['traffic_used']/1073741824, 2) ?> GB</div><?php if($node['traffic_limit']>0): ?><div class="progress" style="height:3px;width:60px"><div class="progress-bar bg-success" style="width:<?= min(100, $node['traffic_used']/($node['traffic_limit']*1073741824)*100) ?>%"></div></div><small class="text-muted text-xs">限 <?= $node['traffic_limit'] ?>G</small><?php endif; ?></td>

                    <td><form method="post" class="d-flex gap-1" style="max-width:300px"><input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="update_node_config"><input type="hidden" name="id" value="<?= $node['id'] ?>"><input type="number" name="weight" value="<?= $node['weight'] ?>" class="form-control form-control-sm px-1" title="权重"><input type="number" name="max_bandwidth" value="<?= $node['max_bandwidth'] ?>" class="form-control form-control-sm px-1" title="限速Mbps"><input type="number" name="max_ram" value="<?= $node['max_ram'] ?>" class="form-control form-control-sm px-1" title="限存MB"><input type="number" name="traffic_limit" value="<?= $node['traffic_limit'] ?>" class="form-control form-control-sm px-1" title="限流GB"><button class="btn btn-outline-primary btn-sm px-2">💾</button></form></td>

                    <td><form method="post" onsubmit="return confirm('删?');"><input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="del_node"><input type="hidden" name="id" value="<?= $node['id'] ?>"><button class="btn btn-link btn-sm text-danger">删</button></form></td>

                </tr>

                <?php endforeach; ?>

            </tbody></table></div></div>

        </div>



        <div class="tab-pane fade" id="tab-alerts">

            <div class="card"><div class="card-header d-flex justify-content-between align-items-center bg-danger bg-opacity-10 text-danger"><span>⚠️ 系统告警记录</span><?php if($unread_alert_count>0): ?><form method="post"><input type="hidden" name="tab" value="alerts"><input type="hidden" name="action" value="mark_all_read"><button class="btn btn-sm btn-outline-danger">全部标记已读</button></form><?php endif; ?></div><div class="card-body table-responsive"><table class="table"><thead><tr><th>时间</th><th>节点</th><th>类型</th><th>内容</th><th>状态</th></tr></thead><tbody><?php $alerts=$pdo->query("SELECT * FROM node_alerts ORDER BY id DESC LIMIT 50")->fetchAll(); if(count($alerts)==0) echo "<tr><td colspan='5' class='text-center text-muted py-4'>无告警记录</td></tr>"; foreach($alerts as $a): ?><tr class="<?= $a['is_read']?'':'table-warning' ?>"><td><?= $a['created_at'] ?></td><td><?= htmlspecialchars($a['node_name']) ?></td><td><span class="badge bg-secondary"><?= $a['type'] ?></span></td><td><?= htmlspecialchars($a['message']) ?></td><td><?php if(!$a['is_read']): ?><form method="post"><input type="hidden" name="tab" value="alerts"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-link">标为已读</button></form><?php else: echo '<span class="text-muted small">已读</span>'; endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>

        </div>



        <div class="tab-pane fade" id="tab-domains">

            <div class="card"><div class="card-header d-flex justify-content-between"><h5>域名白名单</h5><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDomainModal">+ 添加域名</button></div><div class="card-body"><div class="alert alert-info py-2 small">只有在此列表中的域名，节点才会处理请求。</div><ul class="list-group list-group-flush"><?php $domains=$pdo->query("SELECT * FROM domains ORDER BY id DESC")->fetchAll(); foreach($domains as $d): ?><li class="list-group-item d-flex justify-content-between align-items-center"><strong><?= htmlspecialchars($d['domain']) ?></strong><form method="post" onsubmit="return confirm('删?');"><input type="hidden" name="tab" value="domains"><input type="hidden" name="action" value="del_domain"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-sm btn-outline-danger">移除</button></form></li><?php endforeach; ?></ul></div></div>

        </div>



        <div class="tab-pane fade" id="tab-dns">

            <div class="card"><div class="card-header">DNS 调度配置</div><div class="card-body"><form method="post"><input type="hidden" name="tab" value="dns"><input type="hidden" name="action" value="save_dns"><div class="mb-3"><label class="fw-bold">DNS 服务商</label><select name="dns_provider" class="form-select" onchange="toggleDns(this.value)"><option value="cloudflare" <?= get_setting($pdo,'dns_provider','')=='cloudflare'?'selected':'' ?>>Cloudflare</option><option value="huaweicloud" <?= get_setting($pdo,'dns_provider','')=='huaweicloud'?'selected':'' ?>>Huawei Cloud</option></select></div><div id="cfForm" class="p-3 border rounded mb-3"><h6 class="text-primary">Cloudflare API</h6><div class="row g-2"><div class="col-4"><input type="text" name="cf_email" class="form-control" placeholder="Email" value="<?= get_setting($pdo,'cf_email','') ?>"></div><div class="col-4"><input type="password" name="cf_key" class="form-control" placeholder="API Key" value="<?= get_setting($pdo,'cf_key','') ?>"></div><div class="col-4"><input type="text" name="cf_zone_id" class="form-control" placeholder="Zone ID" value="<?= get_setting($pdo,'cf_zone_id','') ?>"></div></div></div><div id="hwForm" class="p-3 border rounded mb-3" style="display:none"><h6 class="text-danger">华为云 API</h6><div class="row g-2"><div class="col-3"><input type="text" name="hw_ak" class="form-control" placeholder="AK" value="<?= get_setting($pdo,'hw_ak','') ?>"></div><div class="col-3"><input type="password" name="hw_sk" class="form-control" placeholder="SK" value="<?= get_setting($pdo,'hw_sk','') ?>"></div><div class="col-3"><input type="text" name="hw_zone_id" class="form-control" placeholder="ZoneID" value="<?= get_setting($pdo,'hw_zone_id','') ?>"></div><div class="col-3"><input type="text" name="hw_region" class="form-control" placeholder="Region" value="<?= get_setting($pdo,'hw_region','ap-southeast-1') ?>"></div></div></div><div class="mb-3"><label>调度记录名</label><input type="text" name="cf_record_name" class="form-control" value="<?= get_setting($pdo,'cf_record_name','cdn') ?>"></div><div class="mb-3"><label>中转 IP 池</label><?php $ips=json_decode(get_setting($pdo,'cf_ips','["1.0.0.1"]'),true); ?><textarea name="cf_ips_list" class="form-control" rows="3"><?= implode("\n", $ips) ?></textarea></div><button class="btn btn-success">保存配置</button></form></div></div>

        </div>



        <div class="tab-pane fade" id="tab-cert">

            <div class="card"><div class="card-header d-flex justify-content-between"><h5>SSL 证书自动化</h5><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCertModal">+ 申请证书</button></div><div class="card-body table-responsive"><table class="table align-middle"><thead><tr><th>域名</th><th>状态</th><th>模式</th><th>有效期</th><th>操作</th></tr></thead><tbody><?php $certs=$pdo->query("SELECT * FROM certificates ORDER BY id DESC")->fetchAll(); foreach($certs as $c): ?><tr><td><?= htmlspecialchars($c['domain']) ?><br><small class="text-muted"><?= $c['status_msg'] ?></small></td><td><?php if($c['apply_status']=='wait_verify'): ?><div class="alert alert-warning p-1 mb-0 small">TXT: <?= $c['dns_txt_value'] ?><form method="post"><input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="verify_manual_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-success btn-sm w-100 mt-1">验证</button></form></div><?php elseif($c['apply_status']=='success'): ?><span class="badge bg-success">正常</span><?php else: echo $c['apply_status']; endif; ?></td><td><?= $c['mode'] ?></td><td><?= intval(($c['expire_time']-time())/86400) ?> 天</td><td><form method="post" onsubmit="return confirm('删?');"><input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="del_cert"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-link text-danger">删</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>

        </div>



        <div class="tab-pane fade" id="tab-settings">

            <div class="card" style="max-width:500px; margin:0 auto"><div class="card-header">⚙️ 系统设置</div><div class="card-body"><form method="post"><input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="save_settings"><div class="mb-3"><label>管理员账号</label><input type="text" name="admin_user" class="form-control" value="<?= htmlspecialchars($CONF_USER) ?>" required></div><div class="mb-3"><label>管理员密码</label><input type="text" name="admin_pass" class="form-control" value="<?= htmlspecialchars($CONF_PASS) ?>" required></div><div class="mb-3"><label>安全入口 Slug</label><div class="input-group"><span class="input-group-text">/</span><input type="text" name="admin_slug" class="form-control" value="<?= htmlspecialchars($CONF_SLUG) ?>" required></div></div><button class="btn btn-danger w-100">更新设置</button></form></div></div>

        </div>

    </div>

</div>



<div class="modal fade" id="addNodeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">加节点</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="nodes"><input type="hidden" name="action" value="add_node"><input type="text" name="hostname" class="form-control mb-2" placeholder="名称" required><input type="text" name="ip" class="form-control" placeholder="IP" required></div><div class="modal-footer"><button class="btn btn-primary">确定</button></div></form></div></div>

<div class="modal fade" id="addDomainModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">加域名</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="domains"><input type="hidden" name="action" value="add_domain"><input type="text" name="domain" class="form-control" placeholder="example.com" required></div><div class="modal-footer"><button class="btn btn-primary">确定</button></div></form></div></div>

<div class="modal fade" id="addCertModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post"><div class="modal-header"><h5 class="modal-title">申证书</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="tab" value="cert"><input type="hidden" name="action" value="apply_cert"><input type="text" name="domain" class="form-control mb-2" placeholder="域名" required><select name="mode" class="form-select mb-2"><option value="auto">自动 API</option><option value="manual">手动验证</option></select><input type="checkbox" name="auto_renew" checked> 自动续费</div><div class="modal-footer"><button class="btn btn-primary">提交</button></div></form></div></div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>

document.addEventListener("DOMContentLoaded", function() {

    var activeTab = '<?= $active_tab ?>';

    var el = document.querySelector('button[data-bs-target="#tab-' + activeTab + '"]');

    if(el) new bootstrap.Tab(el).show();

    toggleDns(document.querySelector('select[name="dns_provider"]').value);

    

    // Charts

    renderRing('chartCpu', <?= $avg_cpu ?>, '#0d6efd');

    renderRing('chartRam', <?= $ram_pct ?>, '#6610f2');

    renderRing('chartBw',  <?= $bw_pct ?>,  '#fd7e14');

});

function toggleDns(val) { document.getElementById('hwForm').style.display = (val==='huaweicloud'?'flex':'none'); document.getElementById('cfForm').style.display = (val==='cloudflare'?'flex':'none'); }

function renderRing(id, pct, color) {

    new Chart(document.getElementById(id), {

        type: 'doughnut', data: { labels: ['Used', 'Free'], datasets: [{ data: [pct, 100-pct], backgroundColor: [color, '#e9ecef'], borderWidth: 0 }] },

        options: { cutout: '75%', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { enabled: false } } }

    });

}

function copyCmd(text) {

    if (navigator.clipboard && window.isSecureContext) {

        navigator.clipboard.writeText(text).then(() => alert('✅ 命令已复制！'), () => fallbackCopy(text));

    } else {

        fallbackCopy(text);

    }

}

function fallbackCopy(text) {

    var textArea = document.createElement("textarea");

    textArea.value = text;

    textArea.style.position = "fixed"; 

    document.body.appendChild(textArea);

    textArea.focus();

    textArea.select();

    try {

        var successful = document.execCommand('copy');

        alert(successful ? '✅ 命令已复制！' : '❌ 复制失败，请手动选择');

    } catch (err) {

        alert('❌ 无法复制，请手动选择');

    }

    document.body.removeChild(textArea);

}

</script>

</body>

</html>
