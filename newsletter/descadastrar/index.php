<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

require_once dirname(__DIR__, 2) . '/includes/email-marketing-service.php';

$encoded = trim((string)($_GET['e'] ?? ''));
$signature = trim((string)($_GET['s'] ?? ''));
$valid = false;
$message = 'Link de descadastro inválido ou expirado.';

if ($encoded !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $encoded) && preg_match('/^[a-f0-9]{64}$/', $signature)) {
    $expected = hash_hmac('sha256', $encoded, svem_secret());
    $decoded = svem_base64url_decode($encoded);
    if (is_string($decoded) && hash_equals($expected, $signature)) {
        $email = strtolower(trim($decoded));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $pdo = sv_pdo();
                $stmt = $pdo->prepare('INSERT INTO email_marketing_suppressions (email_hash, reason) VALUES (:email_hash, \'unsubscribe\') ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_at = NOW()');
                $stmt->execute([':email_hash' => svem_email_hash($email)]);

                $columns = svem_table_columns($pdo, 'newsletter_subscriptions');
                if (isset($columns['email'], $columns['status'])) {
                    $update = $pdo->prepare("UPDATE newsletter_subscriptions SET status = 'unsubscribed' WHERE LOWER(email) = :email");
                    $update->execute([':email' => $email]);
                }
                $valid = true;
                $message = 'Seu e-mail foi removido das comunicações de marketing da Vivaliz.';
            } catch (Throwable $error) {
                error_log('[email-marketing] unsubscribe failed: ' . $error->getMessage());
                http_response_code(503);
                $message = 'Não foi possível concluir o descadastro agora. Tente novamente mais tarde.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Descadastro | Vivaliz</title>
</head>
<body style="margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033">
<main style="max-width:620px;margin:64px auto;padding:24px">
    <section style="background:#fff;border-radius:16px;padding:32px;box-shadow:0 10px 30px rgba(0,0,0,.08)">
        <h1 style="color:#0b4f88"><?= $valid ? 'Descadastro concluído' : 'Não foi possível descadastrar' ?></h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <p><a href="/" style="color:#0b7a52;font-weight:700">Voltar para a Vivaliz</a></p>
    </section>
</main>
</body>
</html>
