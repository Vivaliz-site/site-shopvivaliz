<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';

$token = trim((string)($_GET['token'] ?? ''));
$ok = false;
$message = 'O link de confirmação é inválido ou expirou.';

if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
    try {
        $pdo = sv_pdo();
        $tokenHash = hash('sha256', $token);
        $lookup = $pdo->prepare(
            "SELECT id, status
             FROM newsletter_subscriptions
             WHERE confirm_token_hash = :token_hash
             LIMIT 1"
        );
        $lookup->execute([':token_hash' => $tokenHash]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && in_array((string)$row['status'], ['pending', 'confirmed'], true)) {
            if ((string)$row['status'] !== 'confirmed') {
                $update = $pdo->prepare(
                    "UPDATE newsletter_subscriptions
                     SET status = 'confirmed', confirmed_at = COALESCE(confirmed_at, NOW())
                     WHERE id = :id"
                );
                $update->execute([':id' => (int)$row['id']]);
            }
            $ok = true;
            $message = 'Seu e-mail foi confirmado. Agora você pode receber novidades e ofertas da Vivaliz.';
        }
    } catch (Throwable $error) {
        error_log('[newsletter] confirmation failed: ' . $error->getMessage());
        $message = 'Não foi possível confirmar o cadastro agora. Tente novamente mais tarde.';
    }
}

http_response_code($ok ? 200 : 422);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= $ok ? 'Cadastro confirmado' : 'Confirmação inválida' ?> | Vivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .confirm-shell{min-height:70vh;display:flex;align-items:center;justify-content:center;padding:48px 20px;background:#f8fafc}
        .confirm-card{max-width:620px;padding:38px;border:1px solid #dbe5ef;border-radius:24px;background:#fff;text-align:center;box-shadow:0 20px 50px rgba(15,23,42,.08)}
        .confirm-icon{font-size:54px;margin-bottom:14px}.confirm-card h1{color:#173b63;margin:0 0 12px}.confirm-card p{color:#64748b;line-height:1.6;margin:0 0 24px}
        .confirm-card a{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 22px;border-radius:999px;background:#35c759;color:#fff;text-decoration:none;font-weight:800}
    </style>
</head>
<body>
<?php $svNavCurrent = ''; include dirname(__DIR__, 2) . '/includes/navbar.php'; ?>
<main class="confirm-shell">
    <section class="confirm-card" aria-live="polite">
        <div class="confirm-icon" aria-hidden="true"><?= $ok ? '✅' : '⚠️' ?></div>
        <h1><?= $ok ? 'Cadastro confirmado' : 'Não foi possível confirmar' ?></h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="/catalogo">Continuar para o catálogo</a>
    </section>
</main>
</body>
</html>
