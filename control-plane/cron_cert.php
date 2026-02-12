<?php
// cron_cert.php - 证书全自动申请与续费守护进程 (V6.0)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/notify.php';

set_time_limit(300);

function get_conf_cert(PDO $pdo, string $k): string
{
    $s = $pdo->prepare('SELECT value_json FROM settings WHERE key_name = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    if ($v === false || $v === null) {
        return '';
    }
    $d = json_decode((string)$v, true);
    return is_string($d) ? $d : '';
}

function update_cert_log(PDO $pdo, int $id, string $status, string $msg): void
{
    echo "[$id] $status: $msg\n";
    $pdo->prepare('UPDATE certificates SET apply_status = ?, status_msg = ? WHERE id = ?')->execute([$status, $msg, $id]);
}

function check_issue_result(PDO $pdo, int $id, string $domain, string $home, string $output): void
{
    $cerFile = "$home/$domain/$domain.cer";
    $keyFile = "$home/$domain/$domain.key";
    if (!is_file($cerFile)) {
        $cerFile = "$home/$domain/fullchain.cer";
    }

    if (is_file($cerFile) && is_file($keyFile)) {
        $certBody = (string)file_get_contents($cerFile);
        $keyBody = (string)file_get_contents($keyFile);
        $info = openssl_x509_parse($certBody);
        $expire = (int)($info['validTo_time_t'] ?? (time() + 90 * 86400));

        $pdo->prepare("UPDATE certificates SET cert_body=?, key_body=?, expire_time=?, status=1, apply_status='success', status_msg='申请/续费成功' WHERE id=?")
            ->execute([$certBody, $keyBody, $expire, $id]);

        echo "证书 $domain 获取成功！\n";
        notify_all_nodes($pdo);
        return;
    }

    $err = mb_substr(trim(strip_tags($output)), -200);
    update_cert_log($pdo, $id, 'failed', '申请失败: ' . $err);
}

$acmeBin = __DIR__ . '/acme_tool/acme.sh';
$certHome = __DIR__ . '/cert_data';
if (!is_file($acmeBin)) {
    fwrite(STDERR, "acme.sh not found: {$acmeBin}\n");
    exit(1);
}

$env = "export LE_WORKING_DIR=" . escapeshellarg($certHome) . ' && ';
$cfEmail = get_conf_cert($pdo, 'cf_email');
$cfKey = get_conf_cert($pdo, 'cf_key');
if ($cfEmail !== '' && $cfKey !== '') {
    $env .= 'export CF_Key=' . escapeshellarg($cfKey) . ' && export CF_Email=' . escapeshellarg($cfEmail) . ' && ';
}
$hwAk = get_conf_cert($pdo, 'hw_ak');
$hwSk = get_conf_cert($pdo, 'hw_sk');
if ($hwAk !== '' && $hwSk !== '') {
    $env .= 'export HUAWEICLOUD_AK=' . escapeshellarg($hwAk) . ' && export HUAWEICLOUD_SK=' . escapeshellarg($hwSk) . ' && ';
}

// 任务 A: processing 任务
$tasks = $pdo->query("SELECT * FROM certificates WHERE apply_status = 'processing' ORDER BY id ASC")->fetchAll();
foreach ($tasks as $cert) {
    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    if ($domain === '') {
        update_cert_log($pdo, $id, 'failed', '域名为空');
        continue;
    }

    if ((string)$cert['mode'] === 'auto') {
        $dnsApi = ((string)$cert['provider'] === 'huaweicloud') ? 'dns_huaweicloud' : 'dns_cf';
        update_cert_log($pdo, $id, 'verifying', "正在调用 {$dnsApi} 接口自动申请证书...");

        $cmd = $env . escapeshellcmd($acmeBin) . ' --issue --dns ' . escapeshellarg($dnsApi) . ' -d ' . escapeshellarg($domain) . ' --force --log 2>&1';
        $output = (string)shell_exec($cmd);
        check_issue_result($pdo, $id, $domain, $certHome, $output);
    } else {
        update_cert_log($pdo, $id, 'manual_upload', '手动模式：请直接粘贴证书内容，或改用自动模式。');
    }
}

// 任务 B: 自动续费
$expiring = $pdo->query('SELECT * FROM certificates WHERE auto_renew = 1 AND expire_time < ' . (time() + 5 * 86400) . " AND status = 1 ORDER BY id ASC")->fetchAll();
foreach ($expiring as $cert) {
    if ((string)$cert['mode'] !== 'auto') {
        continue;
    }

    $id = (int)$cert['id'];
    $domain = trim((string)$cert['domain']);
    if ($domain === '') {
        continue;
    }

    echo "发现即将过期证书: {$domain}，尝试续费...\n";
    update_cert_log($pdo, $id, 'renewing', '证书即将过期，正在自动续费...');

    $dnsApi = ((string)$cert['provider'] === 'huaweicloud') ? 'dns_huaweicloud' : 'dns_cf';
    $cmd = $env . escapeshellcmd($acmeBin) . ' --renew -d ' . escapeshellarg($domain) . ' --dns ' . escapeshellarg($dnsApi) . ' --force 2>&1';
    $output = (string)shell_exec($cmd);
    check_issue_result($pdo, $id, $domain, $certHome, $output);
}
