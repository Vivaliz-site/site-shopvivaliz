<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/order-rate-limit.php';
require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
require_once dirname(__DIR__, 2) . '/scripts/mailer.php';

function svnl_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svnl_secret(): string
{
    foreach (['NEWSLETTER_SIGNING_KEY', 'APP_KEY', 'SHOPVIVALIZ_APP_KEY', 'SHOPVIVALIZ_AGENT_KEY'] as $key) {
        $value = getenv($key);
        if (is_string($value) && strlen(trim($value)) >= 24) {
            return trim($value);
        }
    }
    svnl_json(503, ['ok' => false, 'error' => 'newsletter_unavailable', 'message' => 'Cadastro temporariamente indisponível.']);
}

function svnl_same_origin(): bool
{
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return true;
    }
    $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
    return in_array($host, ['shopvivaliz.com.br', 'www.shopvivaliz.com.br', 'localhost', '127.0.0.1'], true);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svnl_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (!svnl_same_origin()) {
    svnl_json(403, ['ok' => false, 'error' => 'origin_rejected']);
}
if (!svorl_allow(5, 3600, 'newsletter')) {
    header('Retry-After: 3600');
    svnl_json(429, [
        'ok' => false,
        'error' => 'rate_limit_exceeded',
        'message' => 'Muitas tentativas de cadastro. Aguarde um pouco antes de tentar novamente.',
    ]);
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 8192) {
    svnl_json(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    svnl_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

if (trim((string)($body['website'] ?? '')) !== '') {
    svnl_json(200, ['ok' => true, 'message' => 'Cadastro recebido.']);
}

$email = strtolower(trim((string)($body['email'] ?? '')));
$consent = filter_var($body['consent'] ?? false, FILTER_VALIDATE_BOOL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    svnl_json(422, ['ok' => false, 'error' => 'invalid_email', 'message' => 'Informe um e-mail válido.']);
}
if (!$consent) {
    svnl_json(422, ['ok' => false, 'error' => 'consent_required', 'message' => 'Confirme que deseja receber novidades e ofertas.']);
}

$secret = svnl_secret();
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$ip = svorl_client_ip();
$userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
$ipHash = hash_hmac('sha256', $ip, $secret);
$userAgentHash = hash_hmac('sha256', $userAgent, $secret);

try {
    $pdo = sv_pdo();
    $stmt = $pdo->prepare(
        "INSERT INTO newsletter_subscriptions
            (email, status, confirm_token_hash, consent_at, source, ip_hash, user_agent_hash)
         VALUES
            (:email, 'pending', :token_hash, NOW(), 'site_home', :ip_hash, :user_agent_hash)
         ON DUPLICATE KEY UPDATE
            status = IF(status = 'confirmed', 'confirmed', 'pending'),
            confirm_token_hash = IF(status = 'confirmed', confirm_token_hash, VALUES(confirm_token_hash)),
            consent_at = NOW(),
            ip_hash = VALUES(ip_hash),
            user_agent_hash = VALUES(user_agent_hash)"
    );
    $stmt->execute([
        ':email' => $email,
        ':token_hash' => $tokenHash,
        ':ip_hash' => $ipHash,
        ':user_agent_hash' => $userAgentHash,
    ]);

    $statusStmt = $pdo->prepare('SELECT status FROM newsletter_subscriptions WHERE email = :email LIMIT 1');
    $statusStmt->execute([':email' => $email]);
    $status = (string)$statusStmt->fetchColumn();
} catch (Throwable $error) {
    error_log('[newsletter] subscription storage failed: ' . $error->getMessage());
    svnl_json(503, ['ok' => false, 'error' => 'storage_unavailable', 'message' => 'Não foi possível concluir o cadastro agora.']);
}

if ($status === 'confirmed') {
    svnl_json(200, ['ok' => true, 'confirmed' => true, 'message' => 'Este e-mail já está confirmado para receber novidades.']);
}

$confirmUrl = 'https://shopvivaliz.com.br/newsletter/confirmar?token=' . rawurlencode($token);
$subject = 'Confirme seu cadastro na Vivaliz';
$text = "Olá!\n\nConfirme que deseja receber novidades e ofertas da Vivaliz acessando o link abaixo:\n\n{$confirmUrl}\n\nSe você não solicitou este cadastro, ignore esta mensagem.\n";
$html = '<h2>Confirme seu cadastro</h2>'
    . '<p>Você solicitou receber novidades e ofertas da Vivaliz.</p>'
    . '<p><a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 18px;background:#0b7a52;color:#fff;text-decoration:none;border-radius:8px;font-weight:700">Confirmar e-mail</a></p>'
    . '<p>Se você não solicitou este cadastro, ignore esta mensagem.</p>';

$mailSent = send_email($email, $subject, $html, $text);
if (!$mailSent) {
    error_log('[newsletter] confirmation email transport failed hash=' . substr(hash('sha256', $email), 0, 12));
    svnl_json(502, ['ok' => false, 'error' => 'email_transport_failed', 'message' => 'Cadastro salvo, mas não foi possível enviar a confirmação. Tente novamente mais tarde.']);
}

svnl_json(200, [
    'ok' => true,
    'confirmed' => false,
    'message' => 'Enviamos um link de confirmação para o seu e-mail.',
]);
