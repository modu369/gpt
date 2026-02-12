<?php
session_start();
require_once 'db.php';

// ================= 1. 核心修复：获取当前激活的 Tab =================
// 优先获取 URL 参数或 POST 参数中的 tab，默认为 'nodes'
$active_tab = $_REQUEST['tab'] ?? 'nodes';

// ================= 配置获取函数 =================
function get_setting($pdo, $key, $default) {
    $stmt = $pdo->prepare("SELECT value_json FROM settings WHERE key_name = ?");
    $stmt->execute([$key]); $val = $stmt->fetchColumn(); return $val ? json_decode($val, true) : $default;
}

// 简单鉴权 (根据您的环境保留或调整)
$CONF_USER = get_setting($pdo, 'admin_user', 'admin');
$CONF_PASS = get_setting($pdo, 'admin_pass', 'admin123');
if (!isset($_SESSION['is_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['username']??'') === $CONF_USER && ($_POST['password']??'') === $CONF_PASS) {
        $_SESSION['is_admin'] = true; header("Location: ".$_SERVER['REQUEST_URI']); exit;
    }
    // ... (此处省略登录页 HTML，保持原样即可，为了篇幅重点展示逻辑) ...
    // 如果需要完整的登录页代码请告诉我，这里假设您保留了之前的登录逻辑
    if(!isset($_SESSION['is_admin'])) { die("Please Login"); }
}

// ================= 业务逻辑处理 =================
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // 获取表单传递回来的 tab，如果没有则默认 nodes
    $redirect_tab = $_POST['tab'] ?? 'nodes';

    // --- 1. 域名管理 (修复：添加域名后跳回 domains tab) ---
    if ($action === 'add_domain') {
        $domain = trim($_POST['domain']);
        if ($domain) {
            try {
                $pdo->prepare("INSERT INTO domains (domain) VALUES (?)")->execute([$domain]);
                $message = "<div class='alert alert-success'>域名 $domain 添加成功</div>";
            } catch (Exception $e) {
                $message = "<div class='alert alert-danger'>添加失败 (可能已存在)</div>";
            }
        }
    }
    elseif ($action === 'del_domain') {
        $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$_POST['id']]);
        $message = "<div class='alert alert-success'>域名已删除</div>";
    }

    // --- 2. 节点管理 ---
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

    // --- 3. DNS 配置 ---
    elseif ($action === 'save_dns') {
        // 保存各项配置...
        $cfg = ['dns_provider', 'cf_email', 'cf_key', 'cf_zone_id', 'cf_record_name', 'hw_ak', 'hw_sk', 'hw_zone_id', 'hw_region'];
        foreach($cfg as $k) {
            if(isset($_POST[$k])) $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES (?, ?)")->execute([$k, json_encode($_POST[$k])]);
        }
        // 保存 IP 池
        $ips = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $_POST['cf_ips_list'])));
        if(empty($ips)) $ips = ["1.0.0.1"];
        $pdo->prepare("REPLACE INTO settings (key_name, value_json) VALUES ('cf_ips', ?)")->execute([json_encode(array_values($ips))]);

        $message = "<div class='alert alert-success'>DNS 配置已保存</div>";
    }

    // --- 4. 证书管理 ---
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

    // 【核心修复】处理完 POST 后，强制刷新并带上 tab 参数，防止表单重复提交，并保持 Tab
    // 注意：如果想显示 $message，就不能用 header 跳转。
    // 为了用户体验，我们这里不跳转，而是直接渲染页面，利用 $active_tab 变量保持状态。
    // 如果您希望彻底防止刷新重提交，可以用 Session 存 message 然后 header 跳转。
    // 这里采用“直接渲染 + JS 切换”方案。
    $active_tab = $redirect_tab;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>CDN 智能控制台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .status-dot {height:10px;width:10px;border-radius:50%;display:inline-block;}
        .bg-online{background:#198754}.bg-offline{background:#dc3545}
        .nav-tabs .nav-link.active { font-weight: bold; border-top: 3px solid #0d6efd; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4"><div class="container"><span class="navbar-brand">⚡ CDN 智能调度控制台</span></div></nav>

<div class="container">
    <?= $message ?>

    <ul class="nav nav-tabs mb-4" id="mainTab" role="tablist">
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-nodes" type="button">📡 监控与节点</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-domains" type="button">🌐 域名管理</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-dns" type="button">☁️ DNS 配置</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cert" type="button">🔒 证书管理</button></li>
    </ul>

    <div class="tab-content">

        <div class="tab-pane fade" id="tab-nodes">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>节点列表</span>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addNodeModal">+ 新增节点</button>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle">
                        <thead><tr><th>状态</th><th>节点</th><th>负载</th><th>流量</th><th>配置</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php
                            $nodes = $pdo->query("SELECT * FROM nodes ORDER BY id DESC")->fetchAll();
                            foreach($nodes as $node):
                                $is_online = (time()-$node['last_heartbeat'])<60;
                            ?>
                            <tr>
                                <td><span class="status-dot <?= $is_online?'bg-online':'bg-offline' ?>"></span></td>
                                <td><?= htmlspecialchars($node['hostname']) ?><br><small><?= $node['ip_address'] ?></small></td>
                                <td>CPU: <?= $node['cpu_usage'] ?>%<br>RAM: <?= $node['ram_usage'] ?>MB</td>
                                <td><?= round($node['traffic_used']/1073741824,2) ?> GB</td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="tab" value="nodes">
                                        <input type="hidden" name="action" value="update_node_config">
                                        <input type="hidden" name="id" value="<?= $node['id'] ?>">
                                        <div class="row g-1">
                                            <div class="col-4"><input type="number" name="weight" value="<?= $node['weight'] ?>" class="form-control form-control-sm" placeholder="权重"></div>
                                            <div class="col-4"><input type="number" name="max_bandwidth" value="<?= $node['max_bandwidth'] ?>" class="form-control form-control-sm" placeholder="限速"></div>
                                            <div class="col-4"><input type="number" name="max_ram" value="<?= $node['max_ram'] ?>" class="form-control form-control-sm" placeholder="内存"></div>
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary w-100 mt-1">保存</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('删?');">
                                        <input type="hidden" name="tab" value="nodes">
                                        <input type="hidden" name="action" value="del_node">
                                        <input type="hidden" name="id" value="<?= $node['id'] ?>">
                                        <button class="btn btn-sm btn-link text-danger">删</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-domains">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>域名白名单 (防盗链)</span>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addDomainModal">+ 添加域名</button>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2">只有在列表中的域名，节点才会允许访问。非白名单域名访问将被拒绝 (Access Denied)。</div>
                    <table class="table">
                        <thead><tr><th>ID</th><th>域名</th><th>添加时间</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php
                            $domains = $pdo->query("SELECT * FROM domains ORDER BY id DESC")->fetchAll();
                            foreach($domains as $d): ?>
                            <tr>
                                <td><?= $d['id'] ?></td>
                                <td><strong><?= htmlspecialchars($d['domain']) ?></strong></td>
                                <td><?= $d['created_at'] ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('移除?');">
                                        <input type="hidden" name="tab" value="domains">
                                        <input type="hidden" name="action" value="del_domain">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
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
            <div class="card">
                <div class="card-header">DNS 与中转配置</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="tab" value="dns">
                        <input type="hidden" name="action" value="save_dns">

                        <div class="mb-3">
                            <label>DNS 服务商</label>
                            <select name="dns_provider" class="form-select" onchange="toggleDns(this.value)">
                                <option value="cloudflare" <?= get_setting($pdo,'dns_provider','')=='cloudflare'?'selected':'' ?>>Cloudflare</option>
                                <option value="huaweicloud" <?= get_setting($pdo,'dns_provider','')=='huaweicloud'?'selected':'' ?>>华为云 (Huawei Cloud)</option>
                            </select>
                        </div>

                        <div id="cfForm" class="row">
                            <div class="col-md-4 mb-2"><label>Email</label><input type="text" name="cf_email" class="form-control" value="<?= get_setting($pdo,'cf_email','') ?>"></div>
                            <div class="col-md-4 mb-2"><label>Key</label><input type="password" name="cf_key" class="form-control" value="<?= get_setting($pdo,'cf_key','') ?>"></div>
                            <div class="col-md-4 mb-2"><label>Zone ID</label><input type="text" name="cf_zone_id" class="form-control" value="<?= get_setting($pdo,'cf_zone_id','') ?>"></div>
                        </div>

                        <div id="hwForm" class="row" style="display:none">
                            <div class="col-md-3 mb-2"><label>AK</label><input type="text" name="hw_ak" class="form-control" value="<?= get_setting($pdo,'hw_ak','') ?>"></div>
                            <div class="col-md-3 mb-2"><label>SK</label><input type="password" name="hw_sk" class="form-control" value="<?= get_setting($pdo,'hw_sk','') ?>"></div>
                            <div class="col-md-3 mb-2"><label>ZoneID</label><input type="text" name="hw_zone_id" class="form-control" value="<?= get_setting($pdo,'hw_zone_id','') ?>"></div>
                            <div class="col-md-3 mb-2"><label>Region</label><input type="text" name="hw_region" class="form-control" value="<?= get_setting($pdo,'hw_region','ap-southeast-1') ?>"></div>
                        </div>

                        <div class="mb-3 mt-3">
                            <label>调度记录名 (Record Name)</label>
                            <input type="text" name="cf_record_name" class="form-control" value="<?= get_setting($pdo,'cf_record_name','cdn') ?>">
                        </div>

                        <div class="mb-3 border-top pt-3">
                            <h6 class="text-primary">⚡ 中转 IP 池 (优选 IP)</h6>
                            <small class="text-muted">SaaS 域名请使用 1.0.0.1 或 162.159.x.x</small>
                            <?php $ips=json_decode(get_setting($pdo,'cf_ips','["1.0.0.1"]'),true); ?>
                            <textarea name="cf_ips_list" class="form-control" rows="2"><?= implode("\n", $ips) ?></textarea>
                        </div>

                        <button class="btn btn-success">保存配置</button>
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
                    <table class="table">
                        <thead><tr><th>域名</th><th>状态</th><th>模式</th><th>操作</th></tr></thead>
                        <tbody>
                            <?php
                            $certs = $pdo->query("SELECT * FROM certificates ORDER BY id DESC")->fetchAll();
                            foreach($certs as $c):
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($c['domain']) ?><br><small class="text-muted"><?= $c['status_msg'] ?></small></td>
                                <td>
                                    <?php if($c['apply_status']=='wait_verify'): ?>
                                        <div class="alert alert-warning p-1 mb-0 small">
                                            TXT域名: <?= $c['dns_txt_domain'] ?><br>记录值: <?= $c['dns_txt_value'] ?>
                                            <form method="post" class="mt-1">
                                                <input type="hidden" name="tab" value="cert">
                                                <input type="hidden" name="action" value="verify_manual_cert">
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button class="btn btn-sm btn-success w-100">验证</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <?= $c['apply_status'] ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $c['mode'] ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('删?');">
                                        <input type="hidden" name="tab" value="cert">
                                        <input type="hidden" name="action" value="del_cert">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button class="btn btn-sm btn-danger">删</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="addNodeModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <div class="modal-header"><h5 class="modal-title">新增节点</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="tab" value="nodes"> <input type="hidden" name="action" value="add_node">
                <div class="mb-2"><label>名称</label><input type="text" name="hostname" class="form-control" required></div>
                <div class="mb-2"><label>IP</label><input type="text" name="ip" class="form-control" required></div>
                <div class="alert alert-info small mt-2">添加后请使用生成的命令在被控端执行安装。</div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">确定</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="addDomainModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <div class="modal-header"><h5 class="modal-title">添加白名单域名</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="tab" value="domains"> <input type="hidden" name="action" value="add_domain">
                <div class="mb-2"><label>域名 (不带http)</label><input type="text" name="domain" class="form-control" placeholder="www.example.com" required></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">确定</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="addCertModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <div class="modal-header"><h5 class="modal-title">申请证书</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="tab" value="cert"> <input type="hidden" name="action" value="apply_cert">
                <div class="mb-2"><label>域名</label><input type="text" name="domain" class="form-control" required></div>
                <div class="mb-2">
                    <label>模式</label>
                    <select name="mode" class="form-select">
                        <option value="auto">自动 API (推荐)</option>
                        <option value="manual">手动 DNS 验证</option>
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="auto_renew" class="form-check-input" checked>
                    <label class="form-check-label">自动续费</label>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">提交</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ================= 2. 核心修复：自动切换 JS =================
document.addEventListener("DOMContentLoaded", function() {
    var activeTab = '<?= $active_tab ?>'; // 获取 PHP 传下来的 tab ID
    var triggerEl = document.querySelector('button[data-bs-target="#tab-' + activeTab + '"]');
    if (triggerEl) {
        var tab = new bootstrap.Tab(triggerEl);
        tab.show();
    } else {
        // 保底：找不到就显示第一个
        var firstTab = new bootstrap.Tab(document.querySelector('.nav-link'));
        firstTab.show();
    }

    // 初始化 DNS 表单状态
    toggleDns(document.querySelector('select[name="dns_provider"]').value);
});

function toggleDns(val) {
    if (val === 'huaweicloud') {
        document.getElementById('hwForm').style.display = 'flex';
        document.getElementById('cfForm').style.display = 'none';
    } else {
        document.getElementById('hwForm').style.display = 'none';
        document.getElementById('cfForm').style.display = 'flex';
    }
}
</script>
</body>
</html>
