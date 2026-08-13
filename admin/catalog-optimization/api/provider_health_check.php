<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config_optimization.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

/** @return array{status:int,body:string} */
function cat_health_get(string $url, array $headers): array
{
    $handle = curl_init();
    $httpHeaders = [];
    foreach ($headers as $name => $value) {
        $httpHeaders[] = $name . ': ' . $value;
    }
    curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false,
    ]);
    $raw = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'body' => is_string($raw) ? $raw : ''];
}

/**
 * Testa cada provedor com uma chamada gratuita/quase-gratuita (listar
 * modelos ou info da chave), sem gerar texto real e sem gastar credito
 * de geracao.
 *
 * @return array{ok:bool,detail:string}
 */
function cat_health_probe(string $provider, string $key): array
{
    if ($key === '') {
        return ['ok' => false, 'detail' => 'Nenhuma chave configurada.'];
    }
    try {
        $response = match ($provider) {
            'openai' => cat_health_get('https://api.openai.com/v1/models', [
                'Authorization' => 'Bearer ' . $key,
            ]),
            'gemini' => cat_health_get('https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key), []),
            'claude' => cat_health_get('https://api.anthropic.com/v1/models', [
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ]),
            'openrouter' => cat_health_get('https://openrouter.ai/api/v1/key', [
                'Authorization' => 'Bearer ' . $key,
            ]),
            'groq' => cat_health_get('https://api.groq.com/openai/v1/models', [
                'Authorization' => 'Bearer ' . $key,
            ]),
            default => ['status' => 0, 'body' => ''],
        };
        $status = (int)$response['status'];
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'detail' => 'Chave valida.'];
        }
        $decoded = json_decode((string)$response['body'], true);
        $message = is_array($decoded) ? (string)($decoded['error']['message'] ?? $decoded['error'] ?? '') : '';
        $message = $message !== '' ? $message : 'HTTP ' . $status;
        return ['ok' => false, 'detail' => mb_substr($message, 0, 220, 'UTF-8')];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => 'Falha de rede: ' . mb_substr($e->getMessage(), 0, 180, 'UTF-8')];
    }
}

function cat_health_is_capacity_failure(string $message): bool
{
    $message = strtolower($message);
    foreach ([
        'no credits remaining', 'insufficient credits', 'insufficient_quota',
        'prepayment credits are depleted', 'credit balance', 'billing',
        'quota exceeded', 'quota_exceeded', 'resource_exhausted',
    ] as $marker) {
        if (str_contains($message, $marker)) {
            return true;
        }
    }
    return false;
}

/** @return array{message:string,updated_at:string}|null */
function cat_health_recent_capacity_failure(PDO $db, string $provider): ?array
{
    try {
        $stmt = $db->prepare(
            "SELECT LEFT(error_message, 220) AS message, updated_at
             FROM catalog_optimizations_staging
             WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
               AND error_message IS NOT NULL AND error_message <> ''
               AND provider_used = ?
             ORDER BY updated_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !cat_health_is_capacity_failure((string)($row['message'] ?? ''))) {
            return null;
        }
        return ['message' => (string)$row['message'], 'updated_at' => (string)$row['updated_at']];
    } catch (Throwable) {
        return null;
    }
}

$pools = [
    'openai' => CATALOG_AI_OPENAI_API_KEY,
    'gemini' => CATALOG_AI_GOOGLE_GEMINI_API_KEY,
    'claude' => CATALOG_AI_CLAUDE_API_KEY,
    'openrouter' => CATALOG_AI_OPENROUTER_API_KEY,
    'groq' => CATALOG_AI_GROQ_API_KEY,
];

$results = [];
$db = catalog_ai_db();
foreach ($pools as $provider => $keys) {
    $firstKey = (is_array($keys) && $keys !== []) ? (string)$keys[0] : '';
    $probe = cat_health_probe($provider, $firstKey);
    $capacityFailure = $db instanceof PDO ? cat_health_recent_capacity_failure($db, $provider) : null;
    if ($probe['ok'] && $capacityFailure !== null) {
        $probe = [
            'ok' => false,
            'detail' => 'Chave válida, mas a execução falhou por capacidade nas últimas 24h, em '
                . $capacityFailure['updated_at'] . ': ' . $capacityFailure['message'],
        ];
    }
    $results[$provider] = [
        'ok' => $probe['ok'],
        'detail' => $probe['detail'],
        'key_count' => is_array($keys) ? count($keys) : 0,
    ];
}

echo json_encode([
    'success' => true,
    'checked_at' => date(DATE_ATOM),
    'providers' => $results,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
