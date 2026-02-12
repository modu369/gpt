<?php

declare(strict_types=1);

if (!function_exists('notify_all_nodes')) {
    function notify_all_nodes(PDO $pdo): array
    {
        $nodes = $pdo->query('SELECT ip_address, secret_key FROM nodes WHERE status = 1')->fetchAll();
        if (empty($nodes)) {
            return ['total' => 0, 'ok' => 0];
        }

        if (!function_exists('curl_multi_init')) {
            return ['total' => count($nodes), 'ok' => 0, 'error' => 'cURL 扩展未启用，无法广播'];
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($nodes as $node) {
            $ip = trim((string)$node['ip_address']);
            if ($ip === '') {
                continue;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, sprintf('http://%s:7777/reload', $ip));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Node-Secret: ' . (string)$node['secret_key']]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc === CURLM_OK) {
            if (curl_multi_select($mh) !== -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            }
        }

        $ok = 0;
        foreach ($handles as $ch) {
            if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $ok++;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return ['total' => count($handles), 'ok' => $ok];
    }
}
