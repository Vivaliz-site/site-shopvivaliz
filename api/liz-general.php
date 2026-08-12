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

$input = json_decode((string)file_get_contents('php://input'), true);
$message = trim((string)($input['message'] ?? ''));
if ($message === '' || mb_strlen($message, 'UTF-8') > 2000) {
    lizg_reply(400, ['ok' => false, 'error' => 'Pergunta ausente ou muito longa.']);
}

$key = trim((string)(getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_GEMINI_API_KEY') ?: ''));
if ($key === '') {
    lizg_reply(503, ['ok' => false, 'error' => 'A pesquisa da Liz está temporariamente indisponível.']);
}

$model = trim((string)(getenv('GEMINI_MODEL') ?: 'gemini-2.0-flash'));
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
$system = <<<'TXT'
Você é Liz, assistente virtual da ShopVivaliz. Também pode conversar de forma simpática sobre assuntos gerais, para tornar o atendimento mais humano.
Quando a pergunta não for sobre a loja, responda em português do Brasil, de forma breve, clara e correta.
Use a pesquisa Google fornecida pela API quando o assunto puder ter mudado, exigir informação atual ou quando a pergunta pedir pesquisa.
Para receitas, ciência básica, curiosidades e conhecimento estável, responda diretamente.
Não invente fatos. Em temas médicos, jurídicos ou financeiros, dê apenas informação geral e recomende orientação profissional quando necessário.
Não transforme toda resposta em oferta comercial e não force o retorno ao assunto da loja.
TXT;

$payload = [
    'system_instruction' => ['parts' => [['text' => $system]]],
    'contents' => [['role' => 'user', 'parts' => [['text' => $message]]]],
    'tools' => [['google_search' => new stdClass()]],
    'generationConfig' => ['maxOutputTokens' => 900, 'temperature' => 0.35],
];

function lizg_request(string $url, string $key, array $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) return [0, ''];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, is_string($body) ? $body : ''];
}

[$status, $body] = lizg_request($url, $key, $payload);
$groundingUsed = true;

// Grounding é um recurso opcional. Se ele falhar por modelo, quota, billing,
// permissão ou indisponibilidade temporária, a Liz ainda deve conseguir conversar.
// A segunda tentativa não alega pesquisa web e preserva erros de autenticação da
// chamada básica, que continuam resultando em 503 abaixo.
if ($status !== 200) {
    unset($payload['tools']);
    $groundingUsed = false;
    $payload['system_instruction']['parts'][0]['text'] .= "\nA pesquisa web não está disponível nesta execução. Não diga que pesquisou; avise quando uma informação atual não puder ser confirmada.";
    [$status, $body] = lizg_request($url, $key, $payload);
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
if ($status !== 200 || $answer === '') {
    error_log('Liz Gemini request failed after fallback; HTTP ' . $status . ', model=' . $model);
    lizg_reply(503, ['ok' => false, 'error' => 'Não consegui pesquisar ou responder agora. Tente novamente em instantes.']);
}

lizg_reply(200, [
    'ok' => true,
    'answer' => $answer,
    'provider' => 'gemini',
    'web_grounding_requested' => $groundingUsed,
    'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format(DateTime::ATOM),
]);
