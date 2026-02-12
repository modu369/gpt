<?php
// cron_dns.php - 双核智能调度器 (Cloudflare + Huawei Cloud)
// V4.0: 支持多 DNS 服务商切换，支持华为云 1秒 TTL 和 原生权重

require_once __DIR__ . '/db.php';

// === 配置获取辅助函数 ===
function get_conf($pdo, $k) {
    $s = $pdo->prepare("SELECT value_json FROM settings WHERE key_name=?");
    $s->execute([$k]); $v=$s->fetchColumn(); return $v?json_decode($v,true):'';
}

// === 1. 定义 DNS 接口标准 ===
interface DnsDriver {
    // 同步 IP 列表 (target_ips 结构: ['ip' => '1.1.1.1', 'weight' => 50])
    public function sync($record_name, $target_nodes);
}

// === 2. Cloudflare 驱动 (原有逻辑) ===
class CloudflareDriver implements DnsDriver {
    private $email, $key, $zone_id;
    public function __construct($e, $k, $z) { $this->email=$e; $this->key=$k; $this->zone_id=$z; }

    public function sync($record_name, $nodes) {
        // CF 免费版不支持 API 设置权重，只能通过“存在即命中”的概率调度
        // 这里的 $nodes 已经是经过上层“概率算法”筛选过的最终 IP 列表

        $headers = ["X-Auth-Email: {$this->email}", "X-Auth-Key: {$this->key}", "Content-Type: application/json"];

        // 获取现有记录
        $url = "https://api.cloudflare.com/client/v4/zones/{$this->zone_id}/dns_records?type=A&name={$record_name}";
        $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($res['success']) || !$res['success']) {
            echo "CF API Error\n";
            return;
        }

        $current_map = []; // IP => ID
        foreach ($res['result'] as $r) {
            // 匹配完整域名
            if (strpos($r['name'], $record_name) !== false) {
                $current_map[$r['content']] = $r['id'];
            }
        }

        // 提取目标 IP (CF 驱动只关心 IP，不关心权重值)
        $target_ips = array_column($nodes, 'ip');

        $to_add = array_diff($target_ips, array_keys($current_map));
        $to_del = array_diff(array_keys($current_map), $target_ips);

        // 删除
        foreach ($to_del as $ip) {
            echo " -> [CF] 删除: $ip\n";
            $rid = $current_map[$ip];
            $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$this->zone_id}/dns_records/$rid");
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            curl_close($ch);
        }

        // 添加
        foreach ($to_add as $ip) {
            echo " -> [CF] 添加: $ip\n";
            $data = ['type' => 'A', 'name' => $record_name, 'content' => $ip, 'ttl' => 60, 'proxied' => false];
            $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$this->zone_id}/dns_records");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            curl_close($ch);
        }
    }
}

// === 3. 华为云 驱动 (新功能) ===
class HuaweiDriver implements DnsDriver {
    private $ak, $sk, $zone_id, $region;

    public function __construct($ak, $sk, $zid, $reg) {
        $this->ak = $ak;
        $this->sk = $sk;
        $this->zone_id = $zid;
        $this->region = $reg;
    }

    public function sync($record_name, $nodes) {
        echo " -> [华为云] 正在同步... (目标节点数: " . count($nodes) . ")\n";

        $batch_list = [];
        foreach ($nodes as $node) {
            $batch_list[] = [
                'name' => $record_name,
                'type' => 'A',
                'ttl' => 1,
                'weight' => intval($node['weight']),
                'records' => [$node['ip']],
                'status' => 'ENABLE',
            ];
        }

        // 实际发送请求：调用 Python 辅助脚本处理签名
        $json_payload = json_encode(['zone_id' => $this->zone_id, 'region' => $this->region, 'records' => $batch_list], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp_file = '/tmp/hw_dns_payload.json';
        file_put_contents($tmp_file, $json_payload);

        $cmd = "export CLOUD_SDK_AK='" . addslashes($this->ak) . "' && export CLOUD_SDK_SK='" . addslashes($this->sk) . "' && python3 " . escapeshellarg(__DIR__ . '/hw_dns_pusher.py') . " " . escapeshellarg($tmp_file);
        $output = shell_exec($cmd);
        echo " -> [华为云] Python 响应: " . ($output ?? '') . "\n";
    }
}

// === 4. 主逻辑 ===

echo "[" . date('Y-m-d H:i:s') . "] 开始智能调度...\n";

// A. 筛选节点
$nodes = $pdo->query("SELECT * FROM nodes WHERE status=1")->fetchAll();
$candidates = [];
foreach ($nodes as $node) {
    if (time() - (int)$node['last_heartbeat'] > 60) {
        continue;
    }
    $limit_bytes = (int)$node['traffic_limit'] * 1024 * 1024 * 1024;
    if ($limit_bytes > 0 && (float)$node['traffic_used'] > ($limit_bytes * 0.95)) {
        continue;
    }
    $candidates[] = $node;
}

// B. 计算权重 (生成目标列表)
$final_nodes = [];
$dns_provider = (string)get_conf($pdo, 'dns_provider');
if ($dns_provider === '') {
    $dns_provider = 'cloudflare';
}

// 如果是 华为云，保留所有合格节点并计算精确权重
if ($dns_provider === 'huaweicloud') {
    foreach ($candidates as $node) {
        $weight = intval($node['weight']);
        if ((float)$node['cpu_usage'] > 95) {
            $weight = 0;
        } elseif ((float)$node['cpu_usage'] > 80) {
            $weight = intval($weight * 0.2);
        }

        if ((int)$node['max_bandwidth'] > 0) {
            $bwPct = ((int)$node['current_bandwidth'] / max(1, (int)$node['max_bandwidth'])) * 100;
            if ($bwPct > 95) {
                $weight = 0;
            } elseif ($bwPct > 80) {
                $weight = intval($weight * 0.3);
            }
        }

        if ($weight > 0) {
            $final_nodes[] = ['ip' => $node['ip_address'], 'weight' => $weight];
        }
    }
} else {
    // Cloudflare: 用概率算法模拟权重
    foreach ($candidates as $node) {
        $weight = max(0, min(100, (int)$node['weight']));
        $cpu = (float)$node['cpu_usage'];
        if ($cpu > 95) {
            $weight = 0;
        } elseif ($cpu > 80) {
            $weight = (int)floor($weight * 0.5);
        }

        if ((int)$node['max_bandwidth'] > 0) {
            $bwPct = ((int)$node['current_bandwidth'] / max(1, (int)$node['max_bandwidth'])) * 100;
            if ($bwPct > 95) {
                $weight = 0;
            } elseif ($bwPct > 80) {
                $weight = (int)floor($weight * 0.3);
            }
        }

        if ($weight > 0 && $weight >= mt_rand(1, 100)) {
            $final_nodes[] = ['ip' => $node['ip_address'], 'weight' => 0];
        }
    }
}

// 兜底逻辑
if (empty($final_nodes) && !empty($candidates)) {
    foreach ($candidates as $n) {
        $final_nodes[] = ['ip' => $n['ip_address'], 'weight' => 1];
    }
}

// C. 执行同步
$record_name = (string)get_conf($pdo, 'cf_record_name');
if ($record_name === '') {
    $record_name = 'cdn';
}

if ($dns_provider === 'huaweicloud') {
    $ak = (string)get_conf($pdo, 'hw_ak');
    $sk = (string)get_conf($pdo, 'hw_sk');
    $zid = (string)get_conf($pdo, 'hw_zone_id');
    $reg = (string)get_conf($pdo, 'hw_region');
    if ($reg === '') {
        $reg = 'ap-southeast-1';
    }

    if ($ak !== '' && $sk !== '' && $zid !== '') {
        $driver = new HuaweiDriver($ak, $sk, $zid, $reg);
        $driver->sync($record_name, $final_nodes);
    } else {
        echo "❌ 华为云配置缺失\n";
    }
} else {
    $mail = (string)get_conf($pdo, 'cf_email');
    $key  = (string)get_conf($pdo, 'cf_key');
    $zid  = (string)get_conf($pdo, 'cf_zone_id');

    if ($mail !== '' && $key !== '' && $zid !== '') {
        $driver = new CloudflareDriver($mail, $key, $zid);
        $driver->sync($record_name, $final_nodes);
    } else {
        echo "❌ Cloudflare 配置缺失\n";
    }
}

echo "✅ 调度完成。\n";
