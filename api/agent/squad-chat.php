<?php
/**
 * ShopVivaliz — Squad Chat Endpoint
 * POST /api/agent/squad-chat.php
 * GET  /api/agent/squad-chat.php?health=1
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
    $root = dirname(__DIR__, 2);
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
        $stored = json_decode((string) @file_get_contents($file), true);
        if (is_array($stored) && ($stored['minute'] ?? null) === $bucket['minute']) {
            $bucket['count'] = (int) ($stored['count'] ?? 0);
        }
    }

    $bucket['count']++;
    @file_put_contents($file, json_encode($bucket), LOCK_EX);

    if ($bucket['count'] > $limit) {
        squad_json(429, ['error' => 'Rate limit exceeded']);
    }
}

function squad_curl_json(string $url, array $headers, array $payload, int $timeout = 60): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('cURL error');
    }

    $decoded = json_decode((string) $response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode);
        throw new RuntimeException((string) $message);
    }

    return is_array($decoded) ? $decoded : [];
}

squad_env_load(dirname(__DIR__, 2) . '/.env');

function squad_github_tree(): string
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    $repo  = getenv('GH_REPO') ?: 'fredmourao-ai/site-shopvivaliz';
    if ($token === '') return '';

    $cacheDir  = dirname(__DIR__, 2) . '/logs/squad';
    $cacheFile = $cacheDir . '/repo-tree.cache';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
        return (string) file_get_contents($cacheFile);
    }

    $ch = curl_init("https://api.github.com/repos/{$repo}/git/trees/HEAD?recursive=1");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Accept: application/vnd.github+json",
            "User-Agent: ShopVivaliz-Squad/1.0",
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($raw, true);
    if (!isset($data['tree'])) return '';

    $keep = array_filter($data['tree'], fn($item) =>
        $item['type'] === 'blob' &&
        !str_contains($item['path'], 'node_modules') &&
        preg_match('/\.(php|html|yml|yaml|json|md|txt|js|css|env\.example)$/', $item['path'])
    );
    $paths = implode("\n", array_column(array_values($keep), 'path'));
    $ctx = "=== REPOSITÓRIO github.com/{$repo} ===\n{$paths}";

    @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheFile, $ctx);
    return $ctx;
}

function squad_github_file(string $path): string
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    $repo  = getenv('GH_REPO') ?: 'fredmourao-ai/site-shopvivaliz';
    if ($token === '' || $path === '') return '';

    $path = ltrim(preg_replace('/[^a-zA-Z0-9\/._\-]/', '', $path), '/');
    $ch = curl_init("https://api.github.com/repos/{$repo}/contents/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Accept: application/vnd.github+json",
            "User-Agent: ShopVivaliz-Squad/1.0",
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);

    $data = json_decode($raw, true);
    if (!isset($data['content'])) return '';
    $content = base64_decode(str_replace("\n", '', $data['content']));
    $lines   = substr_count($content, "\n");
    if ($lines > 300) {
        $content = implode("\n", array_slice(explode("\n", $content), 0, 300)) . "\n... (truncado em 300 linhas)";
    }
    return "=== {$path} ===\n{$content}";
}

function squad_github_issues(): string
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    $repo  = getenv('GH_REPO') ?: 'fredmourao-ai/site-shopvivaliz';
    if ($token === '') return '';

    $ch = curl_init("https://api.github.com/repos/{$repo}/issues?state=open&per_page=10&sort=updated");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Accept: application/vnd.github+json",
            "User-Agent: ShopVivaliz-Squad/1.0",
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);

    $items = json_decode($raw, true);
    if (!is_array($items)) return '';

    $lines = ["=== ISSUES ABERTAS ==="];
    foreach (array_slice($items, 0, 10) as $i) {
        $lines[] = "#{$i['number']} [{$i['state']}] {$i['title']} — {$i['html_url']}";
    }
    return implode("\n", $lines);
}

function squad_github_create_issue(string $title, string $body, array $labels = []): string
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    $repo  = getenv('GH_REPO') ?: 'fredmourao-ai/site-shopvivaliz';
    if ($token === '' || $title === '') return '';

    $payload = json_encode(array_filter(['title' => $title, 'body' => $body, 'labels' => $labels]));
    $ch = curl_init("https://api.github.com/repos/{$repo}/issues");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Accept: application/vnd.github+json",
            "Content-Type: application/json",
            "User-Agent: ShopVivaliz-Squad/1.0",
        ],
    ]);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($code === 201 && isset($data['html_url'])) {
        return "✅ Issue criada: #{$data['number']} — {$data['html_url']}";
    }
    return "⚠️ Erro ao criar issue: HTTP {$code}";
}

function squad_github_commits(): string
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    $repo  = getenv('GH_REPO') ?: 'fredmourao-ai/site-shopvivaliz';
    if ($token === '') return '';

    $ch = curl_init("https://api.github.com/repos/{$repo}/commits?per_page=10");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$token}",
            "Accept: application/vnd.github+json",
            "User-Agent: ShopVivaliz-Squad/1.0",
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);

    $items = json_decode($raw, true);
    if (!is_array($items)) return '';

    $lines = ["=== COMMITS RECENTES ==="];
    foreach (array_slice($items, 0, 10) as $c) {
        $sha  = substr($c['sha'], 0, 7);
        $msg  = strtok($c['commit']['message'], "\n");
        $date = substr($c['commit']['author']['date'], 0, 10);
        $lines[] = "{$sha} [{$date}] {$msg}";
    }
    return implode("\n", $lines);
}

$allowed_origins = [
    'https://dev.shopvivaliz.com.br',
    'https://shopvivaliz.com.br',
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
$geminiModel = getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-2.5-flash';
$maxTokens = (int) (getenv('SQUAD_MAX_TOKENS') ?: 900);
if ($maxTokens < 100 || $maxTokens > 4000) {
    $maxTokens = 900;
}

if (($_GET['health'] ?? '') === '1') {
    squad_json(200, [
        'ok' => true,
        'endpoint' => 'squad-chat',
        'version' => 'squad-chat-dialogue-mode-20260626',
        'token_required_for_post' => true,
        'env_loaded' => is_file(dirname(__DIR__, 2) . '/.env'),
        'providers' => [
            'anthropic' => ['configured' => $anthropicKey !== '', 'model' => $anthropicModel],
            'openai' => ['configured' => $openaiKey !== '', 'model' => $openaiModel],
            'gemini' => ['configured' => $geminiKey !== '', 'model' => $geminiModel],
        ],
        'agents' => ['director', 'claude', 'gpt', 'gemini'],
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
    squad_json(400, ['error' => 'Invalid input']);
}

$userMessage = trim((string) $body['message']);
if ($userMessage === '') {
    squad_json(400, ['error' => 'Message is empty or too large']);
}
// Trunca mensagens muito grandes em vez de rejeitar
if (squad_len($userMessage) > 80000) {
    $userMessage = mb_substr($userMessage, 0, 80000, 'UTF-8') . "\n\n[... conteúdo truncado em 80.000 caracteres]";
}

// Anexo de arquivo: conteúdo é adicionado ao contexto da mensagem
$attachmentCtx = '';
if (!empty($body['attachment'])) {
    $att = $body['attachment'];
    $attName    = preg_replace('/[^a-zA-Z0-9._\-]/', '_', (string) ($att['name'] ?? 'arquivo'));
    $attContent = trim((string) ($att['content'] ?? ''));
    if ($attContent !== '') {
        if (squad_len($attContent) > 40000) {
            $attContent = mb_substr($attContent, 0, 40000, 'UTF-8') . "\n\n[... truncado em 40.000 caracteres]";
        }
        $attachmentCtx = "\n\n=== ANEXO: {$attName} ===\n{$attContent}\n=== FIM DO ANEXO ===";
    }
}
if ($attachmentCtx !== '') {
    $userMessage .= $attachmentCtx;
}

$history = $body['history'] ?? [];
if (!is_array($history)) {
    $history = [];
}
$history = array_slice(array_filter($history, static function ($item): bool {
    return is_array($item)
        && in_array(($item['role'] ?? ''), ['user', 'assistant'], true)
        && trim((string) ($item['content'] ?? '')) !== '';
}), -8);

$dialogueMode   = ($body['dialogue_mode'] ?? false) === true;
$originalTopic  = trim((string) ($body['original_topic'] ?? ''));
$prevSpeakerName = trim((string) ($body['prev_speaker_name'] ?? ''));

$agentConfigs = [
    'director' => [
        'name' => 'Diretor de Projetos',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é o Diretor de Projetos do ShopVivaliz. Você é Claude (Anthropic), acionado via API neste sistema multi-agente onde cada chamada vai a um provider diferente: Arquiteto=Claude, Integrador=GPT-4o (OpenAI), Auditor=Gemini (Google). Cada agente lê o histórico dos anteriores — isso é orquestração real de múltiplas IAs. Assuma o papel de Diretor: coordene, valide riscos, tome decisões de projeto. Nunca exponha credenciais. Responda em português.',
    ],
    'claude' => [
        'name' => 'Arquiteto (Claude)',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é o Arquiteto de Software do ShopVivaliz. Você é Claude (Anthropic), acionado via API neste sistema multi-agente onde cada chamada vai a um provider diferente: Diretor=Claude, Integrador=GPT-4o (OpenAI), Auditor=Gemini (Google). Cada agente lê o histórico dos anteriores — orquestração real de múltiplas IAs. Assuma o papel de Arquiteto: arquitetura PHP segura, cumulativa, sem inventar estrutura. Responda em português.',
    ],
    'gpt' => [
        'name' => 'Integrador (GPT-4o)',
        'provider' => 'openai',
        'model' => $openaiModel,
        'system' => 'Você é o Integrador do ShopVivaliz. Você é GPT-4o (OpenAI), acionado via API neste sistema multi-agente onde cada chamada vai a um provider diferente: Diretor e Arquiteto=Claude (Anthropic), Auditor=Gemini (Google). Cada agente lê o histórico dos anteriores — orquestração real de múltiplas IAs. Assuma o papel de Integrador: APIs, deploy, testes, Olist, checkout, frete, diagnóstico prático. Responda em português.',
    ],
    'gemini' => [
        'name' => 'Auditor (Gemini)',
        'provider' => 'gemini',
        'model' => $geminiModel,
        'system' => 'Você é o Auditor Geral do ShopVivaliz. Você é Gemini (Google), acionado via API neste sistema multi-agente onde cada chamada vai a um provider diferente: Diretor e Arquiteto=Claude (Anthropic), Integrador=GPT-4o (OpenAI). Cada agente lê o histórico dos anteriores — orquestração real de múltiplas IAs. Assuma o papel de Auditor: segurança, LGPD, XSS, CSRF, custo de API, SEO e qualidade. Responda em português.',
    ],
];

// Contexto do repositório para todos os agentes
$repoTree    = squad_github_tree();
$repoIssues  = squad_github_issues();
$repoCommits = squad_github_commits();

// Detecta arquivos mencionados na mensagem e busca conteúdo
$repoFileCtx = '';
if ($repoTree !== '') {
    preg_match_all('/(?:^|[\s`\'"])([a-zA-Z0-9_\-\/]+\.[a-zA-Z]{2,5})(?:[\s`\'"]|$)/', $userMessage, $fileMatches);
    $mentioned = array_unique($fileMatches[1] ?? []);
    $fetched = [];
    foreach (array_slice($mentioned, 0, 3) as $fp) {
        $fc = squad_github_file($fp);
        if ($fc !== '') $fetched[] = $fc;
    }
    $repoFileCtx = $fetched !== [] ? "\n\n" . implode("\n\n", $fetched) : '';
}

$ghCtxParts = array_filter([$repoTree, $repoIssues, $repoCommits]);
if ($ghCtxParts !== []) {
    $ghInstructions = "\n\nVocê tem acesso total ao repositório GitHub. "
        . "Para criar uma issue use o bloco: [CRIAR_ISSUE titulo=\"...\" corpo=\"...\"] no final da sua resposta. "
        . "Você pode analisar o código, sugerir melhorias, identificar bugs e abrir issues diretamente.";
    $repoContext = "\n\n" . implode("\n\n", $ghCtxParts) . $repoFileCtx . $ghInstructions;
    foreach ($agentConfigs as &$cfg) {
        $cfg['system'] .= $repoContext;
    }
    unset($cfg);
}

if ($dialogueMode) {
    $topicStr = $originalTopic !== '' ? "Tópico em debate: \"{$originalTopic}\". " : '';
    $prevStr  = $prevSpeakerName !== '' ? "O agente anterior que falou foi: {$prevSpeakerName}. " : '';
    $preamble = "Você está num debate técnico colaborativo entre agentes de IA da equipe ShopVivaliz. "
        . "Os participantes são: Diretor de Projetos (Claude), Arquiteto (Claude), Integrador (GPT-4o) e Auditor (Gemini). "
        . $topicStr . $prevStr
        . "Seja conciso (até 3 parágrafos), responda diretamente ao ponto anterior, discorde quando necessário e agregue sua perspectiva especializada. "
        . "Não repita o que já foi dito — avance a conversa.\n\n";
    foreach ($agentConfigs as &$cfg) {
        $cfg['system'] = $preamble . $cfg['system'];
    }
    unset($cfg);
}

$requestedAgents = $body['agents'] ?? array_keys($agentConfigs);
if (!is_array($requestedAgents)) {
    $requestedAgents = array_keys($agentConfigs);
}
$agentsToRun = array_values(array_filter(array_keys($agentConfigs), static fn(string $id): bool => in_array($id, $requestedAgents, true)));
if ($agentsToRun === []) {
    squad_json(400, ['error' => 'No valid agents selected']);
}

function call_anthropic_agent(string $key, string $system, string $model, array $messages, int $maxTokens): string
{
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

$responses = [];
foreach ($agentsToRun as $agentId) {
    $config = $agentConfigs[$agentId];
    $context = $userMessage;
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
        if ($config['provider'] === 'openai') {
            $text = call_openai_agent($openaiKey, $config['system'], $config['model'], $messages, $maxTokens);
        } elseif ($config['provider'] === 'gemini') {
            $text = call_gemini_agent($geminiKey, $config['system'], $config['model'], $messages, $maxTokens);
        } else {
            $text = call_anthropic_agent($anthropicKey, $config['system'], $config['model'], $messages, $maxTokens);
        }
        // Processa comando de criação de issue
        $githubAction = null;
        if (preg_match('/\[CRIAR_ISSUE\s+titulo="([^"]+)"\s+corpo="([^"]*)"\]/i', $text, $im)) {
            $issueResult  = squad_github_create_issue($im[1], $im[2], [$config['name']]);
            $text         = preg_replace('/\[CRIAR_ISSUE[^\]]+\]/i', '', $text);
            $githubAction = $issueResult;
        }
        $entry = ['agent' => $agentId, 'name' => $config['name'], 'provider' => $config['provider'], 'model' => $config['model'], 'text' => $text, 'ok' => true];
        if ($githubAction !== null) $entry['github_action'] = $githubAction;
        $responses[] = $entry;
    } catch (RuntimeException $e) {
        $responses[] = ['agent' => $agentId, 'name' => $config['name'], 'provider' => $config['provider'], 'model' => $config['model'], 'text' => 'Erro: ' . $e->getMessage(), 'ok' => false];
    }
}

$logDir = dirname(__DIR__, 2) . '/logs/squad';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logDir . '/chat.log', json_encode([
    'at' => date('c'),
    'agents' => array_column($responses, 'agent'),
    'ok_count' => count(array_filter($responses, static fn(array $r): bool => $r['ok'] === true)),
    'msg_len' => squad_len($userMessage),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

squad_json(200, [
    'cycle_id' => 'cycle_' . date('YmdHis') . '_' . substr(hash('sha256', uniqid('', true)), 0, 8),
    'responses' => $responses,
    'at' => date('c'),
]);
