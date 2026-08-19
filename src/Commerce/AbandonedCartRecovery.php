<?php
declare(strict_types=1);

/**
 * Shared rules for abandoned-cart recovery.
 *
 * A cart is considered recovered only after a status that proves payment
 * approval or a later fulfillment stage. Merely creating an order (or leaving
 * it waiting for payment) must not suppress recovery.
 */
function svacr_completed_order_statuses(): array
{
    return [
        'pagamento_aprovado',
        'nota_fiscal_enviada',
        'pronto_para_enviar',
        'enviado',
        'entregue',
        'nao_entregue',
    ];
}

function svacr_status_is_completed(string $status): bool
{
    return in_array(strtolower(trim($status)), svacr_completed_order_statuses(), true);
}

/**
 * Reconcile old abandonment rows against the canonical MySQL order mirror.
 *
 * This is intentionally idempotent. It also self-heals cases where a payment
 * webhook succeeded but no explicit abandoned-cart marker was written.
 */
function svacr_reconcile_completed_orders(PDO $pdo): int
{
    $statuses = svacr_completed_order_statuses();
    if ($statuses === []) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
    $sql = 'UPDATE checkout_abandonments ca
            INNER JOIN orders o
                ON LOWER(TRIM(o.email)) = LOWER(TRIM(ca.email))
               AND o.created_at >= ca.created_at
            SET ca.recovered_at = COALESCE(ca.recovered_at, NOW())
            WHERE ca.recovered_at IS NULL
              AND LOWER(TRIM(COALESCE(o.order_status, ""))) IN (' . $placeholders . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($statuses);
    return $stmt->rowCount();
}

/**
 * Mark every still-open abandonment for an e-mail as recovered. This helper
 * is safe for payment webhooks to call after a confirmed approval.
 */
function svacr_mark_email_recovered(PDO $pdo, string $email): int
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'UPDATE checkout_abandonments
         SET recovered_at = COALESCE(recovered_at, NOW())
         WHERE recovered_at IS NULL
           AND LOWER(TRIM(email)) = :email'
    );
    $stmt->execute([':email' => $email]);
    return $stmt->rowCount();
}
