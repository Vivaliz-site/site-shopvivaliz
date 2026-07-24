<?php
/**
 * API: Liz - Assistente Virtual Inteligente
 * Endpoint: POST /api/liz-intelligent.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/secure-session.php';

/**
 * Carrega variaveis de ambiente locais sem sobrescrever variaveis ja
 * fornecidas pelo sistema/Apache. .env.local tem prioridade sobre .env.
 */
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($_GET['health'] ?? '') === '1') {
    $providerStatus = liz_provider_status();
    http_response_code(200);
    echo json_encode([
        'ok' => in_array(true, $providerStatus, true),
        'endpoint' => 'liz-intelligent',
        'providers' => $providerStatus,
        'version' => '2.1-intelligent',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$message = trim((string)($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message required'], JSON_UNESCAPED_UNICODE);
    exit;
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
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Nenhuma IA disponivel. Configure GEMINI_API_KEY, OPENAI_API_KEY ou ANTHROPIC_API_KEY.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
    $prompt = "Voce e Liz, assistente virtual inteligente da ShopVivaliz, uma loja online de produtos para casa.\n";
    $prompt .= "Responda em portugues do Brasil de forma concisa, amigavel, util e personalizada, em no maximo 3 linhas.\n";
    $prompt .= "Nao invente informacoes sobre pedidos. Para dados de pedido, oriente o cliente a informar o numero do pedido ou contatar o atendimento.\n\n";
    $prompt .= "DADOS DA LOJA:\n";
    $prompt .= "- Atendimento: atendimento@shopvivaliz.com.br | WhatsApp (37) 99937-4112\n";
    $prompt .= "- Loja 100% online, com entrega para todo o Brasil\n";
    $prompt .= "- Frete gratis acima de R$ 199\n";
    $prompt .= "- Devolucao em ate 7 dias\n";
    $prompt .= "- Cupom VOLTEI5: 5% OFF na primeira compra\n";

    if ($products !== []) {
        $prompt .= "\nPRODUTOS RELACIONADOS:\n";
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

function liz_normalized_history(array $history, string $assistantRole): array
{
    $messages = [];
    foreach (array_slice($history, -5) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $content = trim((string)($entry['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        $messages[] = [
            'role' => ($entry['role'] ?? '') === 'user' ? 'user' : $assistantRole,
            'content' => $content,
        ];
    }
    return $messages;
}

function liz_call_gemini(string $message, array $history, array $products, string $apiKey): ?string
{
    $contents = [];
    foreach (liz_normalized_history($history, 'model') as $entry) {
        $contents[] = [
            'role' => $entry['role'],
            'parts' => [['text' => $entry['content']]],
        ];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $model = liz_env('GEMINI_MODEL') ?: 'gemini-2.0-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'system_instruction' => ['parts' => [['text' => liz_system_prompt($products)]]],
        'contents' => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 250,
            'temperature' => 0.7,
            'topP' => 0.9,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response) || $response === '') {
        error_log("Liz Gemini falhou: HTTP {$httpCode}; cURL {$curlError}");
        return null;
    }

    $data = json_decode($response, true);
    $answer = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
}

function liz_call_gpt(string $message, array $history, array $products, string $apiKey): ?string
{
    $messages = [['role' => 'system', 'content' => liz_system_prompt($products)]];
    $messages = array_merge($messages, liz_normalized_history($history, 'assistant'));
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => liz_env('OPENAI_MODEL') ?: 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 250,
        'temperature' => 0.7,
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
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response) || $response === '') {
        error_log("Liz OpenAI falhou: HTTP {$httpCode}");
        return null;
    }

    $data = json_decode($response, true);
    $answer = $data['choices'][0]['message']['content'] ?? null;
    return is_string($answer) && trim($answer) !== '' ? trim($answer) : null;
}

function liz_call_claude(string $message, array $history, array $products, string $apiKey): ?string
{
    $messages = liz_normalized_history($history, 'assistant');
    $messages[] = ['role' => 'user', 'content' => $message];

    $payload = [
        'model' => liz_env('ANTHROPIC_MODEL') ?: 'claude-3-5-haiku-20241022',
        'max_tokens' => 250,
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
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response) || $response === '') {
        error_log("Liz Claude falhou: HTTP {$httpCode}");
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
        'answer' => 'Desculpe, a inteligencia da Liz esta temporariamente indisponivel. Fale com nosso atendimento: (37) 99937-4112.',
        'provider' => null,
    ];
}

$products = liz_search_products($message);
$result = liz_call_with_fallback($message, $history, $products, $providers);

http_response_code($result['success'] ? 200 : 503);
echo json_encode([
    'ok' => $result['success'],
    'answer' => $result['answer'],
    'provider' => $result['provider'],
    'products_found' => count($products),
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE);