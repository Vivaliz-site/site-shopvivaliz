<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function sv_me_root(): string
{
    return dirname(__DIR__, 2);
}

function sv_me_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function sv_me_env_load(): void
{
    // config/constants.php carrega config/runtime-secrets.php, gerado pelo
    // deploy a partir dos GitHub Secrets (o servidor nao recebe .env via FTP).
    $constants = sv_me_root() . '/config/constants.php';
    if (is_file($constants)) {
        require_once $constants;
    }

    $path = sv_me_root() . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function sv_me_env(string $name): ?string
{
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    if (isset($_ENV[$name]) && is_string($_ENV[$name]) && trim($_ENV[$name]) !== '') {
        return trim($_ENV[$name]);
    }
    return null;
}

function sv_me_mask(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $len = strlen($value);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($value, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($value, -4);
}

function sv_me_request(string $url, array $payload, ?string $token): array
{
    if (!$token) {
        return array(
            'ok' => false,
            'status' => 503,
            'error' => 'missing_access_token',
            'message' => 'Configure MELHORENVIO_ACCESS_TOKEN ou SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN no servidor.'
        );
    }

    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'User-Agent: ShopVivaliz Dev Shipping Diagnostic',
    );
    $bodyJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $bodyJson,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
        }

        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status > 0 ? $status : 502,
            'curl_error' => $error ?: null,
            'body' => $decoded !== null ? $decoded : $body,
        );
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'ignore_errors' => true,
            'timeout' => 25,
            'header' => implode("\r\n", $headers),
            'content' => $bodyJson,
        ),
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
        ),
    ));
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? array() as $line) {
        if (preg_match('/\s(\d{3})\s/', $line, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }

    $decoded = null;
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
    }

    return array(
        'ok' => $status >= 200 && $status < 300,
        'status' => $status > 0 ? $status : 502,
        'curl_error' => $body === false ? 'stream_request_failed' : null,
        'body' => $decoded !== null ? $decoded : $body,
    );
}

sv_me_env_load();
require_once sv_me_root() . '/includes/melhorenvio-oauth.php';

$postalCode = preg_replace('/\D+/', '', (string)($_GET['cep'] ?? $_POST['cep'] ?? '35500025'));

$token = me_current_access_token()
    ?: sv_me_env('MELHORENVIO_ACCESS_TOKEN')
    ?: sv_me_env('SHOPVIVALIZ_MELHORENVIO_ACCESS_TOKEN')
    ?: sv_me_env('MELHORENVIO_API_KEY')
    ?: sv_me_env('SHOPVIVALIZ_MELHORENVIO_API_KEY');
$fromPostalCode = preg_replace('/\D+/', '', (string)(sv_me_env('MELHORENVIO_FROM_POSTAL_CODE') ?: sv_me_env('SHOPVIVALIZ_FROM_POSTAL_CODE') ?: '35501236'));

$payload = array(
    'from' => array('postal_code' => $fromPostalCode),
    'to' => array('postal_code' => $postalCode),
    'products' => array(
        array(
            'id' => 'diag-1',
            'width' => 35,
            'height' => 45,
            'length' => 34,
            'weight' => 3.5,
            'insurance_value' => 578.76,
            'quantity' => 1,
        )
    ),
    'options' => array(
        'receipt' => false,
        'own_hand' => false,
        'collect' => false,
    )
);

$url = me_api_base() . '/api/v2/me/shipment/calculate';
$result = sv_me_request($url, $payload, $token);

sv_me_json($result['status'], array(
    'ok' => $result['ok'],
    'agent' => 'melhorenvio_diagnostic',
    'generated_at' => date('c'),
    'cep' => $postalCode,
    'readiness' => $result['ok'] ? 'operational' : ($result['error'] ?? $result['curl_error'] ?? 'attention'),
    'token_detected' => (bool)$token,
    'token_mask' => sv_me_mask($token),
    'from_postal_code' => $fromPostalCode,
    'payload' => $payload,
    'result' => $result,
));
