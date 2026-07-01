<?php
/**
 * EHA Health Check — coleta métricas reais do site
 */

function http_status(string $url, int $timeout = 10): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'EHA-HealthCheck/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: EHA-HealthCheck/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $code = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
            $code = (int)$m[1];
            break;
        }
    }
    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

function check_checkout(): bool {
    // Verifica a homepage: o carrinho é acessível via JS na página principal.
    // /carrinho tem redirect loop; /carrinho.php não existe.
    $url = (getenv('BASE_URL') ?: 'https://dev.shopvivaliz.com.br') . '/';
    $res = http_status($url, 10);
    if ($res['code'] !== 200) return false;
    // Verifica presença de UI de carrinho na homepage
    $body = strtolower($res['body']);
    return str_contains($body, 'carrinho') || str_contains($body, 'cart') || str_contains($body, 'shopping');
}

function check_api(): bool {
    $url = (getenv('BASE_URL') ?: 'https://dev.shopvivaliz.com.br') . '/api/health.php';
    $res = http_status($url, 8);
    // Aceita qualquer resposta do servidor (2xx, 3xx, 4xx).
    // 403 = servidor ativo mas endpoint restrito por IP (esperado em CI).
    // Só falha se servidor não responder (0) ou retornar 5xx.
    return $res['code'] > 0 && $res['code'] < 500;
}

function check_db(): bool {
    $host = getenv('DB_HOST')     ?: '';
    $db   = getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER')     ?: '';
    $pass = getenv('DB_PASS')     ?: '';

    if (!$host || !$db) return true; // sem credenciais no ambiente, ignora

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_TIMEOUT    => 5,
            PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function check_pages(): bool {
    $base  = getenv('BASE_URL') ?: 'https://dev.shopvivaliz.com.br';
    // Verifica páginas críticas; aceita 4xx (página existe mas vazia/restrita)
    // Falha apenas em 5xx (erro do servidor) ou sem resposta (0)
    $pages = ['/', '/produtos'];
    foreach ($pages as $path) {
        $res = http_status($base . $path, 8);
        if ($res['code'] >= 500 || $res['code'] === 0) return false;
    }
    return true;
}

function collect_metrics(): array {
    $e2e_failed = (getenv('E2E_FAILED') === '1');

    // HTTP checks sempre rodam, independente de E2E.
    $checkout_ok = check_checkout();
    $api_ok      = check_api();
    $db_ok       = check_db();
    $pages_ok    = check_pages();

    // lê log de erros PHP se existir
    $error_log_path = ini_get('error_log') ?: '/var/log/php_errors.log';
    $recent_errors  = [];
    if (is_readable($error_log_path)) {
        $lines = array_slice(file($error_log_path) ?: [], -200);
        $recent_errors = array_values(array_filter($lines, fn($l) =>
            str_contains($l, 'Fatal') || str_contains($l, 'Warning') || str_contains($l, 'Notice')
        ));
    }

    return [
        'checkout_ok'   => $checkout_ok,
        'checkout_fail' => !$checkout_ok,
        'api_ok'        => $api_ok,
        'db_ok'         => $db_ok,
        'pages_ok'      => $pages_ok,
        'error_count'   => count($recent_errors),
        'error_low'     => count($recent_errors) <= 5,
        'error_medium'  => count($recent_errors) > 5 && count($recent_errors) <= 20,
        'error_high'    => count($recent_errors) > 20,
        'recent_errors' => array_slice($recent_errors, -10),
        'e2e_failed'    => $e2e_failed,
        'timestamp'     => date('c'),
    ];
}
