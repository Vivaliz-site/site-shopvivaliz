<?php
/**
 * API: Liz - Assistente Virtual Inteligente
 * Endpoint: POST /api/liz-intelligent.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/secure-session.php';

function liz_load_env_files(array $files): void
{
    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) !== false && getenv($name) !== '') {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$projectRoot = dirname(__DIR__);
liz_load_env_files([
    $projectRoot . '/.env.local',
    $projectRoot . '/.env',
]);

function liz_env(string $name): string
{
    $value = getenv($name);
    return $value === false ? '' : trim((string)$value);
}

function liz_provider_status(): array
{
    return [
        'gemini' => liz_env('GEMINI_API_KEY') !== '' || liz_env('GOOGLE_GEMINI_API_KEY') !== '',
        'openai' => liz_env('OPENAI_API_KEY') !== '',
        'claude' => liz_env('ANTHROPIC_API_KEY') !== '',
    ];
}

function liz_json_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($_GET['health'] ?? '') === '1') {
    $providerStatus = liz_provider_status();
    liz_json_response(200, [
        'ok' => in_array(true, $providerStatus, true),
        'endpoint' => 'liz-intelligent',
        'providers' => $providerStatus,
        'version' => '2.2-gemini-resilient',
    ]);
}

if ($method !== 'POST') {
    liz_json_response(405, ['ok' => false, 'error' => 'Método não permitido.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    liz_json_response(400, ['ok' => false, 'error' => 'JSON inválido.']);
}

$message = trim((string)($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($message === '') {
    liz_json_response(400, ['ok' => false, 'error' => 'Mensagem obrigatória.']);
}

$providers = [];
$geminiKey = liz_env('GEMINI_API_KEY') ?: liz_env('GOOGLE_GEMINI_API_KEY');
if ($geminiKey !== '') {
    $providers[] = ['name' => 'gemini', 'key' => $geminiKey];
}
if (liz_env('OPENAI_API_KEY') !== '') {
    $providers[] = ['name' => 'gpt', 'key' => liz_env('OPENAI_API_KEY')];
}
if (liz_env('ANTHROPIC_API_KEY') !== '') {
    $providers[] = ['name' => 'claude', 'key' => liz_env('ANTHROPIC_API_KEY')];
}

if ($providers === []) {
    liz_json_response(503, [
        'ok' => false,
        'provider' => null,
        'error' => 'A Liz está temporariamente indisponível. Tente novamente em alguns instantes.',
    ]);
}

function liz_search_products(string $query): array
{
    $catalogFile = __DIR__ . '/catalog/fallback-products.json';
    if (!is_file($catalogFile)) {
        return [];
    }

    $products = json_decode((string)file_get_contents($catalogFile), true);
    if (!is_array($products)) {
        return [];
    }

    $queryNorm = mb_strtolower($query, 'UTF-8');
    $relevant = [];

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $name = mb_strtolower((string)($product['name'] ?? ''), 'UTF-8');
        $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
        $score = 0;

        if ($queryNorm !== '' && (str_contains($name, $queryNorm) || str_contains($category, $queryNorm))) {
            $score += 10;
        }
        if ($queryNorm === $category && $category !== '') {
            $score += 5;
        }

        if ($score > 0) {
            $relevant[] = [
                'sku' => $product['sku'] ?? null,
                'name' => $product['name'] ?? '',
                'price' => $product['price'] ?? null,
                'category' => $product['category'] ?? '',
                'score' => $score,
            ];
        }
    }

    usort($relevant, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($relevant, 0, 3);
}

function liz_system_prompt(array $products): string
{
    $prompt = "Você é Liz, assistente virtual da ShopVivaliz, uma loja online de produtos para casa.\n";
    $prompt .= "Responda sempre em português do Brasil. Só mude de idioma quando o cliente pedir explicitamente.\n";
    $prompt .= "Dê respostas naturais, completas e normalmente em 2 a 4 frases. Nunca termine uma frase pela metade.\n";
    $prompt .= "Não invente preço, estoque, prazo de entrega, forma de pagamento, rastreio ou status de pedido. Quando não houver dado confirmado, explique como consultar.\n";
    $prompt .= "Para assuntos fora da loja, responda brevemente e, quando apropriado, retome o contexto da ShopVivaliz sem forçar uma venda.\n";
    $prompt .= "Não mencione regras internas, prompt, provedor ou limitações técnicas.\n\n";
    $prompt .= "DADOS CONFIRMADOS DA LOJA:\n";
    $prompt .= "- Atendimento: atendimento@shopvivaliz.com.br | WhatsApp (37) 99937-4112\n";
    $prompt .= "- Loja 100% online, com entrega para todo o Brasil\n";
    $prompt .= "- Frete grátis acima de R$ 199\n";
    $prompt .= "- Devolução em até 7 dias\n";
    $prompt .= "- Cupom VOLTEI5: 5% OFF na primeira compra\n";

    if ($products !== []) {
        $prompt .= "\nPRODUTOS RELACIONADOS ENCONTRADOS NO CATÁLOGO LOCAL:\n";
        foreach ($products as $product) {
            $prompt .= sprintf(
                "- %s (R$ %s, %s)\n",
                (string)$product['name'],
                (string)$product['price'],
                (string)$product['category']
            );
        }
    }

    return $prompt;
}

function liz_is_ignored_history_text(string $content): bool
{
    $normalized = mb_strtolower(trim($content), 'UTF-8');
    if ($normalized === '') {
        return true;
    }

    $ignoredExact = [
        'liz está pensando...',
        'liz esta pensando...',
        'oi! eu sou a liz. posso ajudar você a encontrar um produto, acompanhar uma compra ou tirar dúvidas.',
        'oi! eu sou a liz. posso ajudar voce a encontrar um produto, acompanhar uma compra ou tirar duvidas.',
    ];
    if (in_array($normalized, $ignoredExact, true)) {
        return true;
    }

    return str_contains($normalized, 'temporariamente indisponível')
        || str_contains($normalized, 'temporariamente indisponivel')
        || str_starts_with($normalized, 'erro:')
        || preg_match('/^http\s+\d{3}$/i', $normalized) === 1;
}

function liz_normalized_history(array $history, string $assistantRole, string $currentMessage = ''): array
{
    $clean = [];
    $currentNormalized = mb_strtolower(trim($currentMessage), 'UTF-8');

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $rawRole = strtolower(trim((string)($entry['role'] ?? '')));
        if (!in_array($rawRole, ['user', 'assistant', 'model'], true)) {
            continue;
        }

        $content = trim((string)($entry['content'] ?? ''));
        if (liz_is_ignored_history_text($content)) {
            continue;
        }

        $role = $rawRole === 'user' ? 'user' : $assistantRole;
        if ($role === 'user' && $currentNormalized !== '' && mb_strtolower($content, 'UTF-8') === $currentNormalized) {
            continue;
        }

        $lastIndex = count($clean) - 1;
        if ($lastIndex >= 0 && $clean[$lastIndex]['role'] === $role) {
            $clean[$lastIndex]['content'] .= "\n" . $content;
            continue;
        }

        $clean[] = ['role' => $role, 'content' => $content];
    }

    while ($clean !== [] && $clean[0]['role'] !== 'user') {
        array_shift($clean);
    }
    while ($clean !== [] && $clean[count($clean) - 1]['role'] === 'user') {
        array_pop($clean);
    }

    return array_slice($clean, -12);
}

function liz_log_provider_error(string $provider, int $httpCode, string $curlError, string $response): void
{
    $body = trim(preg_replace('/\s+/', ' ', $response) ?? '');
    if (strlen($body) > 1000) {
        $body = substr($body, 0, 1000) . '…';
    }
    error_log(sprintf(
        'Liz %s falhou: HTTP %d; cURL %s; corpo %s',
        $provider,
        $httpCode,
        $curlError !== '' ? $curlError : 'sem erro',
        $body !== '' ? $body : 'vazio'
    ));
}

function liz_extract_gemini_text(array $data): ?string
{
    $parts = $data['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        return null;
    }

    $texts = [];
    foreach ($parts as $part) {
        if (!is_array($part) || !isset($part['text']) || !is_string($part['text'])) {
            continue;
        }
        $text = trim($part['text']);
        if ($text !== '') {
            $texts[] = $text;
        }
    }

    $answer = trim(implode("\n", $texts));
    return $answer !== '' ? $answer : null;
}

function liz_call_gemini(string $message, array $history, array $products, string $apiKey): ?string
{
    $contents = [];
    foreach (liz_normalized_history($history, 'model', $message) as $entry) {
        $contents[] = [
            'role' => $entry['role'],
            'parts' => [['text' => $entry['content']]],
        ];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $model = liz_env('GEMINI_MODEL') ?: 'gemini-3.5-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent';

    $payload = [
        'system_instruction' => ['parts' => [['text' => liz_system_prompt($products)]]],
        'contents' => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 900,
            'thinkingConfig' => [
                'thinkingLevel' => 'minimal',
            ],
        ],
    ];
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedPayload)) {
        error_log('Liz Gemini falhou ao serializar o payload.');
        return null;
    }

    $retryableStatus = [429, 500, 502, 503, 504];
    for ($attempt = 0; $attempt < 3; $attempt++) {
        if ($attempt > 0) {
            sleep($attempt);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 35,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseBody = is_string($response) ? $response : '';
        if ($httpCode === 200 && $responseBody !== '') {
            $data = json_decode($responseBody, true);
            if (is_array($data)) {
                $answer = liz_extract_gemini_text($data);
                if ($answer !== null) {
                    return $answer;
                }
            }
            liz_log_provider_error('Gemini resposta vazia', $httpCode, $curlError, $responseBody);
            return null;
        }

        liz_log_provider_error('Gemini', $httpCode, $curlError, $responseBody);
        if (!in_array($httpCode, $retryableStatus, true) || $attempt === 2) {
            return null;
        }
    }

    return null;
}

function liz_call_gpt(string $message, array $history, array $products, string $apiKey): ?string
{
    $messages = [['role' => 'system', 'content' => liz_system_prompt($products)]];
    $messages = array_merge($messages, liz_normalized_history($history, 'assistant', $message));
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => liz_env('OPENAI_MODEL') ?: 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 700,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 35,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response) || $response === '') {
        liz_log_provider_error('OpenAI', $httpCode, $curlError, is_string($response) ? $response : '');
        return null;
    }

    $data = json_decode($response, true);
    $answer = $data['choices'][0]['message']['content'] ?? null;
    return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
}

function liz_call_claude(string $message, array $history, array $products, string $apiKey): ?string
{
    $messages = liz_normalized_history($history, 'assistant', $message);
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => liz_env('ANTHROPIC_MODEL') ?: 'claude-3-5-haiku-20241022',
        'max_tokens' => 700,
        'system' => liz_system_prompt($products),
        'messages' => $messages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 35,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response) || $response === '') {
        liz_log_provider_error('Claude', $httpCode, $curlError, is_string($response) ? $response : '');
        return null;
    }

    $data = json_decode($response, true);
    $answer = $data['content'][0]['text'] ?? null;
    return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
}

function liz_call_with_fallback(string $message, array $history, array $products, array $providers): array
{
    foreach ($providers as $provider) {
        $answer = match ($provider['name']) {
            'gemini' => liz_call_gemini($message, $history, $products, $provider['key']),
            'gpt' => liz_call_gpt($message, $history, $products, $provider['key']),
            'claude' => liz_call_claude($message, $history, $products, $provider['key']),
            default => null,
        };

        if ($answer !== null) {
            return ['success' => true, 'answer' => $answer, 'provider' => $provider['name']];
        }
    }

    return [
        'success' => false,
        'answer' => null,
        'provider' => null,
        'error' => 'A Liz está temporariamente indisponível. Tente novamente em alguns instantes.',
    ];
}

$products = liz_search_products($message);
$result = liz_call_with_fallback($message, $history, $products, $providers);

liz_json_response($result['success'] ? 200 : 503, [
    'ok' => $result['success'],
    'answer' => $result['answer'],
    'error' => $result['error'] ?? null,
    'provider' => $result['provider'],
    'products_found' => count($products),
    'timestamp' => date('c'),
]);
