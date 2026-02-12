<?php
// cron_cert.php - V7.0 (支持手动 DNS 验证解析)

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify.php';

set_time_limit(300);

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

function check_result(PDO $pdo, int $id, string $domain, string $home, string $out): void
{
    $cer = "$home/$domain/fullchain.cer";
    $key = "$home/$domain/$domain.key";

    if (!is_file($cer)) {
        $cer = "$home/$domain/$domain.cer";
    }

    if (is_file($cer) && is_file($key)) {
        $body = (string)file_get_contents($cer);
        $kbody = (string)file_get_contents($key);
        $info = openssl_x509_parse($body);
        $exp = (int)($info['validTo_time_t'] ?? (time() + 90 * 86400));

        $pdo->prepare("UPDATE certificates SET cert_body=?, key_body=?, expire_time=?, status=1, apply_status='success', status_msg='颁发成功' WHERE id=?")
            ->execute([$body, $kbody, $exp, $id]);
        notify_all_nodes($pdo);
        return;
    }

    if (stripos($out, 'Verify error') !== false) {
        $pdo->prepare("UPDATE certificates SET apply_status='failed', status_msg='验证失败：DNS记录未生效' WHERE id=?")->execute([$id]);
        return;
    }

    $err = mb_substr(trim(strip_tags($out)), -200);
    update_log($pdo, $id, 'failed', '申请失败: ' . $err);
}

$ACME_BIN = __DIR__ . '/acme_tool/acme.sh';
$CERT_HOME = __DIR__ . '/cert_data';
if (!is_file($ACME_BIN)) {
    fwrite(STDERR, "acme.sh not found: {$ACME_BIN}\n");
    exit(1);
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

$tasks = $pdo->query("SELECT * FROM certificates WHERE apply_status IN ('processing', 'verifying') ORDER BY id ASC")->fetchAll();
foreach ($tasks as $cert) {
    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    $status = (string)$cert['apply_status'];
    if ($domain === '') {
        update_log($pdo, $id, 'failed', '域名为空');
        continue;
    }

    if ((string)$cert['mode'] === 'auto' && $status === 'processing') {
        $dnsApi = ((string)$cert['provider'] === 'huaweicloud') ? 'dns_huaweicloud' : 'dns_cf';
        update_log($pdo, $id, 'verifying', '自动 API 申请中...');
        $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --issue --dns ' . escapeshellarg($dnsApi) . ' -d ' . escapeshellarg($domain) . ' --force 2>&1';
        $out = (string)shell_exec($cmd);
        check_result($pdo, $id, $domain, $CERT_HOME, $out);
        continue;
    }

    if ((string)$cert['mode'] === 'manual' && $status === 'processing') {
        update_log($pdo, $id, 'pending_txt', '正在生成 TXT 记录...');
        $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --issue -d ' . escapeshellarg($domain) . ' --dns --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1';
        $out = (string)shell_exec($cmd);

        $txtDomain = '';
        $txtValue = '';
        if (preg_match("/Domain:\s*'([^']+)'/", $out, $m1)) {
            $txtDomain = $m1[1];
        }
        if (preg_match("/TXT value:\s*'([^']+)'/", $out, $m2)) {
            $txtValue = $m2[1];
        }

        if ($txtDomain !== '' && $txtValue !== '') {
            $pdo->prepare("UPDATE certificates SET dns_txt_domain=?, dns_txt_value=?, apply_status='wait_verify', status_msg='请添加 TXT 记录' WHERE id=?")
                ->execute([$txtDomain, $txtValue, $id]);
            echo "[$domain] TXT 获取成功: $txtValue\n";
        } else {
            update_log($pdo, $id, 'failed', '获取 TXT 失败，请检查日志');
        }
        continue;
    }

    if ((string)$cert['mode'] === 'manual' && $status === 'verifying') {
        update_log($pdo, $id, 'verifying', '正在验证 DNS...');
        $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --renew -d ' . escapeshellarg($domain) . ' --yes-I-know-dns-manual-mode-enough-go-ahead-please 2>&1';
        $out = (string)shell_exec($cmd);
        check_result($pdo, $id, $domain, $CERT_HOME, $out);
    }
}

$expiring = $pdo->query('SELECT * FROM certificates WHERE auto_renew=1 AND expire_time < ' . (time() + 5 * 86400) . ' AND status=1 ORDER BY id ASC')->fetchAll();
foreach ($expiring as $cert) {
    if ((string)$cert['mode'] !== 'auto') {
        continue;
    }

    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    if ($domain === '') {
        continue;
    }

    update_log($pdo, $id, 'renewing', '证书即将过期，正在自动续费...');
    $dnsApi = ((string)$cert['provider'] === 'huaweicloud') ? 'dns_huaweicloud' : 'dns_cf';
    $cmd = $envVars . escapeshellcmd($ACME_BIN) . ' --renew -d ' . escapeshellarg($domain) . ' --dns ' . escapeshellarg($dnsApi) . ' --force 2>&1';
    $out = (string)shell_exec($cmd);
    check_result($pdo, $id, $domain, $CERT_HOME, $out);
}
