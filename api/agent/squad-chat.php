<?php
/**
 * ShopVivaliz — Squad Chat Endpoint
 * POST /api/agent/squad-chat.php
 * PHP 8.3 · Anthropic · OpenAI · Gemini
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function squad_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function squad_env_load(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

squad_env_load(dirname(__DIR__, 2) . '/.env');

$allowed_origins = [
    'https://dev.shopvivaliz.com.br',
    'https://shopvivaliz.com.br',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Squad-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    squad_json(405, ['error' => 'Method not allowed']);
}

$expected_token = getenv('SQUAD_TOKEN') ?: '';
$received_token = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';

if ($expected_token === '') {
    squad_json(503, ['error' => 'SQUAD_TOKEN not configured']);
}

if ($received_token === '' || !hash_equals($expected_token, $received_token)) {
    squad_json(401, ['error' => 'Unauthorized']);
}

$raw_body = file_get_contents('php://input') ?: '';
if (strlen($raw_body) > 50000) {
    squad_json(413, ['error' => 'Payload too large']);
}

$body = json_decode($raw_body, true);
if (!is_array($body) || empty($body['message'])) {
    squad_json(400, ['error' => 'Invalid input']);
}

$user_message = trim((string) $body['message']);
if ($user_message === '' || mb_strlen($user_message) > 8000) {
    squad_json(400, ['error' => 'Message is empty or too large']);
}

$requested_agents = $body['agents'] ?? ['director', 'claude', 'gpt', 'gemini'];
if (!is_array($requested_agents)) {
    $requested_agents = ['director', 'claude', 'gpt', 'gemini'];
}

$history = $body['history'] ?? [];
if (!is_array($history)) {
    $history = [];
}

$history = array_slice(array_filter($history, static function ($item): bool {
    if (!is_array($item)) {
        return false;
    }

    $role = $item['role'] ?? '';
    $content = (string) ($item['content'] ?? '');

    return in_array($role, ['user', 'assistant'], true)
        && $content !== ''
        && mb_strlen($content) <= 8000;
}), -8);

$cycle_id = 'cycle_' . date('YmdHis') . '_' . substr(hash('sha256', uniqid('', true)), 0, 8);

$ANTHROPIC_KEY = getenv('ANTHROPIC_API_KEY') ?: '';
$OPENAI_KEY = getenv('OPENAI_API_KEY') ?: '';
$GEMINI_KEY = getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: '');

$AGENT_CONFIGS = [
    'director' => [
        'name' => 'Diretor de Projetos',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você é o Diretor de Projetos do ShopVivaliz. Coordene o ciclo com foco em segurança, execução cumulativa e validação antes de qualquer mudança.
Regras: nunca exponha credenciais, nunca altere preços sem aprovação, nunca publique campanhas automaticamente, nunca apague dados sem aprovação.
Responda em português, com decisão objetiva e próximos passos.
SYS,
    ],
    'claude' => [
        'name' => 'Arquiteto (Claude)',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'system' => <<<'SYS'
Você é o Arquiteto de Software do ShopVivaliz. Foque em arquitetura PHP 8.3, organização, segurança, tratamento de erro, código cumulativo e compatibilidade com hospedagem compartilhada.
Não invente estrutura sem evidência. Responda em português.
SYS,
    ],
    'gpt' => [
        'name' => 'Integrador (GPT-4o)',
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'system' => <<<'SYS'
Você é o Integrador do ShopVivaliz. Foque em APIs, deploy, GitHub Actions, testes, diagnóstico prático, Olist, checkout, frete, Pix e boleto.
Proponha passos executáveis e seguros para ambiente dev antes de produção. Responda em português.
SYS,
    ],
    'gemini' => [
        'name' => 'Auditor (Gemini)',
        'provider' => 'gemini',
        'model' => 'gemini-1.5-flash',
        'system' => <<<'SYS'
Você é o Auditor Geral do ShopVivaliz. Atue como advogado do diabo. Revise riscos de segurança, LGPD, XSS, CSRF, exposição de credenciais, custos de API, SEO, documentação e qualidade visual.
Bloqueie soluções frágeis ou incompletas. Responda em português.
SYS,
    ],
];

function squad_required_key(string $provider, string $anthropic, string $openai, string $gemini): string
{
    return match ($provider) {
        'openai' => $openai,
        'gemini' => $gemini,
        default => $anthropic,
    };
}

function call_anthropic(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 900): string
{
    if ($api_key === '') {
        throw new RuntimeException('ANTHROPIC_API_KEY não configurada.');
    }

    $payload = json_encode([
        'model' => $model,
        'max_tokens' => $max_tokens,
        'system' => $system_prompt,
        'messages' => $messages,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error !== '') {
        throw new RuntimeException('Anthropic cURL error.');
    }

    if ($http_code < 200 || $http_code >= 300) {
        $decoded = json_decode((string) $response, true);
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
        throw new RuntimeException('Anthropic: ' . $msg);
    }

    $data = json_decode((string) $response, true);
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string) ($block['text'] ?? '');
        }
    }

    return trim($text) ?: 'Sem resposta.';
}

function call_openai(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 900): string
{
    if ($api_key === '') {
        throw new RuntimeException('OPENAI_API_KEY não configurada.');
    }

    $oai = [['role' => 'system', 'content' => $system_prompt]];
    foreach ($messages as $message) {
        $oai[] = ['role' => $message['role'], 'content' => $message['content']];
    }

    $payload = json_encode([
        'model' => $model,
        'messages' => $oai,
        'max_tokens' => $max_tokens,
        'temperature' => 0.2,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error !== '') {
        throw new RuntimeException('OpenAI cURL error.');
    }

    if ($http_code < 200 || $http_code >= 300) {
        $decoded = json_decode((string) $response, true);
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
        throw new RuntimeException('OpenAI: ' . $msg);
    }

    $data = json_decode((string) $response, true);
    return trim((string) ($data['choices'][0]['message']['content'] ?? '')) ?: 'Sem resposta.';
}

function call_gemini(string $api_key, string $system_prompt, string $model, array $messages, int $max_tokens = 900): string
{
    if ($api_key === '') {
        throw new RuntimeException('GEMINI_API_KEY/GOOGLE_API_KEY não configurada.');
    }

    $contents = [];
    foreach ($messages as $message) {
        $contents[] = [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $message['content']]],
        ];
    }

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $system_prompt]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => $max_tokens, 'temperature' => 0.2],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $api_key,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error !== '') {
        throw new RuntimeException('Gemini cURL error.');
    }

    if ($http_code < 200 || $http_code >= 300) {
        $decoded = json_decode((string) $response, true);
        $msg = $decoded['error']['message'] ?? ('HTTP ' . $http_code);
        throw new RuntimeException('Gemini: ' . $msg);
    }

    $data = json_decode((string) $response, true);
    return trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '')) ?: 'Sem resposta.';
}

$valid_order = ['director', 'claude', 'gpt', 'gemini'];
$agents_to_run = array_values(array_filter(
    $valid_order,
    static fn(string $id): bool => in_array($id, $requested_agents, true) && isset($AGENT_CONFIGS[$id])
));

if ($agents_to_run === []) {
    squad_json(400, ['error' => 'No valid agents selected']);
}

$responses = [];

foreach ($agents_to_run as $agent_id) {
    $cfg = $AGENT_CONFIGS[$agent_id];
    $context = $user_message;

    if ($responses !== []) {
        $context .= "\n\n--- Respostas anteriores dos agentes ---";
        foreach ($responses as $response) {
            $context .= "\n\n[" . $response['name'] . "]:\n" . $response['text'];
        }
    }

    $messages = [];
    foreach ($history as $item) {
        $messages[] = ['role' => $item['role'], 'content' => (string) $item['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $context];

    try {
        $provider = (string) $cfg['provider'];
        $api_key = squad_required_key($provider, $ANTHROPIC_KEY, $OPENAI_KEY, $GEMINI_KEY);

        if ($provider === 'openai') {
            $text = call_openai($api_key, $cfg['system'], $cfg['model'], $messages);
        } elseif ($provider === 'gemini') {
            $text = call_gemini($api_key, $cfg['system'], $cfg['model'], $messages);
        } else {
            $text = call_anthropic($api_key, $cfg['system'], $cfg['model'], $messages);
        }

        $responses[] = [
            'agent' => $agent_id,
            'name' => $cfg['name'],
            'provider' => $provider,
            'model' => $cfg['model'],
            'text' => $text,
            'ok' => true,
        ];
    } catch (RuntimeException $e) {
        $responses[] = [
            'agent' => $agent_id,
            'name' => $cfg['name'],
            'provider' => $cfg['provider'],
            'model' => $cfg['model'],
            'text' => 'Erro: ' . $e->getMessage(),
            'ok' => false,
        ];
    }
}

$log_dir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

@file_put_contents(
    $log_dir . '/chat.log',
    json_encode([
        'cycle_id' => $cycle_id,
        'at' => date('c'),
        'agents' => array_column($responses, 'agent'),
        'providers' => array_column($responses, 'provider'),
        'msg_len' => mb_strlen($user_message),
        'ok_count' => count(array_filter($responses, static fn(array $r): bool => $r['ok'] === true)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX
);

echo json_encode([
    'cycle_id' => $cycle_id,
    'responses' => $responses,
    'at' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
