<?php
declare(strict_types=1);

function svorl_client_key(): string {
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $ip = $forwarded !== '' ? trim(explode(',', $forwarded)[0]) : $remote;
    return hash('sha256', $ip . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function svorl_allow(int $limit = 10, int $window = 300): bool {
    $dir = dirname(__DIR__) . '/storage/order-rate-limit';
    if ((!is_dir($dir) && !@mkdir($dir, 0755, true)) || !is_writable($dir)) {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-order-rate-limit';
        if ((!is_dir($dir) && !@mkdir($dir, 0755, true)) || !is_writable($dir)) return false;
    }
    $path = $dir . '/' . svorl_client_key() . '.json';
    $now = time();
    $state = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $started = (int)($state['started_at'] ?? 0);
    $count = (int)($state['count'] ?? 0);
    if ($started <= 0 || ($now - $started) >= $window) { $started = $now; $count = 0; }
    $count++;
    file_put_contents($path, json_encode(['started_at'=>$started,'count'=>$count]), LOCK_EX);
    return $count <= $limit;
}
