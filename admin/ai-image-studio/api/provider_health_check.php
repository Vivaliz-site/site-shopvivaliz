<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/AiServices.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

function ais_health_sanitize(string $message): string
{
    $message = trim($message);
    if ($message === '') return '';
    $patterns = [
        '/(?:sk-(?:proj-)?|AIza|ant-api)[A-Za-z0-9_\-]{8,}/i' => '[redacted]',
        '/Bearer\s+[A-Za-z0-9._~+\-\/=]+/i' => 'Bearer [redacted]',
        '/([?&](?:key|api_key|token|access_token|refresh_token)=)[^&\s]+/i' => '$1[redacted]',
        '/((?:api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|secret)\s*[:=]\s*["\']?)[^\s"\'&,;]+/i' => '$1[redacted]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $message = preg_replace($pattern, $replacement, $message) ?? $message;
    }
    $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
    return mb_substr(trim($message), 0, 220, 'UTF-8');
}

/** @return array{ok:bool,detail:string} */
function ais_health_probe(string $provider, string $key): array
{
    if ($key === '') return ['ok' => false, 'detail' => 'Nenhuma chave configurada.'];
    try {
        $response = match ($provider) {
            'openai' => AiStudioHttpClient::request('GET', 'https://api.openai.com/v1/models', ['Authorization' => 'Bearer ' . $key], null, 12),
            'google' => AiStudioHttpClient::request('GET', 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key), [], null, 12),
            'claude' => AiStudioHttpClient::request('GET', 'https://api.anthropic.com/v1/models', ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'], null, 12),
            'openrouter' => AiStudioHttpClient::request('GET', 'https://openrouter.ai/api/v1/key', ['Authorization' => 'Bearer ' . $key], null, 12),
            'groq' => AiStudioHttpClient::request('GET', 'https://api.groq.com/openai/v1/models', ['Authorization' => 'Bearer ' . $key], null, 12),
            default => ['status' => 0, 'body' => ''],
        };
        $status = (int)$response['status'];
        $body = (string)$response['body'];
        if ($status >= 200 && $status < 300) return ['ok' => true, 'detail' => 'Autenticação válida.'];
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string)($decoded['error']['message'] ?? $decoded['error'] ?? '') : '';
        if ($message === '') $message = 'HTTP ' . $status;
        return ['ok' => false, 'detail' => ais_health_sanitize($message)];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => 'Falha de rede: ' . ais_health_sanitize($e->getMessage())];
    }
}

/** @param list<string> $keys @return array{ok:bool,detail:string,working_keys:int,checked_keys:int} */
function ais_health_probe_pool(string $provider, array $keys): array
{
    if ($keys === []) return ['ok' => false, 'detail' => 'Nenhuma chave configurada.', 'working_keys' => 0, 'checked_keys' => 0];
    $errors = [];
    $checked = 0;
    foreach ($keys as $key) {
        $checked++;
        $probe = ais_health_probe($provider, (string)$key);
        if ($probe['ok']) {
            return [
                'ok' => true,
                'detail' => $checked === 1 ? 'Chave principal autenticou corretamente.' : "Uma chave de fallback autenticou após {$checked} tentativa(s).",
                'working_keys' => 1,
                'checked_keys' => $checked,
            ];
        }
        if ($probe['detail'] !== '') $errors[] = $probe['detail'];
    }
    $detail = $errors !== [] ? implode(' | ', array_slice(array_values(array_unique($errors)), 0, 2)) : 'Nenhuma chave autenticou.';
    return ['ok' => false, 'detail' => ais_health_sanitize($detail), 'working_keys' => 0, 'checked_keys' => $checked];
}

/** @return array{message:string,updated_at:string}|null */
function ais_health_recent_capacity_failure(PDO $db, string $provider): ?array
{
    $provider = ai_studio_normalize_provider($provider);
    $providerPatterns = match ($provider) {
        'openai' => ['openai', '%+openai'],
        'google' => ['google', '%+google'],
        'openrouter' => ['openrouter', '%+openrouter'],
        default => [],
    };
    if ($providerPatterns === []) return null;
    try {
        $stmt = $db->prepare(
            "SELECT LEFT(error_message, 220) AS message, updated_at
             FROM product_images_staging
             WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
               AND error_message IS NOT NULL AND error_message <> ''
               AND (provider_used = ? OR provider_used LIKE ?)
             ORDER BY updated_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute($providerPatterns);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $exception = new AiStudioApiException((string)($row['message'] ?? ''));
        return ai_studio_is_capacity_failure($exception)
            ? ['message' => ais_health_sanitize((string)$row['message']), 'updated_at' => (string)$row['updated_at']]
            : null;
    } catch (Throwable) {
        return null;
    }
}

$pools = [
    'openai' => ai_studio_secret_pool('AI_STUDIO_OPENAI_API_KEY', ['AI_STUDIO_OPENAI_API_KEY', 'OPENAI_API_KEY']),
    'google' => ai_studio_secret_pool('AI_STUDIO_GOOGLE_IMAGEN_API_KEY', ['AI_STUDIO_GOOGLE_IMAGEN_API_KEY', 'GOOGLE_IMAGEN_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY']),
    'claude' => ai_studio_secret_pool('AI_STUDIO_CLAUDE_API_KEY', ['AI_STUDIO_CLAUDE_API_KEY', 'CLAUDE_API_KEY', 'ANTHROPIC_API_KEY']),
    'openrouter' => ai_studio_secret_pool('AI_STUDIO_OPENROUTER_API_KEY', ['AI_STUDIO_OPENROUTER_API_KEY', 'OPENROUTER_API_KEY']),
    'groq' => ai_studio_secret_pool('AI_STUDIO_GROQ_API_KEY', ['AI_STUDIO_GROQ_API_KEY', 'GROQ_API_KEY']),
];
$results = [];
$db = ai_studio_db();
foreach ($pools as $provider => $keys) {
    $probe = ais_health_probe_pool($provider, $keys);
    $capacityFailure = $db instanceof PDO ? ais_health_recent_capacity_failure($db, $provider) : null;
    if ($probe['ok'] && $capacityFailure !== null) {
        $probe['ok'] = false;
        $probe['detail'] = 'Autenticação válida, mas houve falha recente de capacidade/crédito em '
            . $capacityFailure['updated_at'] . ': ' . $capacityFailure['message'];
    }
    $results[$provider] = [
        'ok' => $probe['ok'],
        'detail' => ais_health_sanitize($probe['detail']),
        'key_count' => count($keys),
        'working_key_count' => (int)$probe['working_keys'],
        'checked_key_count' => (int)$probe['checked_keys'],
    ];
}

$imageEditors = ['openai', 'google', 'openrouter'];
$hasEditor = false;
foreach ($imageEditors as $imageEditor) {
    if (($results[$imageEditor]['ok'] ?? false) === true) {
        $hasEditor = true;
        break;
    }
}
foreach (['claude', 'groq'] as $optimizer) {
    if (($results[$optimizer]['ok'] ?? false) === true && !$hasEditor) {
        $results[$optimizer]['ok'] = false;
        $results[$optimizer]['detail'] = 'Autenticação válida para otimizar prompt, mas nenhum editor de imagem (OpenAI, Gemini ou OpenRouter) está apto para concluir os pixels.';
    }
}

$readyProviders = array_values(array_keys(array_filter($results, static fn(array $row): bool => !empty($row['ok']))));
echo json_encode([
    'success' => true,
    'checked_at' => date(DATE_ATOM),
    'providers' => $results,
    'ready_providers' => $readyProviders,
    'has_visual_editor' => $hasEditor,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
