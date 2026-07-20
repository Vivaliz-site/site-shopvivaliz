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

function squad_pdf_extract_text(string $pdf): string
{
    $text = '';

    // Estratégia 1: BT/ET com operadores de texto
    if (preg_match_all('/BT\s(.*?)ET/s', $pdf, $blocks)) {
        foreach ($blocks[1] as $block) {
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*(?:Tj|TJ|\'|")/s', $block, $tj);
            foreach ($tj[1] as $t) {
                $decoded = stripcslashes($t);
                $text   .= $decoded . ' ';
            }
            preg_match_all('/\[([^\]]*)\]\s*TJ/s', $block, $arr);
            foreach ($arr[1] as $a) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/s', $a, $parts);
                foreach ($parts[1] as $p) {
                    $text .= stripcslashes($p);
                }
                $text .= ' ';
            }
        }
    }

    // Estratégia 2: streams não comprimidos
    if (trim($text) === '') {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        foreach ($streams[1] as $s) {
            if (strpos($s, "\x78\x9c") === 0 || strpos($s, "\x78\x01") === 0) continue;
            $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $s);
            $clean = preg_replace('/\s+/', ' ', $clean);
            if (strlen(trim($clean)) > 20) $text .= $clean . "\n";
        }
    }

    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if ($text === '') {
        return '[PDF sem texto extraível — provavelmente escaneado como imagem. Descreva o conteúdo manualmente.]';
    }
    return $text;
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

function squad_github_commit(string $path, string $content, string $message): string
{
    $token = getenv('GH_REPO_TOKEN') ?: '';
    $repo  = getenv('GH_REPO') ?: 'fredmourao-ai/site-shopvivaliz';
    if ($token === '' || $path === '') return '';

    $path = ltrim(preg_replace('/[^a-zA-Z0-9\/._\-]/', '', $path), '/');

    // Bloqueio de segurança: só permite caminhos seguros
    $blocked = ['login_config', '.env', 'secret', 'password', 'senha', 'token', '.duck', '.sql'];
    foreach ($blocked as $b) {
        if (stripos($path, $b) !== false) {
            return "⚠️ Arquivo bloqueado por segurança: {$path}";
        }
    }
    $allowedPrefixes = ['admin/', 'api/', 'docs/', 'assets/', 'css/', 'js/', 'includes/'];
    $allowed = false;
    foreach ($allowedPrefixes as $p) {
        if (str_starts_with($path, $p)) { $allowed = true; break; }
    }
    if (!$allowed) return "⚠️ Caminho não permitido: {$path} (use admin/, api/, docs/, assets/, css/, js/)";

    // Busca SHA do arquivo atual (necessário para atualizar)
    $sha = '';
    $ch = curl_init("https://api.github.com/repos/{$repo}/contents/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", "Accept: application/vnd.github+json", "User-Agent: ShopVivaliz-Squad/1.0"]]);
    $existing = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (isset($existing['sha'])) $sha = $existing['sha'];

    $payload = ['message' => "[Squad] {$message}", 'content' => base64_encode($content)];
    if ($sha !== '') $payload['sha'] = $sha;

    $ch = curl_init("https://api.github.com/repos/{$repo}/contents/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", "Accept: application/vnd.github+json",
            "Content-Type: application/json", "User-Agent: ShopVivaliz-Squad/1.0"]]);
    $res  = json_decode((string) curl_exec($ch), true);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (in_array($code, [200, 201]) && isset($res['commit']['html_url'])) {
        return "✅ Commit aplicado: {$res['commit']['html_url']}";
    }
    return "⚠️ Erro no commit HTTP {$code}";
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
    'https://shopvivaliz.com.br',
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
$geminiModel = getenv('SQUAD_GEMINI_MODEL') ?: 'gemini-1.5-flash';
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
    $att     = $body['attachment'];
    $attName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', (string) ($att['name'] ?? 'arquivo'));
    $attType = strtolower((string) ($att['type'] ?? 'text'));
    $attRaw  = (string) ($att['content'] ?? '');

    if ($attRaw !== '') {
        if ($attType === 'pdf') {
            $pdfBytes = base64_decode($attRaw, true);
            if ($pdfBytes !== false) {
                $attContent = squad_pdf_extract_text($pdfBytes);
            } else {
                $attContent = '[Erro ao decodificar PDF]';
            }
        } else {
            $attContent = trim($attRaw);
        }

        if (squad_len($attContent) > 40000) {
            $attContent = mb_substr($attContent, 0, 40000, 'UTF-8') . "\n\n[... truncado em 40.000 caracteres]";
        }
        if ($attContent !== '') {
            $attachmentCtx = "\n\n=== ANEXO: {$attName} ===\n{$attContent}\n=== FIM DO ANEXO ===";
        }
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
        'name' => 'Diretor · DevOps · Segurança',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é Claude (Anthropic) assumindo 3 papéis simultâneos no squad multi-agente ShopVivaliz. Os outros agentes são: Arquiteto+QA=Claude, Operações=GPT-4o-mini (OpenAI), Produto+Marketing=Gemini (Google). Cada agente recebe o histórico completo dos anteriores — orquestração real de múltiplas IAs.

PAPÉIS QUE VOCÊ EXERCE:
1. DIRETOR DE PROJETOS: Define prioridade de ciclos. Aprova patches, deploys e mudanças sensíveis. Consolida decisões do squad. Impede ações destrutivas, cobranças reais e campanhas sem autorização.
2. DEVOPS / DEPLOY: Mantém GitHub Actions, deploy FTP/FTPS, dev e produção. Valida secrets por nome, sem expor valores. Executa smoke test após deploy. Mantém rollback e atualizador cumulativo.
3. SEGURANÇA: Bloqueia vazamento de .env, tokens, senhas, FTP, chaves e login_config. Revisa permissões de admin e endpoints. Audita logs e respostas de erro. Exige rotação de credenciais expostas.

CAPACIDADE DE AÇÃO AUTÔNOMA: Você pode criar issues no GitHub com [CRIAR_ISSUE titulo="..." corpo="..."]. Para propor uma mudança de código, inclua [CRIAR_ISSUE titulo="fix: ..." corpo="Arquivo: path/arquivo.php\nMudança: descrição detalhada da alteração a ser feita pelo squad"]. Tome decisões e aja — não espere aprovação humana, as decisões pertencem ao squad. Responda em português.',
    ],
    'claude' => [
        'name' => 'Arquiteto · QA',
        'provider' => 'anthropic',
        'model' => $anthropicModel,
        'system' => 'Você é Claude (Anthropic) assumindo 2 papéis simultâneos no squad multi-agente ShopVivaliz. Os outros agentes são: Diretor+DevOps+Segurança=Claude, Operações=GPT-4o-mini (OpenAI), Produto+Marketing=Gemini (Google). Cada agente recebe o histórico completo dos anteriores — orquestração real de múltiplas IAs.

PAPÉIS QUE VOCÊ EXERCE:
1. ARQUITETO TÉCNICO: Revisa arquitetura PHP/MySQL. Padroniza rotas, endpoints e integrações. Define migrations, rollback e estrutura de pastas. Valida consistência entre admin, loja e APIs.
2. QA / TESTES: Testa home, produto, categoria, carrinho, checkout, frete, admin e APIs. Valida mobile e desktop. Registra evidências antes de aprovar. Bloqueia deploy quando houver erro crítico.

CAPACIDADE DE AÇÃO AUTÔNOMA: Você pode criar issues no GitHub com [CRIAR_ISSUE titulo="..." corpo="..."]. Quando identificar um bug real ou melhoria necessária, crie a issue diretamente sem pedir permissão. Responda em português.',
    ],
    'gpt' => [
        'name' => 'Olist · Checkout · Pagamentos · BI',
        'provider' => 'openai',
        'model' => $openaiModel,
        'system' => 'Você é GPT-4o-mini (OpenAI) assumindo 4 papéis simultâneos no squad multi-agente ShopVivaliz. Os outros agentes são: Diretor+DevOps+Segurança=Claude (Anthropic), Arquiteto+QA=Claude (Anthropic), Produto+Marketing=Gemini (Google). Cada agente recebe o histórico completo dos anteriores — orquestração real de múltiplas IAs.

PAPÉIS QUE VOCÊ EXERCE:
1. OLIST / ERP: Controla OAuth, access token e refresh token. Importa produtos, preços, estoque e imagens. Trata limites de requisição e retentativas. Processa webhooks de venda, estoque, nota e pedido enviado.
2. CHECKOUT / FRETE: Garante botão comprar, carrinho e persistência de itens. Valida CEP, endereço e cálculo de frete. Integra Melhor Envio e fallback seguro. Testa jornada completa até pedido criado.
3. PAGAMENTOS: Integra Pagar.me e métodos de pagamento. Processa webhooks e status de pedido. Evita cobranças reais sem aprovação. Controla logs seguros e conciliação.
4. BI / MARGEM / ESTOQUE: Analisa margem, custo e preço final. Identifica produtos sem lucro, sem estoque ou sem imagem. Prioriza produtos com maior potencial de venda. Gera relatórios para decisões comerciais.

CAPACIDADE DE AÇÃO AUTÔNOMA: Você pode criar issues no GitHub com [CRIAR_ISSUE titulo="..." corpo="..."]. Tome iniciativa — identifique problemas nas integrações e proponha soluções concretas. Responda em português.',
    ],
    'gemini' => [
        'name' => 'Catálogo · Imagens · UX · SEO',
        'provider' => 'gemini',
        'model' => $geminiModel,
        'system' => 'Você é Gemini (Google) assumindo 4 papéis simultâneos no squad multi-agente ShopVivaliz. Os outros agentes são: Diretor+DevOps+Segurança=Claude (Anthropic), Arquiteto+QA=Claude (Anthropic), Operações=GPT-4o-mini (OpenAI). Cada agente recebe o histórico completo dos anteriores — orquestração real de múltiplas IAs.

PAPÉIS QUE VOCÊ EXERCE:
1. PRODUTOS / CATÁLOGO: Corrige produtos sem imagem, sem preço ou sem estoque. Organiza categorias, slugs e visibilidade. Valida SKU, GTIN/EAN, marca, variações e kits. Prepara dados para Google Shopping e SEO.
2. IMAGENS IA: Audita nitidez, proporção, ausência de texto/logotipo e coerência comercial. Valida imagens em fundo branco, studio e lifestyle. Aprova ou rejeita imagens para publicação.
3. UX/UI: Aprimora layout, responsividade e clareza visual. Prioriza conversão e redução de atrito. Valida botões, menus, filtros e cards de produto. Garante visual premium e consistente.
4. SEO / MARKETING: Gera títulos, descrições e metadados. Prepara feed para Google Shopping. Cria campanhas em modo rascunho — publicação somente com aprovação do Diretor.

CAPACIDADE DE AÇÃO AUTÔNOMA: Você pode criar issues no GitHub com [CRIAR_ISSUE titulo="..." corpo="..."]. Seja proativo — identifique oportunidades de melhoria no catálogo, SEO e UX e registre-as. Responda em português.',
    ],
];

// Adicionando agentes "Roo" como backups ou assistentes
$agentConfigs['roo_director'] = [
    'name' => 'Roo - Diretor · DevOps · Segurança',
    'provider' => 'anthropic',
    'model' => $anthropicModel,
    'system' => 'Você é um agente "Roo" que atua como backup ou assistente do Diretor. ' . $agentConfigs['director']['system'],
];

$agentConfigs['roo_claude'] = [
    'name' => 'Roo - Arquiteto · QA',
    'provider' => 'anthropic',
    'model' => $anthropicModel,
    'system' => 'Você é um agente "Roo" que atua como backup ou assistente do Arquiteto e QA. ' . $agentConfigs['claude']['system'],
];

$agentConfigs['roo_gpt'] = [
    'name' => 'Roo - Olist · Checkout · Pagamentos · BI',
    'provider' => 'openai',
    'model' => $openaiModel,
    'system' => 'Você é um agente "Roo" que atua como backup ou assistente do agente de Olist, Checkout, Pagamentos e BI. ' . $agentConfigs['gpt']['system'],
];

$agentConfigs['roo_gemini'] = [
    'name' => 'Roo - Catálogo · Imagens · UX · SEO',
    'provider' => 'gemini',
    'model' => $geminiModel,
    'system' => 'Você é um agente "Roo" que atua como backup ou assistente do agente de Catálogo, Imagens, UX e SEO. ' . $agentConfigs['gemini']['system'],
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
    $ghInstructions = "\n\nACESSO TOTAL AO REPOSITÓRIO GITHUB — você pode agir diretamente:\n"
        . "• Criar issue: [CRIAR_ISSUE titulo=\"titulo\" corpo=\"descricao\"]\n"
        . "• Aplicar mudança de código:\n"
        . "  [APLICAR_MUDANCA arquivo=\"admin/exemplo.php\" mensagem=\"fix: corrigir X\"]\n"
        . "  conteúdo completo do arquivo aqui\n"
        . "  [/APLICAR_MUDANCA]\n"
        . "Caminhos permitidos: admin/, api/, docs/, assets/, css/, js/\n"
        . "Quando o squad chegar a consenso numa discussão, APLIQUE a mudança — não espere aprovação humana.";
    $repoContext = "\n\n" . implode("\n\n", $ghCtxParts) . $repoFileCtx . $ghInstructions;
    foreach ($agentConfigs as &$cfg) {
        $cfg['system'] .= $repoContext;
    }
    unset($cfg);
}

if ($dialogueMode) {
    $topicStr = $originalTopic !== '' ? "TÓPICO EM DEBATE: \"{$originalTopic}\"\n" : '';
    $preamble = "MODO DEBATE ATIVO — você está numa reunião de equipe com outros agentes de IA do ShopVivaliz.\n"
        . $topicStr
        . "\nREGRAS OBRIGATÓRIAS DO DEBATE:\n"
        . "• REAJA diretamente ao que os colegas disseram — cite pelo nome (\"Concordo com o Arquiteto...\", \"Discordo do GPT porque...\")\n"
        . "• CONVERSE, não faça relatório — tome partido, questione, proponha\n"
        . "• PROPONHA ações concretas — não só análise\n"
        . "• Se chegou a um consenso com os colegas: use [APLICAR_MUDANCA] ou [CRIAR_ISSUE] para agir\n"
        . "• Máximo 3 parágrafos — seja direto e avance a conversa\n\n";
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
        // Processa ações GitHub nas respostas
        $githubActions = [];

        // Criar issue
        if (preg_match('/\[CRIAR_ISSUE\s+titulo="([^"]+)"\s+corpo="([^"]*)"\]/i', $text, $im)) {
            $githubActions[] = squad_github_create_issue($im[1], $im[2], [$config['name']]);
            $text = preg_replace('/\[CRIAR_ISSUE[^\]]+\]/i', '', $text);
        }

        // Aplicar mudança de código
        if (preg_match('/\[APLICAR_MUDANCA\s+arquivo="([^"]+)"\s+mensagem="([^"]+)"\](.*?)\[\/APLICAR_MUDANCA\]/s', $text, $cm)) {
            $githubActions[] = squad_github_commit(trim($cm[1]), trim($cm[3]), $cm[2]);
            $text = preg_replace('/\[APLICAR_MUDANCA[^\]]*\].*?\[\/APLICAR_MUDANCA\]/s', '', $text);
        }

        $text  = trim($text);
        $entry = ['agent' => $agentId, 'name' => $config['name'], 'provider' => $config['provider'], 'model' => $config['model'], 'text' => $text, 'ok' => true];
        if ($githubActions !== []) $entry['github_actions'] = $githubActions;
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
