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

/**
 * Testa cada provedor com uma chamada gratuita/quase-gratuita (listar
 * modelos ou info da chave), sem gerar conteudo real e sem gastar credito
 * de geracao. Serve para distinguir "chave invalida", "sem credito" e
 * "provedor operacional" antes de tentar uma edicao de imagem de verdade.
 *
 * @return array{ok:bool,detail:string}
 */
function ais_health_probe(string $provider, string $key): array
{
    if ($key === '') {
        return ['ok' => false, 'detail' => 'Nenhuma chave configurada.'];
    }
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
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'detail' => 'Chave valida.'];
        }
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string)($decoded['error']['message'] ?? $decoded['error'] ?? '') : '';
        $message = $message !== '' ? $message : 'HTTP ' . $status;
        return ['ok' => false, 'detail' => mb_substr($message, 0, 220, 'UTF-8')];
    } catch (Throwable $e) {
        return ['ok' => false, 'detail' => 'Falha de rede: ' . mb_substr($e->getMessage(), 0, 180, 'UTF-8')];
    }
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
    if ($providerPatterns === []) {
        return null;
    }

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
        if (!is_array($row)) {
            return null;
        }
        $exception = new AiStudioApiException((string)($row['message'] ?? ''));
        return ai_studio_is_capacity_failure($exception)
            ? ['message' => (string)$row['message'], 'updated_at' => (string)$row['updated_at']]
            : null;
    } catch (Throwable) {
        return null;
    }
}

$results = [];
$db = ai_studio_db();
foreach ([
    'openai' => ai_studio_secret_pool('AI_STUDIO_OPENAI_API_KEY', ['AI_STUDIO_OPENAI_API_KEY', 'OPENAI_API_KEY']),
    'google' => ai_studio_secret_pool('AI_STUDIO_GOOGLE_IMAGEN_API_KEY', ['AI_STUDIO_GOOGLE_IMAGEN_API_KEY', 'GOOGLE_IMAGEN_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY']),
    'claude' => ai_studio_secret_pool('AI_STUDIO_CLAUDE_API_KEY', ['AI_STUDIO_CLAUDE_API_KEY', 'CLAUDE_API_KEY', 'ANTHROPIC_API_KEY']),
    'openrouter' => ai_studio_secret_pool('AI_STUDIO_OPENROUTER_API_KEY', ['AI_STUDIO_OPENROUTER_API_KEY', 'OPENROUTER_API_KEY']),
    'groq' => ai_studio_secret_pool('AI_STUDIO_GROQ_API_KEY', ['AI_STUDIO_GROQ_API_KEY', 'GROQ_API_KEY']),
] as $provider => $keys) {
    $firstKey = $keys[0] ?? '';
    $probe = ais_health_probe($provider, $firstKey);
    $capacityFailure = $db instanceof PDO ? ais_health_recent_capacity_failure($db, $provider) : null;
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
        'key_count' => count($keys),
    ];
}

// Claude melhora o prompt, mas não gera a imagem final. Ele só fica apto para
// o fluxo de imagem quando ao menos um editor real está apto a executar.
if (($results['claude']['ok'] ?? false) === true) {
    $imageEditors = ['openai', 'google', 'openrouter'];
    $allBlocked = true;
    foreach ($imageEditors as $imageEditor) {
        if (($results[$imageEditor]['ok'] ?? false) === true) {
            $allBlocked = false;
            break;
        }
    }
    if ($allBlocked) {
        $results['claude']['ok'] = false;
        $results['claude']['detail'] = 'Chave válida para otimizar prompt, mas nenhum editor de imagem (OpenAI, Gemini ou OpenRouter) está apto para concluir a edição.';
    }
}

echo json_encode([
    'success' => true,
    'checked_at' => date(DATE_ATOM),
    'providers' => $results,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
