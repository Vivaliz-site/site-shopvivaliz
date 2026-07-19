<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svi_base_url(): string
{
    $env = getenv('BASE_URL');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br';
    return $scheme . '://' . $host;
}

function svi_fetch_json(string $url, int $timeout = 45): array
{
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: ShopVivalizAutoRoutines/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\s(\d{3})\s/', $line, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
        $json = is_string($body) ? json_decode($body, true) : null;
        return [
            'status' => $status,
            'error' => $body === false ? 'stream_request_failed' : '',
            'json' => is_array($json) ? $json : null,
            'raw' => is_string($body) ? $body : '',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: ShopVivalizAutoRoutines/1.0',
        ],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $json = is_string($body) ? json_decode($body, true) : null;
    return [
        'status' => $status,
        'error' => $error,
        'json' => is_array($json) ? $json : null,
        'raw' => is_string($body) ? $body : '',
    ];
}

function svi_run_sync_cli(int $expected, int $limit): array
{
    // /olist/* e bloqueado no perimetro de seguranca (deploy/apache/shopvivaliz-private-paths.conf),
    // entao chama-lo via HTTP (como os outros diagnosticos abaixo) sempre retorna 403 e
    // olist_sync fica null -- ver docs/MEMORIA-AGENTES.md. Roda via CLI local, que nao
    // passa pelo Apache/htaccess.
    $script = escapeshellarg(dirname(__DIR__) . '/olist/sync-products.php');
    $cmd = 'SVS_DRY_RUN=1 php ' . $script . ' 2>&1';
    $output = shell_exec($cmd);
    $json = is_string($output) ? json_decode($output, true) : null;
    return [
        'status' => is_array($json) ? 200 : 0,
        'error' => is_array($json) ? '' : 'cli_invocation_failed',
        'json' => is_array($json) ? $json : null,
        'raw' => is_string($output) ? $output : '',
    ];
}

$expected = max(1, (int)($_GET['expected'] ?? 200));
$limit = max(1, min(250, (int)($_GET['limit'] ?? 50)));
$baseUrl = svi_base_url();

$update = svi_fetch_json($baseUrl . '/installer/update-applied-check.php');
$sync = svi_run_sync_cli($expected, $limit);
$melhorEnvio = svi_fetch_json($baseUrl . '/api/melhorenvio/diagnostic.php?cep=35500025');
$pagarme = svi_fetch_json($baseUrl . '/api/pagarme/diagnostic.php');
$beforeCount = (int)($sync['json']['before_count'] ?? 0);
$afterCount = (int)($sync['json']['after_count'] ?? 0);
$baselineExpected = $beforeCount > 0 ? min($expected, $beforeCount) : $expected;

$checks = [
    'Produto com botao Comprar agora' => (bool)($update['json']['checks']['Produto com botao Comprar agora'] ?? false),
    'Produto com campo CEP' => (bool)($update['json']['checks']['Produto com campo CEP'] ?? false),
    'Checkout com PIX' => (bool)($update['json']['checks']['Checkout com PIX'] ?? false),
    'Checkout com boleto' => (bool)($update['json']['checks']['Checkout com boleto'] ?? false),
    'Diagnostico Melhor Envio presente' => (bool)($update['json']['checks']['Diagnostico Melhor Envio presente'] ?? false),
    'Diagnostico Pagar.me presente' => (bool)($update['json']['checks']['Diagnostico Pagar.me presente'] ?? false),
    'OAuth Olist solicita offline_access' => (bool)($sync['json']['oauth']['has_offline_access'] ?? false),
    'OAuth Olist solicita prompt consent' => (bool)($sync['json']['oauth']['has_prompt_consent'] ?? false),
    'Olist/Tiny sincronizacao automatica sem erro operacional' => (bool)($sync['json']['operational'] ?? false),
    'Olist/Tiny produtos esperados' => $afterCount >= $baselineExpected,
    'Melhor Envio pronto para cotacao' => (bool)($melhorEnvio['json']['ok'] ?? false),
    'Pagar.me pronto para autenticacao' => (bool)($pagarme['json']['ok'] ?? false),
];

$ok = !in_array(false, $checks, true);
http_response_code($ok ? 200 : 207);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'version' => $update['json']['version'] ?? null,
    'expected' => $expected,
    'baseline_expected' => $baselineExpected,
    'limit' => $limit,
    'checks' => $checks,
    'olist_sync' => $sync['json'],
    'melhorenvio' => $melhorEnvio['json'],
    'pagarme' => $pagarme['json'],
    'update_applied' => $update['json'],
    'generated_at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
