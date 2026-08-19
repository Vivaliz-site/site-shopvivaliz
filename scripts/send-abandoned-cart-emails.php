<?php
declare(strict_types=1);

/**
 * scripts/send-abandoned-cart-emails.php
 *
 * Roda via cron (sugestao: a cada 30-60 min). Varre checkout_abandonments
 * por registros onde:
 *   - o e-mail foi capturado ha mais de 1h e menos de 48h;
 *   - ainda nao recebeu e-mail de recuperacao;
 *   - ainda nao houve pagamento aprovado (ou etapa posterior de fulfillment)
 *     para esse e-mail depois do abandono.
 *
 * Pedido apenas criado/aguardando pagamento NAO encerra a recuperacao.
 * A recuperacao e transacional e nao oferece cupom/desconto adicional.
 *
 * Uso: php scripts/send-abandoned-cart-emails.php
 */

require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';
require_once __DIR__ . '/../src/Commerce/AbandonedCartRecovery.php';
require_once __DIR__ . '/../src/Commerce/AbandonedCartRecoverySchema.php';
require_once __DIR__ . '/mailer.php';

const MIN_AGE_MINUTES = 60;
const MAX_AGE_HOURS = 48;
const RESTORE_TOKEN_HOURS = 72;

function sac_snapshot_names(array $items): array
{
    $names = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $value = trim((string)($item['name'] ?? $item['sku'] ?? ''));
        } else {
            $value = trim((string)$item);
        }
        if ($value !== '') {
            $names[] = $value;
        }
    }
    return $names;
}

function sac_render_email(string $name, array $items, string $restoreUrl): string
{
    $greeting = $name !== '' ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : 'tudo bem?';
    $itemsHtml = '';
    $names = sac_snapshot_names($items);
    if ($names !== []) {
        $itemsHtml = '<ul style="padding-left:20px;color:#334155;">';
        foreach (array_slice($names, 0, 5) as $itemName) {
            $itemsHtml .= '<li>' . htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $itemsHtml .= '</ul>';
    }

    $safeRestoreUrl = htmlspecialchars($restoreUrl, ENT_QUOTES, 'UTF-8');
    return '<div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;color:#173B63;">'
        . '<h2 style="color:#173B63;">Oi, ' . $greeting . '</h2>'
        . '<p>Notamos que você deixou alguns itens no carrinho da Vivaliz:</p>'
        . $itemsHtml
        . '<p>Você pode retomar a compra mesmo em outro celular ou computador. Preço, estoque e disponibilidade serão confirmados novamente antes de finalizar.</p>'
        . '<p><a href="' . $safeRestoreUrl . '" style="display:inline-block;margin-top:14px;background:#0b4f88;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700;">Restaurar meu carrinho</a></p>'
        . '<p style="margin-top:24px;font-size:12px;color:#64748b;">O link expira automaticamente. Se você já concluiu essa compra ou não reconhece este e-mail, pode ignorar esta mensagem.</p>'
        . '</div>';
}

function sac_new_restore_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

$pdo = sv_pdo();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Banco indisponivel.\n");
    exit(1);
}
sv_account_ensure_schema();
svacr_ensure_restore_schema($pdo);

$reconciled = svacr_reconcile_completed_orders($pdo);

$stmt = $pdo->prepare(
    'SELECT ca.id, ca.email, ca.customer_name, ca.cart_snapshot, ca.created_at
     FROM checkout_abandonments ca
     WHERE ca.recovery_email_sent_at IS NULL
       AND ca.recovered_at IS NULL
       AND ca.created_at <= (NOW() - INTERVAL :minAge MINUTE)
       AND ca.created_at >= (NOW() - INTERVAL :maxAge HOUR)
     ORDER BY ca.created_at ASC
     LIMIT 100'
);
$stmt->execute([':minAge' => MIN_AGE_MINUTES, ':maxAge' => MAX_AGE_HOURS]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sent = 0;
$failed = 0;
$skippedRecovered = 0;
$stillPending = $pdo->prepare(
    'SELECT 1
     FROM checkout_abandonments
     WHERE id = :id
       AND recovery_email_sent_at IS NULL
       AND recovered_at IS NULL
     LIMIT 1'
);
$storeToken = $pdo->prepare(
    'UPDATE checkout_abandonments
     SET recovery_token_hash = :token_hash,
         recovery_token_expires_at = DATE_ADD(NOW(), INTERVAL 72 HOUR)
     WHERE id = :id
       AND recovery_email_sent_at IS NULL
       AND recovered_at IS NULL'
);

foreach ($rows as $row) {
    $id = (int)$row['id'];

    $stillPending->execute([':id' => $id]);
    if (!$stillPending->fetchColumn()) {
        $skippedRecovered++;
        continue;
    }

    $email = (string)$row['email'];
    $name = trim((string)($row['customer_name'] ?? ''));
    $items = json_decode((string)($row['cart_snapshot'] ?? '[]'), true);
    $items = is_array($items) ? $items : [];

    $restoreToken = sac_new_restore_token();
    $storeToken->execute([
        ':token_hash' => hash('sha256', $restoreToken),
        ':id' => $id,
    ]);
    if ($storeToken->rowCount() !== 1) {
        $skippedRecovered++;
        continue;
    }

    $restoreUrl = 'https://shopvivaliz.com.br/recuperar-carrinho.php#token=' . rawurlencode($restoreToken);
    $html = sac_render_email($name, $items, $restoreUrl);
    $ok = send_email($email, 'Você deixou itens no seu carrinho da Vivaliz', $html);

    if ($ok) {
        $upd = $pdo->prepare(
            'UPDATE checkout_abandonments
             SET recovery_email_sent_at = NOW()
             WHERE id = :id
               AND recovery_email_sent_at IS NULL
               AND recovered_at IS NULL'
        );
        $upd->execute([':id' => $id]);
        if ($upd->rowCount() === 1) {
            $sent++;
        } else {
            $skippedRecovered++;
        }
    } else {
        $failed++;
        error_log('[abandoned-cart-email] falha ao enviar para id=' . $id);
    }
}

echo "Enviados: {$sent} | Falhas: {$failed} | Candidatos: " . count($rows)
    . " | Marcados recuperados: {$reconciled} | Ignorados por recuperacao: {$skippedRecovered}\n";