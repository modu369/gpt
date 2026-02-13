<?php
// cron_cert.php - V8.0 (ECC路径修复 + 10天续签策略 + 强制续费支持)

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify.php';

set_time_limit(600);

function get_conf(PDO $pdo, string $k): string
{
    $s = $pdo->prepare('SELECT value_json FROM settings WHERE key_name = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    if ($v === false || $v === null) {
        return '';
    }
    $decoded = json_decode((string)$v, true);
    return is_string($decoded) ? $decoded : '';
}

function update_log(PDO $pdo, int $id, string $status, string $msg): void
{
    echo "[$id] $status: $msg\n";
    $pdo->prepare('UPDATE certificates SET apply_status=?, status_msg=? WHERE id=?')->execute([$status, $msg, $id]);
}

// 核心修复：增强文件查找和结果判断
function check_result(PDO $pdo, int $id, string $domain, string $home, string $out): void
{
    // 1. 优先尝试查找 ECC 目录，其次是普通目录
    $dirs = ["$home/{$domain}_ecc", "$home/$domain"];
    $cer = '';
    $key = '';
    $found = false;

    foreach ($dirs as $dir) {
        if (is_file("$dir/fullchain.cer") && is_file("$dir/$domain.key")) {
            $cer = "$dir/fullchain.cer";
            $key = "$dir/$domain.key";
            $found = true;
            break;
        }
        // 兼容部分旧版本文件名
        if (is_file("$dir/$domain.cer") && is_file("$dir/$domain.key")) {
            $cer = "$dir/$domain.cer";
            $key = "$dir/$domain.key";
            $found = true;
            break;
        }
    }

    // 2. 如果文件存在，无视输出日志，直接视为成功
    if ($found) {
        $body = (string)file_get_contents($cer);
        $kbody = (string)file_get_contents($key);
        $info = openssl_x509_parse($body);
        // 获取准确的过期时间
        $exp = (int)($info['validTo_time_t'] ?? (time() + 90 * 86400));

        // 更新数据库
        $pdo->prepare("UPDATE certificates SET cert_body=?, key_body=?, expire_time=?, status=1, apply_status='success', status_msg='颁发成功' WHERE id=?")
            ->execute([$body, $kbody, $exp, $id]);
        
        // 通知所有节点更新
        notify_all_nodes($pdo);
        echo "[$domain] 证书获取成功 (有效期至: " . date('Y-m-d', $exp) . ")\n";
        return;
    }

    // 3. 如果文件不存在，分析错误
    if (stripos($out, 'Verify error') !== false) {
        $pdo->prepare("UPDATE certificates SET apply_status='failed', status_msg='验证失败：DNS记录未生效' WHERE id=?")->execute([$id]);
        echo "[$domain] 验证失败：DNS记录未生效\n";
        return;
    }
    
    // 捕获并精简错误日志
    $cleanOut = preg_replace('/\x1b[^m]*m/', '', $out); // 去除颜色
    // 过滤掉无关的 Skipping 信息，只保留最后几行错误
    $lines = explode("\n", trim($cleanOut));
    $errLines = array_slice($lines, -3); 
    $err = implode(" ", $errLines);
    
    if (empty($err)) $err = "未知错误，未生成证书文件";
    
    update_log($pdo, $id, 'failed', '失败: ' . substr($err, 0, 250));
}

$ACME_BIN = __DIR__ . '/acme_tool/acme.sh';
$CERT_HOME = __DIR__ . '/cert_data';

if (!is_file($ACME_BIN)) {
    fwrite(STDERR, "acme.sh not found: {$ACME_BIN}\n");
    exit(1);
}

// === 准备 ACME 参数 ===
$caProvider = get_conf($pdo, 'cert_ca_provider');
if (!$caProvider) $caProvider = 'letsencrypt'; 
$caEmail = get_conf($pdo, 'cert_ca_email');

$acmeFlags = '';
if ($caProvider === 'zerossl') {
    $acmeFlags = ' --server zerossl';
    if ($caEmail) $acmeFlags .= ' -m ' . escapeshellarg($caEmail);
} else {
    $acmeFlags = ' --server letsencrypt';
}

$envVars = 'export LE_WORKING_DIR=' . escapeshellarg($CERT_HOME) . ' && ';
$cfEmail = get_conf($pdo, 'cf_email');
$cfKey = get_conf($pdo, 'cf_key');
if ($cfEmail !== '' && $cfKey !== '') {
    $envVars .= 'export CF_Key=' . escapeshellarg($cfKey) . ' && export CF_Email=' . escapeshellarg($cfEmail) . ' && ';
}
$hwAk = get_conf($pdo, 'hw_ak');
$hwSk = get_conf($pdo, 'hw_sk');
if ($hwAk !== '' && $hwSk !== '') {
    $envVars .= 'export HUAWEICLOUD_AK=' . escapeshellarg($hwAk) . ' && export HUAWEICLOUD_SK=' . escapeshellarg($hwSk) . ' && ';
}

// === 任务 1: 处理新申请 / 强制续费任务 ===
// 状态说明：
// processing: 刚提交申请
// pending_txt: 等待生成TXT
// verifying: 用户点击了“验证”
// force_renew: 用户手动点击了“续费”
$tasks = $pdo->query("SELECT * FROM certificates WHERE apply_status IN ('processing', 'verifying', 'pending_txt', 'force_renew') ORDER BY id ASC")->fetchAll();

foreach ($tasks as $cert) {
    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    $status = (string)$cert['apply_status'];
    $mode = (string)$cert['mode'];
    
    if ($domain === '') continue;

    // A. API 自动模式 (新增或强制续费)
    if ($mode === 'auto' && ($status === 'processing' || $status === 'force_renew')) {
        $dnsApi = ((string)$cert['provider'] === 'huaweicloud') ? 'dns_huaweicloud' : 'dns_cf';
        update_log($pdo, $id, 'verifying', '自动 API 申请中...');
        
        // 如果是 force_renew，添加 --force 参数
        $forceFlag = ($status === 'force_renew') ? ' --force' : '';
        
        $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --issue --dns ' . escapeshellarg($dnsApi) . ' -d ' . escapeshellarg($domain) . $acmeFlags . $forceFlag . ' 2>&1';
        $out = (string)shell_exec($cmd);
        check_result($pdo, $id, $domain, $CERT_HOME, $out);
        continue;
    }

    // B. 手动模式 - 获取 TXT
    if ($mode === 'manual' && ($status === 'processing' || $status === 'pending_txt')) {
        update_log($pdo, $id, 'pending_txt', '正在生成 TXT 记录...');
        $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --issue -d ' . escapeshellarg($domain) . ' --dns --yes-I-know-dns-manual-mode-enough-go-ahead-please' . $acmeFlags . ' 2>&1';
        $out = (string)shell_exec($cmd);

        $txtDomain = '';
        $txtValue = '';
        if (preg_match("/Domain: ['\"]?([^'\"\s\r\n]+)['\"]?/", $out, $m1)) $txtDomain = $m1[1];
        if (preg_match("/TXT value: ['\"]?([^'\"\s\r\n]+)['\"]?/", $out, $m2)) $txtValue = $m2[1];

        if ($txtDomain && $txtValue) {
            $pdo->prepare("UPDATE certificates SET dns_txt_domain=?, dns_txt_value=?, apply_status='wait_verify', status_msg='请添加 TXT 记录' WHERE id=?")
                ->execute([$txtDomain, $txtValue, $id]);
            echo "[$domain] TXT 获取成功\n";
        } else {
            // 如果日志显示 Skipping，说明证书已存在，直接检查文件
            if (stripos($out, 'Skipping') !== false) {
                echo "[$domain] 检测到已存在证书，尝试直接读取...\n";
                check_result($pdo, $id, $domain, $CERT_HOME, $out);
            } else {
                $cleanOut = preg_replace('/\x1b[^m]*m/', '', $out);
                update_log($pdo, $id, 'failed', '获取TXT失败: ' . substr(trim($cleanOut), -200));
            }
        }
        continue;
    }

    // C. 手动模式 - 验证 TXT (或手动强制续费)
    if ($mode === 'manual' && ($status === 'verifying' || $status === 'force_renew')) {
        update_log($pdo, $id, 'verifying', '正在验证 DNS...');
        // 强制续费时加 --force
        $forceFlag = ($status === 'force_renew') ? ' --force' : '';
        $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --renew -d ' . escapeshellarg($domain) . ' --yes-I-know-dns-manual-mode-enough-go-ahead-please' . $acmeFlags . $forceFlag . ' 2>&1';
        $out = (string)shell_exec($cmd);
        check_result($pdo, $id, $domain, $CERT_HOME, $out);
    }
}

// === 任务 2: 处理自动续费 (最后10天策略) ===
// 策略：expire_time < 当前时间 + 10天
$threshold = time() + (10 * 86400); 
$expiring = $pdo->query("SELECT * FROM certificates WHERE auto_renew=1 AND expire_time < $threshold AND status=1 AND apply_status NOT IN ('renewing', 'failed', 'force_renew') ORDER BY id ASC")->fetchAll();

foreach ($expiring as $cert) {
    if ((string)$cert['mode'] !== 'auto') continue;
    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    if ($domain === '') continue;

    update_log($pdo, $id, 'renewing', '证书即将过期(<10天)，自动续费中...');
    $dnsApi = ((string)$cert['provider'] === 'huaweicloud') ? 'dns_huaweicloud' : 'dns_cf';
    $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --renew -d ' . escapeshellarg($domain) . ' --dns ' . escapeshellarg($dnsApi) . $acmeFlags . ' --force 2>&1';
    $out = (string)shell_exec($cmd);
    check_result($pdo, $id, $domain, $CERT_HOME, $out);
}
?>
