<?php
// cron_cert.php - V9.0 (Database-Driven Cleanup)

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
    $domainLower = strtolower($domain);
    $candidateDirs = array_unique([
        "$home/{$domain}_ecc", "$home/{$domainLower}_ecc", "$home/{$domain}", "$home/{$domainLower}"
    ]);

    foreach ($candidateDirs as $dir) {
        if (!is_dir($dir)) continue;
        $certFile = null;
        if (is_file("$dir/fullchain.cer")) $certFile = "$dir/fullchain.cer";
        elseif (is_file("$dir/$domain.cer")) $certFile = "$dir/$domain.cer";
        elseif (is_file("$dir/$domainLower.cer")) $certFile = "$dir/$domainLower.cer";

        $keyFile = null;
        if (is_file("$dir/$domain.key")) $keyFile = "$dir/$domain.key";
        elseif (is_file("$dir/$domainLower.key")) $keyFile = "$dir/$domainLower.key";

        if ($certFile && $keyFile) {
            $body = (string)file_get_contents($certFile);
            $kbody = (string)file_get_contents($keyFile);
            $info = openssl_x509_parse($body);
            $exp = (int)($info['validTo_time_t'] ?? (time() + 89 * 86400));
            $pdo->prepare("UPDATE certificates SET cert_body=?, key_body=?, expire_time=?, status=1, apply_status='success', status_msg='颁发成功' WHERE id=?")->execute([$body, $kbody, $exp, $id]);
            notify_all_nodes($pdo);
            echo "SUCCESS: 证书获取成功 [$domain] (Path: $dir)\n";
            return;
        }
    }
    $scanned = implode(", ", array_map('basename', $candidateDirs));
    update_log($pdo, $id, 'failed', "验证完成但未找到证书文件 (已扫描: $scanned)");
}

// 调用 Python 脚本 (新增 $old_token 参数)
function push_txt_via_python($pdo, $domain, $txt_domain, $txt_value, $old_token) {
    echo ">> 启动 Python 脚本 (追加新值: " . substr($txt_value,0,5) . "..., 删除旧值: " . ($old_token ? substr($old_token,0,5)."..." : "无") . ")\n";
    
    $ak = get_conf($pdo, 'hw_ak');
    $sk = get_conf($pdo, 'hw_sk');
    $zid = ''; 
    $reg = get_conf($pdo, 'hw_region');

    if (!$ak || !$sk) { echo "ERROR: AK/SK Missing\n"; return false; }

    $payload = [
        'zone_id' => $zid,
        'region' => $reg,
        'domain' => $txt_domain,
        'value' => $txt_value,
        'remove_value' => $old_token // 告诉 Python 精准删除这个值
    ];

    $tmpFile = '/tmp/cert_txt_' . md5($domain) . '.json';
    file_put_contents($tmpFile, json_encode($payload));

    $pyScript = __DIR__ . '/hw_txt_pusher.py';
    $cmd = "export CLOUD_SDK_AK='" . escapeshellcmd($ak) . "' && " .
           "export CLOUD_SDK_SK='" . escapeshellcmd($sk) . "' && " .
           "python3 " . escapeshellarg($pyScript) . " " . escapeshellarg($tmpFile) . " 2>&1";

    $output = shell_exec($cmd);
    echo ">> Python 输出:\n$output\n";
    return strpos($output, 'Success!') !== false;
}

$ACME_BIN = __DIR__ . '/acme_tool/acme.sh';
$CERT_HOME = __DIR__ . '/cert_data';
if (!is_file($ACME_BIN)) die("acme.sh missing\n");

$envVars = 'export LE_WORKING_DIR=' . escapeshellarg($CERT_HOME) . ' && ';
$acmeFlags = ' --server letsencrypt';
if (get_conf($pdo, 'cert_ca_provider') === 'zerossl' && ($mail = get_conf($pdo, 'cert_ca_email'))) $acmeFlags = ' --server zerossl -m ' . escapeshellarg($mail);

$tasks = $pdo->query("SELECT * FROM certificates WHERE apply_status IN ('processing', 'verifying', 'pending_txt', 'force_renew') ORDER BY id ASC")->fetchAll();

foreach ($tasks as $cert) {
    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    $status = (string)$cert['apply_status'];
    $mode = (string)$cert['mode'];
    $provider = (string)$cert['provider'];
    
    // [关键] 从数据库获取上次记录的 TXT 值 (用于精准清理)
    $oldTxtValue = (string)($cert['dns_txt_value'] ?? '');

    if ($mode === 'auto' && $provider === 'huaweicloud' && ($status === 'processing' || $status === 'force_renew')) {
        update_log($pdo, $id, 'verifying', 'Python 自动化流程启动...');
        
        // 1. 获取新令牌
        $cmdGet = $envVars . escapeshellcmd($ACME_BIN) . " --issue -d " . escapeshellarg($domain) . " --dns --yes-I-know-dns-manual-mode-enough-go-ahead-please " . $acmeFlags . " 2>&1";
        $outGet = (string)shell_exec($cmdGet);
        if (strpos($outGet, 'Skipping') !== false) { check_result($pdo, $id, $domain, $CERT_HOME); continue; }

        $txtDomain = ''; $txtValue = '';
        if (preg_match("/Domain: ['\"]?([^'\"\s\r\n]+)['\"]?/", $outGet, $m1)) $txtDomain = $m1[1];
        if (preg_match("/TXT value: ['\"]?([^'\"\s\r\n]+)['\"]?/", $outGet, $m2)) $txtValue = $m2[1];

        if (!$txtDomain || !$txtValue) {
            update_log($pdo, $id, 'failed', '获取 TXT 令牌失败');
            continue;
        }

        // 2. Python 推送 (传入 Old Token)
        if (push_txt_via_python($pdo, $domain, $txtDomain, $txtValue, $oldTxtValue)) {
            
            // [关键] 推送成功后，立即更新数据库中的 dns_txt_value 为最新的
            // 这样下一次续费时，这个值就会变成 old_token 被传进去删除
            $pdo->prepare("UPDATE certificates SET dns_txt_domain=?, dns_txt_value=? WHERE id=?")
                ->execute([$txtDomain, $txtValue, $id]);

            echo "Step 3: 等待 DNS 生效 (20秒)...\n";
            sleep(20);
            
            echo "Step 4: 执行验证...\n";
            $cmdVerify = $envVars . escapeshellcmd($ACME_BIN) . " --renew -d " . escapeshellarg($domain) . " --yes-I-know-dns-manual-mode-enough-go-ahead-please " . $acmeFlags . " 2>&1";
            $outVerify = shell_exec($cmdVerify);
            echo ">> Acme Verify Output:\n$outVerify\n";
            
            check_result($pdo, $id, $domain, $CERT_HOME);
        } else {
            update_log($pdo, $id, 'failed', 'Python 推送 TXT 失败');
        }
    }
}
?>
