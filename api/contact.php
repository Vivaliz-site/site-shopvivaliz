<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/order-rate-limit.php';
require_once dirname(__DIR__) . '/scripts/mailer.php';

function sv_contact_reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sv_contact_same_origin(): bool
{
    $origin = rtrim(trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    if ($origin === '') return true;
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br')));
    $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    return $originHost !== '' && hash_equals(preg_replace('/:\d+$/', '', $host) ?: $host, $originHost);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sv_contact_reply(405, ['ok' => false, 'error' => 'Método não permitido.']);
}
if (!sv_contact_same_origin()) {
    sv_contact_reply(403, ['ok' => false, 'error' => 'Origem não permitida.']);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    sv_contact_reply(413, ['ok' => false, 'error' => 'Mensagem muito grande.']);
}
if (!svorl_allow(5, 3600, 'contact')) {
    sv_contact_reply(429, ['ok' => false, 'error' => 'Limite de mensagens atingido. Tente novamente mais tarde.']);
}

$input = $_POST;
if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) $input = $decoded;
}
if (trim((string)($input['website'] ?? '')) !== '') {
    sv_contact_reply(200, ['ok' => true, 'message' => 'Mensagem recebida.']);
}

$name = trim(strip_tags((string)($input['name'] ?? '')));
$email = trim((string)($input['email'] ?? ''));
$phone = trim(strip_tags((string)($input['phone'] ?? '')));
$topic = trim(strip_tags((string)($input['topic'] ?? 'Atendimento')));
$topic = preg_replace('/[\r\n]+/', ' ', $topic) ?: 'Atendimento';
$order = trim(strip_tags((string)($input['order_reference'] ?? '')));
$message = trim(strip_tags((string)($input['message'] ?? '')));

if (mb_strlen($name) < 2 || mb_strlen($name) > 100
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || mb_strlen($topic) < 2 || mb_strlen($topic) > 80
    || mb_strlen($message) < 10 || mb_strlen($message) > 3000
    || mb_strlen($phone) > 40 || mb_strlen($order) > 80) {
    sv_contact_reply(422, ['ok' => false, 'error' => 'Revise nome, e-mail, assunto e mensagem.']);
}

$company = @include dirname(__DIR__) . '/config/company-profile.php';
$company = is_array($company) ? $company : [];
$to = trim((string)($company['operational']['support_email'] ?? $company['email'] ?? 'atendimento@shopvivaliz.com.br'));
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html = '<h2>Contato recebido pelo site</h2>'
    . '<p><strong>Nome:</strong> ' . $escape($name) . '<br>'
    . '<strong>E-mail para resposta:</strong> ' . $escape($email) . '<br>'
    . '<strong>Telefone:</strong> ' . $escape($phone !== '' ? $phone : 'Não informado') . '<br>'
    . '<strong>Assunto:</strong> ' . $escape($topic) . '<br>'
    . '<strong>Pedido:</strong> ' . $escape($order !== '' ? $order : 'Não informado') . '</p>'
    . '<p><strong>Mensagem:</strong><br>' . nl2br($escape($message)) . '</p>';
$text = "Contato recebido pelo site\nNome: {$name}\nE-mail: {$email}\nTelefone: {$phone}\nAssunto: {$topic}\nPedido: {$order}\n\n{$message}";

if (!send_email($to, '[Site] ' . $topic, $html, $text)) {
    error_log('[contact] falha no envio SMTP');
    sv_contact_reply(503, ['ok' => false, 'error' => 'Não foi possível enviar agora. Use o WhatsApp ou tente novamente.']);
}

sv_contact_reply(200, ['ok' => true, 'message' => 'Mensagem enviada. Guarde esta confirmação e acompanhe a resposta no e-mail informado.']);
