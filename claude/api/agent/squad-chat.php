<?php
/**
 * ShopVivaliz — Squad Chat Endpoint
 * POST api/agent/squad-chat.php
 * GET  api/agent/squad-chat.php?health=1
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function squad_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function squad_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
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
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function squad_rate_limit(string $token): void
{
    $root = dirname(__DIR__, 3);
    $dir = $root . '/logs/squad/rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $limit = (int) (getenv('SQUAD_RATE_LIMIT_PER_MINUTE') ?: 12);
    if ($limit < 1 || $limit > 120) {
        $limit = 12;
    }

    $key = substr(hash('sha256', $token . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 24);
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $bucket = ['minute' => (int) floor($now / 60), 'count' => 0];

    if (is_file($file)) {
        $saved = json_decode((string) file_get_contents($file), true);
        if (is_array($saved) && ($saved['minute'] ?? 0) === (int) floor($now / 60)) {
            $bucket = $saved;
        }
    }

    $bucket['count']++;
    if ($bucket['count'] > $limit) {
        squad_json(429, ['error' => 'Rate limit exceeded']);
    }

    file_put_contents($file, json_encode($bucket), LOCK_EX);
}

function squad_curl_json(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = (string) curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('cURL error: ' . $curlError);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $errData = json_decode($body, true);
        $errMsg = '';
        if (is_array($errData)) {
            $errMsg = (string) ($errData['error']['message']
                ?? $errData['error']['code']
                ?? $errData['error']
                ?? $errData['message']
                ?? '');
        }
        if ($errMsg === '') {
            $errMsg = 'HTTP ' . $httpCode;
        }
        throw new RuntimeException($errMsg);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON response');
    }
    return $data;
}

$root = dirname(__DIR__, 3);
$envPath = $root . '/.env';
squad_env_load($envPath);

$allowed_origins = [
    'https://shopvivaliz.com.br',
    'https://www.shopvivaliz.com.br',
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Squad-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
$openaiKey = getenv('OPENAI_API_KEY') ?: '';
$geminiKey = getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: '');
$anthropicModel = getenv('SQUAD_ANTHROPIC_MODEL') ?: 'claude-haiku-4-5-20251001';
$openaiModel = getenv('SQUAD_OPENAI_MODEL') ?: 'gpt-4o-mini';
$geminiModel = getenv('AI_SQUAD_GEMINI_MODEL') ?: getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-2.5-flash';
$maxTokens = (int) (getenv('SQUAD_MAX_TOKENS') ?: 900);
if ($maxTokens < 100 || $maxTokens > 4000) {
    $maxTokens = 900;
}
$claudeBridgeUrl = getenv('AI_SQUAD_CLAUDE_BRIDGE_URL') ?: 'http://127.0.0.1:17657';
// Use bridge when API key absent or AI_SQUAD_USE_BRIDGE=1
$useBridge = ($anthropicKey === '' || getenv('AI_SQUAD_USE_BRIDGE') === '1');

if (($_GET['health'] ?? '') === '1') {
    squad_json(200, [
        'ok' => true,
        'endpoint' => 'squad-chat',
        'version' => 'squad-chat-dialogue-mode-20260626',
        'token_required_for_post' => true,
        'env_loaded' => is_file(dirname(__DIR__, 3) . '/.env'),
        'providers' => [
            'anthropic' => ['configured' => $anthropicKey !== '' || $useBridge, 'model' => $anthropicModel, 'via_bridge' => $useBridge],
            'openai' => ['configured' => $openaiKey !== '', 'model' => $openaiModel],
            'gemini' => ['configured' => $geminiKey !== '', 'model' => $geminiModel],
        ],
        'agents' => ['director', 'claude', 'gpt', 'gemini', 'roo_director', 'roo_claude', 'roo_gpt', 'roo_gemini'],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    squad_json(405, ['error' => 'Method not allowed', 'hint' => 'Use POST or ?health=1']);
}

$expectedToken = getenv('SQUAD_TOKEN') ?: '';
$receivedToken = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? '';
if ($expectedToken === '') {
    squad_json(503, ['error' => 'SQUAD_TOKEN not configured']);
}
if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    squad_json(401, ['error' => 'Unauthorized']);
}

squad_rate_limit($expectedToken);

$rawBody = file_get_contents('php://input') ?: '';
if (strlen($rawBody) > 500000) {
    squad_json(413, ['error' => 'Payload too large']);
}
$body = json_decode($rawBody, true);
if (!is_array($body) || empty($body['message'])) {
    squad_json(400, ['error' => 'message is required']);
}

$userMessage = (string) ($body['message'] ?? '');
if (squad_len($userMessage) > 8000) {
    squad_json(400, ['error' => 'message too long (max 8000 chars)']);
}

$agentFilter = isset($body['agent']) ? strtolower(trim((string) $body['agent'])) : '';
$historyRaw = $body['history'] ?? [];
$history = [];
if (is_array($historyRaw)) {
    foreach (array_slice($historyRaw, -20) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = ($entry['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = (string) ($entry['content'] ?? '');
        if ($content !== '') {
            $history[] = ['role' => $role, 'content' => $content];
        }
    }
}

$messages = $history;
$messages[] = ['role' => 'user', 'content' => $userMessage];

$shopContext = '';
$contextObj = $body['context'] ?? null;
if (is_array($contextObj)) {
    $parts = [];
    if (!empty($contextObj['page'])) {
        $parts[] = 'Página atual: ' . (string) $contextObj['page'];
    }
    if (!empty($contextObj['cart'])) {
        $parts[] = 'Carrinho: ' . json_encode($contextObj['cart'], JSON_UNESCAPED_UNICODE);
    }
    if (!empty($contextObj['issue'])) {
        $parts[] = 'Issue/tarefa: ' . (string) $contextObj['issue'];
    }
    if ($parts !== []) {
        $shopContext = "\n\nContexto da loja:\n" . implode("\n", $parts);
    }
}

// ─── Agent system prompts ───────────────────────────────────────────
$baseContext = 'Você é um assistente especializado da ShopVivaliz, uma loja de moda esportiva brasileira.' . $shopContext;

$directorSystem = $baseContext . ' Como Diretor de Engenharia, DevOps e Segurança, você coordena as análises do AI Squad, identifica riscos e propõe planos de ação claros e objetivos. Responda sempre em português.';

$claudeAgentSystem = $baseContext . ' Como Arquiteto de Software e especialista em QA, você analisa código, arquitetura e qualidade. Foque em soluções técnicas elegantes e seguras. Responda sempre em português.';

$gptAgentSystem = $baseContext . ' Como especialista em Olist/ERP, Checkout, Pagamentos e Business Intelligence, você analisa integrações, fluxos de pedido e métricas de negócio. Responda sempre em português.';

$geminiAgentSystem = $baseContext . ' Como especialista em Catálogo de Produtos, Imagens, UX e SEO, você analisa a vitrine, experiência do usuário e otimização para buscadores. Responda sempre em português.';

// Agent configurations
$agentConfigs = [
    'director' => [
        'name' => 'Diretor · DevOps · Segurança',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => $directorSystem,
    ],
    'claude' => [
        'name' => 'Arquiteto · QA',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => $claudeAgentSystem,
    ],
    'gpt' => [
        'name' => 'Olist · Checkout · Pagamentos · BI',
        'provider' => 'openai',
        'model' => $openaiModel,
        'system' => $gptAgentSystem,
    ],
    'gemini' => [
        'name' => 'Catálogo · Imagens · UX · SEO',
        'provider' => 'gemini',
        'model' => $geminiModel,
        'system' => $geminiAgentSystem,
    ],
    'roo_director' => [
        'name' => 'Roo - Diretor · DevOps · Segurança',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é um agente "Roo" que atua como backup ou assistente do Diretor. ' . $directorSystem,
    ],
    'roo_claude' => [
        'name' => 'Roo - Arquiteto · QA',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é um agente "Roo" que atua como backup ou assistente do Arquiteto. ' . $claudeAgentSystem,
    ],
    'roo_gpt' => [
        'name' => 'Roo - Olist · Checkout · Pagamentos · BI',
        'provider' => 'openai',
        'model' => $openaiModel,
        'system' => 'Você é um agente "Roo" que atua como backup ou assistente do especialista em Checkout. ' . $gptAgentSystem,
    ],
];
$agentConfigs['roo_gemini'] = [
    'name' => 'Roo - Catálogo · Imagens · UX · SEO',
    'provider' => 'gemini',
    'model' => $geminiModel,
    'system' => 'Você é um agente "Roo" que atua como backup ou assistente do agente de Catálogo, Imagens, UX e SEO. ' . $agentConfigs['gemini']['system'],
];

$allAgents = array_keys($agentConfigs);
$agentsToRun = $agentFilter === '' ? $allAgents : (in_array($agentFilter, $allAgents, true) ? [$agentFilter] : []);

if ($agentsToRun === []) {
    squad_json(400, ['error' => 'No valid agents selected']);
}

function call_claude_bridge_agent(string $bridgeUrl, string $system, string $model, array $messages, int $maxTokens): string
{
    $allowlist = ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5', 'claude-haiku-4-5-20251001'];
    $bridgeModel = in_array($model, $allowlist, true) ? $model : 'claude-haiku-4-5-20251001';
    $prompt = '';
    foreach ($messages as $msg) {
        $role = ($msg['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
        $prompt .= $role . ': ' . ($msg['content'] ?? '') . "\n";
    }
    $prompt = trim($prompt) ?: 'Olá.';
    $health = @file_get_contents($bridgeUrl . '/health');
    if ($health === false || !str_contains((string) $health, '"authenticated":true')) {
        throw new RuntimeException('claude_bridge_unavailable');
    }
    $data = squad_curl_json($bridgeUrl . '/v1/respond', ['Content-Type: application/json'], [
        'model' => $bridgeModel,
        'effort' => 'low',
        'system' => $system,
        'prompt' => $prompt,
        'web_search' => false,
    ]);
    if (!($data['ok'] ?? false)) {
        throw new RuntimeException('claude_bridge_error: ' . ($data['error_class'] ?? 'unknown'));
    }
    return trim((string) ($data['result'] ?? '')) ?: 'Sem resposta.';
}

function call_anthropic_agent(string $key, string $system, string $model, array $messages, int $maxTokens): string
{
    global $useBridge, $claudeBridgeUrl;
    if ($useBridge) {
        return call_claude_bridge_agent($claudeBridgeUrl, $system, $model, $messages, $maxTokens);
    }
    if ($key === '') {
        throw new RuntimeException('ANTHROPIC_API_KEY not configured');
    }
    $data = squad_curl_json('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ], [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => $messages,
    ]);
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string) ($block['text'] ?? '');
        }
    }
    return trim($text) ?: 'Sem resposta.';
}

function call_openai_agent(string $key, string $system, string $model, array $messages, int $maxTokens): string
{
    if ($key === '') {
        throw new RuntimeException('OPENAI_API_KEY not configured');
    }
    $payloadMessages = [['role' => 'system', 'content' => $system]];
    foreach ($messages as $message) {
        $payloadMessages[] = ['role' => $message['role'], 'content' => $message['content']];
    }
    $data = squad_curl_json('https://api.openai.com/v1/chat/completions', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], [
        'model' => $model,
        'messages' => $payloadMessages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.2,
    ]);
    return trim((string) ($data['choices'][0]['message']['content'] ?? '')) ?: 'Sem resposta.';
}

function call_gemini_agent(string $key, string $system, string $model, array $messages, int $maxTokens): string
{
    if ($key === '') {
        throw new RuntimeException('GEMINI_API_KEY not configured');
    }
    $contents = [];
    foreach ($messages as $message) {
        $contents[] = [
            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $message['content']]],
        ];
    }
    $data = squad_curl_json('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $key,
    ], [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => 0.2],
    ]);
    return trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '')) ?: 'Sem resposta.';
}

// ─── GitHub helper functions ────────────────────────────────────────
function gh_get_open_prs(): array
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    if ($token === '') return [];

    $repo = getenv('GH_REPO') ?: 'Vivaliz-site/site-shopvivaliz';
    $url = 'https://api.github.com/repos/' . $repo . '/pulls?state=open&per_page=20';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'User-Agent: ShopVivaliz-Squad/1.0',
        ],
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

function gh_get_file_content(string $path): string
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    if ($token === '' || $path === '') return '';

    $repo = getenv('GH_REPO') ?: 'Vivaliz-site/site-shopvivaliz';
    $safe = array_filter(explode('/', $path), fn($s) => $s !== '' && $s !== '..' && $s !== '.');
    if (count($safe) < 1) return '';
    $blocked = ['login_config', '.env', 'secret', 'password', 'senha', 'token', '.duck', '.sql'];
    foreach ($blocked as $b) {
        if (str_contains(strtolower($path), $b)) return '';
    }
    $url = 'https://api.github.com/repos/' . $repo . '/contents/' . implode('/', $safe);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'User-Agent: ShopVivaliz-Squad/1.0',
        ],
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['content'])) return '';
    return (string) base64_decode(str_replace("\n", '', (string) $data['content']));
}

function gh_get_issues(string $state = 'open'): array
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    if ($token === '') return [];

    $repo = getenv('GH_REPO') ?: 'Vivaliz-site/site-shopvivaliz';
    $url = 'https://api.github.com/repos/' . $repo . '/issues?state=' . $state . '&per_page=20';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'User-Agent: ShopVivaliz-Squad/1.0',
        ],
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

function gh_create_issue(string $title, string $body, array $labels = []): array
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    if ($token === '') return [];

    $repo = getenv('GH_REPO') ?: 'Vivaliz-site/site-shopvivaliz';
    $url = 'https://api.github.com/repos/' . $repo . '/issues';
    $payload = ['title' => $title, 'body' => $body];
    if ($labels !== []) $payload['labels'] = $labels;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json',
            'Accept: application/vnd.github+json',
            'User-Agent: ShopVivaliz-Squad/1.0',
        ],
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

function gh_get_workflow_runs(string $workflow = '', string $branch = 'main'): array
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    if ($token === '') return [];

    $repo = getenv('GH_REPO') ?: 'Vivaliz-site/site-shopvivaliz';
    $url = $workflow !== ''
        ? 'https://api.github.com/repos/' . $repo . '/actions/workflows/' . rawurlencode($workflow) . '/runs?branch=' . rawurlencode($branch) . '&per_page=10'
        : 'https://api.github.com/repos/' . $repo . '/actions/runs?branch=' . rawurlencode($branch) . '&per_page=15';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'User-Agent: ShopVivaliz-Squad/1.0',
        ],
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return is_array($data) ? ($data['workflow_runs'] ?? []) : [];
}

function gh_get_repo_stats(): array
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    if ($token === '') return [];

    $repo = getenv('GH_REPO') ?: 'Vivaliz-site/site-shopvivaliz';
    $url = 'https://api.github.com/repos/' . $repo;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'User-Agent: ShopVivaliz-Squad/1.0',
        ],
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

// ─── Consensus functions ─────────────────────────────────────────────
function svais_build_consensus(array $responses, string $userMessage): string
{
    $successTexts = [];
    foreach ($responses as $resp) {
        if (($resp['ok'] ?? false) && !empty($resp['text'])) {
            $successTexts[$resp['agent']] = $resp['text'];
        }
    }
    if (count($successTexts) < 2) {
        return '';
    }
    return implode("\n\n---\n\n", array_map(
        fn($agent, $text) => "**{$agent}:** {$text}",
        array_keys($successTexts),
        array_values($successTexts)
    ));
}

function svais_cycle_complete_for_consensus(array $responses, array $agentsToRun): bool
{
    $needed = array_fill_keys($agentsToRun, false);
    foreach ($responses as $r) {
        if (isset($needed[$r['agent']])) {
            $needed[$r['agent']] = true;
        }
    }
    foreach ($needed as $covered) {
        if (!$covered) return false;
    }
    return true;
}

// ─── Run agents ──────────────────────────────────────────────────────
$cycleId = 'cycle_' . gmdate('Ymd His') . '_' . substr(md5(uniqid('', true)), 0, 8);
$cycleId = str_replace(' ', '', $cycleId);

$responses = [];
$consensusAvailable = false;
$consensusText = '';

foreach ($agentsToRun as $agentKey) {
    $cfg = $agentConfigs[$agentKey];
    $provider = $cfg['provider'];
    $system = $cfg['system'];
    $model = $cfg['model'];
    $agentName = $cfg['name'];
    $ok = false;
    $text = '';

    try {
        $text = match ($provider) {
            'anthropic' => call_anthropic_agent($anthropicKey, $system, $model, $messages, $maxTokens),
            'openai'    => call_openai_agent($openaiKey, $system, $model, $messages, $maxTokens),
            'gemini'    => call_gemini_agent($geminiKey, $system, $model, $messages, $maxTokens),
            default     => throw new RuntimeException('Unknown provider: ' . $provider),
        };
        $ok = $text !== '' && $text !== 'Sem resposta.';
    } catch (Throwable $e) {
        $text = 'Erro: ' . $e->getMessage();
        $ok = false;
    }

    $responses[] = [
        'agent'    => $agentKey,
        'name'     => $agentName,
        'provider' => $provider,
        'model'    => $model,
        'text'     => $text,
        'ok'       => $ok,
    ];
}

if (svais_cycle_complete_for_consensus($responses, $agentsToRun)) {
    $consensusText = svais_build_consensus($responses, $userMessage);
    $consensusAvailable = $consensusText !== '';
}

squad_json(200, [
    'cycle_id'           => $cycleId,
    'responses'          => $responses,
    'consensus_available' => $consensusAvailable,
    'consensus'          => $consensusAvailable ? $consensusText : null,
    'at'                 => gmdate('c'),
]);
