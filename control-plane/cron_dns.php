<?php
// cron_dns.php - V5.0 旗舰版调度器 (CF + 华为云 + 全维度监控)
require_once __DIR__ . '/db.php';

// === 配置获取 ===
function get_conf($pdo, $k) {
    $s = $pdo->prepare("SELECT value_json FROM settings WHERE key_name=?");
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v ? json_decode($v, true) : '';
}

// === 1. 定义 DNS 驱动 ===
interface DnsDriver {
    // $nodes 结构: [['ip'=>'1.1.1.1', 'weight'=>50], ...]
    public function sync($record_name, $nodes);
}

// --- Cloudflare 驱动 ---
class CloudflareDriver implements DnsDriver {
    private $email, $key, $zone_id;
    public function __construct($e, $k, $z) { $this->email = $e; $this->key = $k; $this->zone_id = $z; }

    public function sync($name, $nodes) {
        $headers = ["X-Auth-Email: {$this->email}", "X-Auth-Key: {$this->key}", "Content-Type: application/json"];
        $url = "https://api.cloudflare.com/client/v4/zones/{$this->zone_id}/dns_records?type=A&name={$name}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (!isset($res['success']) || !$res['success']) {
            return;
        }

        $current = [];
        foreach ($res['result'] as $r) {
            if (strpos($r['name'], $name) !== false) {
                $current[$r['content']] = $r['id'];
            }
        }
        $targets = array_column($nodes, 'ip');

        // 删除
        foreach (array_diff(array_keys($current), $targets) as $ip) {
            $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$this->zone_id}/dns_records/" . $current[$ip]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_exec($ch);
            curl_close($ch);
        }
        // 添加
        foreach (array_diff($targets, array_keys($current)) as $ip) {
            $data = ['type' => 'A', 'name' => $name, 'content' => $ip, 'ttl' => 60, 'proxied' => false];
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

// --- 华为云 驱动 (调用 Python 脚本) ---
class HuaweiDriver implements DnsDriver {
    private $ak, $sk, $zid, $reg;
    public function __construct($a, $s, $z, $r) { $this->ak = $a; $this->sk = $s; $this->zid = $z; $this->reg = $r; }

    public function sync($name, $nodes) {
        // 构造 JSON 数据传给 Python
        $payload = ['zone_id' => $this->zid, 'region' => $this->reg, 'records' => []];
        foreach ($nodes as $n) {
            $payload['records'][] = [
                'name' => $name,
                'type' => 'A',
                'ttl' => 1,
                'weight' => intval($n['weight']),
                'records' => [$n['ip']],
                'status' => 'ENABLE',
            ];
        }
        $tmp = '/tmp/hw_dns.json';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // 调用 Python SDK
        $cmd = "export CLOUD_SDK_AK='" . addslashes($this->ak) . "' && export CLOUD_SDK_SK='" . addslashes($this->sk) . "' && python3 " . escapeshellarg(__DIR__ . '/hw_dns_pusher.py') . ' ' . escapeshellarg($tmp);
        shell_exec($cmd);
    }
}

// === 2. 调度主逻辑 ===
echo "[" . date('H:i:s') . "] 开始智能调度...\n";
$nodes = $pdo->query("SELECT * FROM nodes WHERE status=1")->fetchAll();
$candidates = [];

// A. 硬性筛选
foreach ($nodes as $n) {
    if (time() - (int)$n['last_heartbeat'] > 60) {
        continue;
    }
    if ((int)$n['traffic_limit'] > 0 && (float)$n['traffic_used'] > (int)$n['traffic_limit'] * 1073741824 * 0.95) {
        continue;
    }
    $candidates[] = $n;
}

// B. 动态权重计算 (核心智能)
$final_nodes = [];
$provider = get_conf($pdo, 'dns_provider');
$is_hw = ($provider === 'huaweicloud');

// 单节点保护
if (count($candidates) === 1) {
    $n = $candidates[0];
    $final_nodes[] = ['ip' => $n['ip_address'], 'weight' => $is_hw ? 100 : 0];
} else {
    foreach ($candidates as $n) {
        $w = intval($n['weight']);

        // 1. CPU 降权
        if ((float)$n['cpu_usage'] > 95) {
            $w = 0;
        } elseif ((float)$n['cpu_usage'] > 80) {
            $w = intval($w * 0.2);
        }

        // 2. 带宽 降权
        if ((int)$n['max_bandwidth'] > 0) {
            $bw_pct = ((float)$n['current_bandwidth'] / max(1, (int)$n['max_bandwidth'])) * 100;
            if ($bw_pct > 95) {
                $w = 0;
            } elseif ($bw_pct > 80) {
                $w = intval($w * 0.2);
            }
        }

        // 3. 内存 降权 (新增)
        if ((int)$n['max_ram'] > 0) {
            $ram_pct = ((float)$n['ram_usage'] / max(1, (int)$n['max_ram'])) * 100;
            if ($ram_pct > 90) {
                $w = intval($w * 0.5);
            }
        }

        if ($w > 0) {
            if ($is_hw) {
                $final_nodes[] = ['ip' => $n['ip_address'], 'weight' => $w];
            } else {
                if ((int)$n['weight'] >= mt_rand(1, 100)) {
                    $final_nodes[] = ['ip' => $n['ip_address'], 'weight' => 0];
                }
            }
        }
    }

    // 全员兜底
    if (empty($final_nodes) && !empty($candidates)) {
        foreach ($candidates as $n) {
            $final_nodes[] = ['ip' => $n['ip_address'], 'weight' => 1];
        }
    }
}

// C. 执行同步
$rec_name = get_conf($pdo, 'cf_record_name');
if (!is_string($rec_name) || $rec_name === '') {
    $rec_name = 'cdn';
}

if ($is_hw) {
    $driver = new HuaweiDriver((string)get_conf($pdo, 'hw_ak'), (string)get_conf($pdo, 'hw_sk'), (string)get_conf($pdo, 'hw_zone_id'), (string)get_conf($pdo, 'hw_region'));
} else {
    $driver = new CloudflareDriver((string)get_conf($pdo, 'cf_email'), (string)get_conf($pdo, 'cf_key'), (string)get_conf($pdo, 'cf_zone_id'));
}
$driver->sync($rec_name, $final_nodes);
echo "完成.\n";
