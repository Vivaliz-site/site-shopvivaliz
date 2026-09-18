<?php
/**
 * Liz - respostas gerais com pesquisa web (Gemini Google Search grounding).
 * Não consulta catálogo nem executa ações da loja.
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lizg_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lizg_load_env(string $path): void
{
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || getenv($name) !== false) continue;
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
    }
}

$root = dirname(__DIR__);
lizg_load_env($root . '/.env.local');
lizg_load_env($root . '/.env');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lizg_reply(405, ['ok' => false, 'error' => 'Método não permitido.']);
}

// CUSTO/ABUSO: este endpoint e publico e cada chamada consome cota paga da
// API do Gemini. Sem limite, qualquer um podia usa-lo como proxy gratuito de
// LLM na conta da loja. api/liz-intelligent.php (o endpoint que o widget da
// vitrine realmente usa) ja tinha limite; estes dois nao tinham.
require_once dirname(__DIR__) . '/includes/rate-limiter.php';
require_once __DIR__ . '/liz-general-policy.php';
$lizgIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!RateLimiter::isAllowed('liz-general:' . $lizgIp, 10, 60)) {
    lizg_reply(429, ['ok' => false, 'error' => 'Muitas perguntas seguidas. Aguarde um minuto.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
$message = trim((string)($input['message'] ?? ''));
if ($message === '' || mb_strlen($message, 'UTF-8') > 2000) {
    lizg_reply(400, ['ok' => false, 'error' => 'Pergunta ausente ou muito longa.']);
}

$geminiKey = trim((string)(getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_GEMINI_API_KEY') ?: ''));
$openRouterKey = trim((string)(getenv('OPENROUTER_API_KEY') ?: ''));
if ($geminiKey === '' && $openRouterKey === '') {
    lizg_reply(503, ['ok' => false, 'error' => 'A pesquisa da Liz est? temporariamente indispon?vel.']);
}

$model = trim((string)(getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite'));
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
$system = <<<'TXT'
Voc? ? Liz, assistente virtual da ShopVivaliz. Tamb?m pode conversar de forma simp?tica sobre assuntos gerais, para tornar o atendimento mais humano.
Quando a pergunta n?o for sobre a loja, responda em portugu?s do Brasil, de forma breve, clara e correta.
Use a pesquisa Google fornecida pela API quando o assunto puder ter mudado, exigir informa??o atual ou quando a pergunta pedir pesquisa.
Para receitas, ci?ncia b?sica, curiosidades e conhecimento est?vel, responda diretamente.
N?o invente fatos. Em temas m?dicos, jur?dicos ou financeiros, d? apenas informa??o geral e recomende orienta??o profissional quando necess?rio.
N?o transforme toda resposta em oferta comercial e n?o force o retorno ao assunto da loja.
TXT;

$groundingRequested = lizg_needs_web_grounding($message);
$payload = [
    'system_instruction' => ['parts' => [['text' => $system]]],
    'contents' => [['role' => 'user', 'parts' => [['text' => $message]]]],
    'generationConfig' => ['maxOutputTokens' => 900, 'temperature' => 0.35],
];
if ($groundingRequested) {
    $payload['tools'] = [['google_search' => new stdClass()]];
}

function lizg_request(string $url, string $key, array $payload, int $timeoutSeconds = 18): array
{
    $ch = curl_init($url);
    if ($ch === false) return [0, ''];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(5, $timeoutSeconds),
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? $body : ''];
}

function lizg_openrouter_request(string $key, string $system, string $message): array
{
    $baseUrl = rtrim(trim((string)(getenv('OPENROUTER_API_BASE_URL') ?: 'https://openrouter.ai/api/v1')), '/');
    $model = trim((string)(getenv('OPENROUTER_TEXT_MODEL') ?: 'google/gemini-2.5-flash-lite'));
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
    $referer = trim((string)(getenv('OPENROUTER_HTTP_REFERER') ?: 'https://shopvivaliz.com.br'));
    $title = trim((string)(getenv('OPENROUTER_APP_TITLE') ?: 'ShopVivaliz'));
    if ($referer !== '') $headers[] = 'HTTP-Referer: ' . $referer;
    if ($title !== '') $headers[] = 'X-OpenRouter-Title: ' . $title;
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $message],
        ],
        'max_tokens' => 900,
        'temperature' => 0.35,
    ];
    $ch = curl_init($baseUrl . '/chat/completions');
    if ($ch === false) return [0, ''];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 18,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? $body : ''];
}

$status = 0;
$body = '';
$answer = '';
$provider = null;
$groundingUsed = false;

if ($geminiKey !== '') {
    if ($groundingRequested) {
        [$status, $body] = lizg_request($url, $geminiKey, $payload, 12);
        $groundingUsed = $status === 200;
        if (!$groundingUsed) {
            unset($payload['tools']);
            $payload['system_instruction']['parts'][0]['text'] .= "\nA pesquisa web n?o est? dispon?vel nesta execu??o. N?o diga que pesquisou; avise quando uma informa??o atual n?o puder ser confirmada.";
        }
    }

    if (!$groundingUsed) {
        for ($attempt = 1; $attempt <= lizg_plain_max_attempts(); $attempt++) {
            [$status, $body] = lizg_request($url, $geminiKey, $payload, 18);
            if ($status === 200 || !lizg_should_retry_plain($status)) {
                break;
            }
            if ($attempt < lizg_plain_max_attempts()) {
                usleep(250000 * $attempt);
            }
        }
    }

    $data = json_decode($body, true);
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $texts = [];
    foreach (is_array($parts) ? $parts : [] as $part) {
        if (is_array($part) && empty($part['thought']) && is_string($part['text'] ?? null)) {
            $texts[] = trim($part['text']);
        }
    }
    $answer = trim(implode("\n", array_filter($texts)));
    if ($status === 200 && $answer !== '') {
        $provider = 'gemini';
    } else {
        error_log('Liz Gemini request failed; HTTP ' . $status . ', model=' . $model . '. Trying OpenRouter if configured.');
        $answer = '';
    }
}

if ($answer === '' && $openRouterKey !== '') {
    [$routerStatus, $routerBody] = lizg_openrouter_request($openRouterKey, $system, $message);
    $routerData = json_decode($routerBody, true);
    $routerAnswer = $routerData['choices'][0]['message']['content'] ?? null;
    if ($routerStatus === 200 && is_string($routerAnswer) && trim($routerAnswer) !== '') {
        $answer = trim($routerAnswer);
        $provider = 'openrouter';
        $groundingUsed = false;
    } else {
        error_log('Liz OpenRouter request failed; HTTP ' . $routerStatus);
    }
}

if ($answer === '' || $provider === null) {
    lizg_reply(503, ['ok' => false, 'error' => 'N?o consegui pesquisar ou responder agora. Tente novamente em instantes.']);
}

lizg_reply(200, [
    'ok' => true,
    'answer' => $answer,
    'provider' => $provider,
    'web_grounding_requested' => $groundingUsed,
    'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format(DateTime::ATOM),
]);
