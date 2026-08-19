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
 * Pedido apenas criado/aguardando pagamento NAO encerra a recuperacao. Antes
 * desta regra, qualquer INSERT em orders bloqueava o e-mail exatamente para
 * clientes que abandonavam o pagamento depois de o pedido ser criado.
 *
 * A recuperacao e puramente transacional: nao oferece cupom ou desconto
 * adicional. Isso evita conflito com a politica comercial vigente.
 *
 * Uso: php scripts/send-abandoned-cart-emails.php
 */

require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';
require_once __DIR__ . '/../src/Commerce/AbandonedCartRecovery.php';
require_once __DIR__ . '/mailer.php';

const MIN_AGE_MINUTES = 60;
const MAX_AGE_HOURS = 48;

function sac_render_email(string $name, array $items): string
{
    $greeting = $name !== '' ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : 'tudo bem?';
    $itemsHtml = '';
    if ($items !== []) {
        $itemsHtml = '<ul style="padding-left:20px;color:#334155;">';
        foreach (array_slice($items, 0, 5) as $item) {
            $itemsHtml .= '<li>' . htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $itemsHtml .= '</ul>';
    }

    return '<div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;color:#173B63;">'
        . '<h2 style="color:#173B63;">Oi, ' . $greeting . '</h2>'
        . '<p>Notamos que você deixou alguns itens no carrinho da Vivaliz:</p>'
        . $itemsHtml
        . '<p>Seu carrinho continua disponível para você revisar itens, frete e condições antes de finalizar.</p>'
        . '<p><a href="https://shopvivaliz.com.br/carrinho" style="display:inline-block;margin-top:14px;background:#0b4f88;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700;">Voltar ao carrinho</a></p>'
        . '<p style="margin-top:24px;font-size:12px;color:#64748b;">Se você já concluiu essa compra ou não reconhece este e-mail, pode ignorar esta mensagem.</p>'
        . '</div>';
}

$pdo = sv_pdo();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "Banco indisponivel.\n");
    exit(1);
}
sv_account_ensure_schema();

// Autorrecupera o estado a partir do espelho canonico de pedidos. Isso faz
// recovered_at finalmente cumprir sua finalidade e protege contra falhas
// anteriores de webhook/marcacao.
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

foreach ($rows as $row) {
    $id = (int)$row['id'];

    // Reduz a janela de corrida com um pagamento aprovado entre o SELECT
    // inicial e o envio desta linha.
    $stillPending->execute([':id' => $id]);
    if (!$stillPending->fetchColumn()) {
        $skippedRecovered++;
        continue;
    }

    $email = (string)$row['email'];
    $name = trim((string)($row['customer_name'] ?? ''));
    $items = json_decode((string)($row['cart_snapshot'] ?? '[]'), true);
    $items = is_array($items) ? $items : [];

    $html = sac_render_email($name, $items);
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
            // O e-mail saiu, mas um pagamento pode ter sido aprovado no mesmo
            // instante. Nao conta como envio elegivel recuperavel.
            $skippedRecovered++;
        }
    } else {
        $failed++;
        error_log('[abandoned-cart-email] falha ao enviar para id=' . $id);
    }
}

echo "Enviados: {$sent} | Falhas: {$failed} | Candidatos: " . count($rows)
    . " | Marcados recuperados: {$reconciled} | Ignorados por recuperacao: {$skippedRecovered}\n";
