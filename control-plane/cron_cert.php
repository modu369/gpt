<?php
// cron_cert.php - V8.4 (Fix: 强制自动发现 ZoneID，解决跨域名申请报错 DNS.0304)

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify.php';

set_time_limit(900);

function get_conf(PDO $pdo, string $k): string
{
    $s = $pdo->prepare('SELECT value_json FROM settings WHERE key_name = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    if ($v === false || $v === null) return '';
    $decoded = json_decode((string)$v, true);
    return is_string($decoded) ? $decoded : '';
}

function update_log(PDO $pdo, int $id, string $status, string $msg): void
{
    echo "[$id] $status: $msg\n";
    $pdo->prepare('UPDATE certificates SET apply_status=?, status_msg=? WHERE id=?')->execute([$status, $msg, $id]);
}

function check_result(PDO $pdo, int $id, string $domain, string $home): void
{
    $dirs = ["$home/{$domain}_ecc", "$home/$domain"];
    foreach ($dirs as $dir) {
        if (is_file("$dir/fullchain.cer") && is_file("$dir/$domain.key")) {
            $body = (string)file_get_contents("$dir/fullchain.cer");
            $kbody = (string)file_get_contents("$dir/$domain.key");
            $info = openssl_x509_parse($body);
            $exp = (int)($info['validTo_time_t'] ?? (time() + 90 * 86400));

            $pdo->prepare("UPDATE certificates SET cert_body=?, key_body=?, expire_time=?, status=1, apply_status='success', status_msg='颁发成功' WHERE id=?")
                ->execute([$body, $kbody, $exp, $id]);
            
            notify_all_nodes($pdo);
            echo "SUCCESS: 证书获取成功 [$domain]\n";
            return;
        }
    }
    update_log($pdo, $id, 'failed', '验证完成但未找到证书文件');
}

// 核心函数：调用 Python
function push_txt_via_python($pdo, $domain, $txt_domain, $txt_value) {
    echo ">> 启动 Python 脚本推送 TXT 记录...\n";
    echo "   Domain: $txt_domain\n";

    $ak = get_conf($pdo, 'hw_ak');
    $sk = get_conf($pdo, 'hw_sk');
    
    // [核心修复] 这里强制留空！不要读取 get_conf($pdo, 'hw_zone_id')
    // 原因：后台配置的 ZoneID 通常是主业务域名的，如果现在申请另一个域名的证书，
    // 传这个 ID 会导致 "DNS.0304 Record set name must be ended with this zone name"
    // 留空后，Python 脚本(V2.0+)会自动根据 txt_domain 去查找正确的 ZoneID。
    $zid = ''; 
    
    $reg = get_conf($pdo, 'hw_region');

    if (!$ak || !$sk) {
        echo "ERROR: 华为云 AK/SK 未配置\n";
        return false;
    }

    $payload = [
        'zone_id' => $zid, // 传空字符串，激活 Python 的自动发现逻辑
        'region' => $reg,
        'domain' => $txt_domain,
        'value' => $txt_value
    ];

    $tmpFile = '/tmp/cert_txt_' . md5($domain) . '.json';
    file_put_contents($tmpFile, json_encode($payload));

    $pyScript = __DIR__ . '/hw_txt_pusher.py';
    $cmd = "export CLOUD_SDK_AK='" . escapeshellcmd($ak) . "' && " .
           "export CLOUD_SDK_SK='" . escapeshellcmd($sk) . "' && " .
           "python3 " . escapeshellarg($pyScript) . " " . escapeshellarg($tmpFile) . " 2>&1";

    $output = shell_exec($cmd);
    echo ">> Python 输出:\n$output\n";

    if (strpos($output, 'Success!') !== false) {
        return true;
    }
    return false;
}

$ACME_BIN = __DIR__ . '/acme_tool/acme.sh';
$CERT_HOME = __DIR__ . '/cert_data';

if (!is_file($ACME_BIN)) die("acme.sh missing\n");

$envVars = 'export LE_WORKING_DIR=' . escapeshellarg($CERT_HOME) . ' && ';

$caProvider = get_conf($pdo, 'cert_ca_provider');
$caEmail = get_conf($pdo, 'cert_ca_email');
$acmeFlags = ' --server letsencrypt';
if ($caProvider === 'zerossl' && !empty($caEmail)) {
    $acmeFlags = ' --server zerossl -m ' . escapeshellarg($caEmail);
}

$tasks = $pdo->query("SELECT * FROM certificates WHERE apply_status IN ('processing', 'verifying', 'pending_txt', 'force_renew') ORDER BY id ASC")->fetchAll();

foreach ($tasks as $cert) {
    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    $status = (string)$cert['apply_status'];
    $mode = (string)$cert['mode'];
    $provider = (string)$cert['provider']; 

    if ($domain === '') continue;
    
    // CF 逻辑 (保持不变)
    if ($mode === 'auto' && $provider === 'cloudflare') {
        $cfKey = get_conf($pdo, 'cf_key');
        $cfEmail = get_conf($pdo, 'cf_email');
        $env = $envVars . "export CF_Key='$cfKey' && export CF_Email='$cfEmail' && ";
        $cmd = $env . escapeshellcmd($ACME_BIN) . " --issue --dns dns_cf -d " . escapeshellarg($domain) . $acmeFlags . " 2>&1";
        shell_exec($cmd);
        check_result($pdo, $id, $domain, $CERT_HOME);
        continue;
    }

    // === 华为云自动逻辑 (Python 介入) ===
    if ($mode === 'auto' && $provider === 'huaweicloud' && ($status === 'processing' || $status === 'force_renew')) {
        update_log($pdo, $id, 'verifying', '正在调用 Python 自动申请...');

        // 1. 获取 TXT 令牌
        echo "Step 1: 获取 TXT 令牌...\n";
        $cmdGet = $envVars . escapeshellcmd($ACME_BIN) . " --issue -d " . escapeshellarg($domain) . " --dns --yes-I-know-dns-manual-mode-enough-go-ahead-please " . $acmeFlags . " 2>&1";
        $outGet = (string)shell_exec($cmdGet);

        if (strpos($outGet, 'Skipping') !== false) {
            echo "证书已存在，跳过申请。\n";
            check_result($pdo, $id, $domain, $CERT_HOME);
            continue;
        }

        $txtDomain = ''; $txtValue = '';
        if (preg_match("/Domain: ['\"]?([^'\"\s\r\n]+)['\"]?/", $outGet, $m1)) $txtDomain = $m1[1];
        if (preg_match("/TXT value: ['\"]?([^'\"\s\r\n]+)['\"]?/", $outGet, $m2)) $txtValue = $m2[1];

        if (!$txtDomain || !$txtValue) {
            update_log($pdo, $id, 'failed', '获取 TXT 令牌失败');
            echo "Output: " . substr($outGet, -500) . "\n";
            continue;
        }

        // 2. Python 推送 (自动寻找 ZoneID)
        echo "Step 2: Python 推送 TXT...\n";
        if (!push_txt_via_python($pdo, $domain, $txtDomain, $txtValue)) {
            update_log($pdo, $id, 'failed', 'Python 推送失败: 未找到 ZoneID 或 权限不足');
            continue;
        }

        // 3. 等待
        echo "Step 3: 等待 DNS 生效 (20秒)...\n";
        sleep(20);

        // 4. 验证
        echo "Step 4: 执行验证...\n";
        $cmdVerify = $envVars . escapeshellcmd($ACME_BIN) . " --renew -d " . escapeshellarg($domain) . " --yes-I-know-dns-manual-mode-enough-go-ahead-please " . $acmeFlags . " 2>&1";
        shell_exec($cmdVerify);
        check_result($pdo, $id, $domain, $CERT_HOME);
    }
}
?>
