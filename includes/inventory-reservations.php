<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

final class SvirInsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly string $sku,
        public readonly int $available,
        public readonly int $requested
    ) {
        parent::__construct('insufficient_stock:' . $sku);
    }
}

function svir_ensure_schema(PDO $pdo): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    // A criacao das tabelas pertence ao deploy versionado. O usuario HTTP do
    // checkout precisa apenas de DML e nunca executa CREATE TABLE por pedido.
    foreach (['inventory_reservation_locks', 'inventory_reservations'] as $table) {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 0');
    }
    $verified = true;
}

function svir_ttl_minutes(string $paymentMethod): int
{
    return match (strtolower(trim($paymentMethod))) {
        'boleto' => 4320,
        'mercado_pago', 'infinitepay', 'pagarme' => 120,
        'pix' => 30,
        default => 30,
    };
}

/**
 * @return array{consumed:int,released_cancelled:int,released_expired:int,released_consumed:int}
 */
function svir_reconcile(PDO $pdo): array
{
    svir_ensure_schema($pdo);

    $consumed = $pdo->exec(
        "UPDATE inventory_reservations r
         INNER JOIN orders o ON o.order_number = r.order_number
         SET r.status = 'consumed',
             r.expires_at = GREATEST(r.expires_at, DATE_ADD(NOW(), INTERVAL 7 DAY))
         WHERE r.status = 'active'
           AND o.order_status = 'pagamento_aprovado'"
    );

    $releasedCancelled = $pdo->exec(
        "UPDATE inventory_reservations r
         INNER JOIN orders o ON o.order_number = r.order_number
         SET r.status = 'released', r.expires_at = NOW()
         WHERE r.status = 'active'
           AND o.order_status IN ('cancelado', 'devolvido')"
    );

    $releasedExpired = $pdo->exec(
        "UPDATE inventory_reservations
         SET status = 'released'
         WHERE status = 'active'
           AND expires_at <= NOW()"
    );

    // A retencao de sete dias evita dupla venda enquanto o ERP assimila a
    // baixa. Depois disso o estoque sincronizado volta a ser a fonte unica.
    $releasedConsumed = $pdo->exec(
        "UPDATE inventory_reservations
         SET status = 'released'
         WHERE status = 'consumed'
           AND expires_at <= NOW()"
    );

    return [
        'consumed' => max(0, (int)$consumed),
        'released_cancelled' => max(0, (int)$releasedCancelled),
        'released_expired' => max(0, (int)$releasedExpired),
        'released_consumed' => max(0, (int)$releasedConsumed),
    ];
}

/**
 * @param array<int,array<string,mixed>> $items
 */
function svir_reserve(
    string $reservationKey,
    array $items,
    string $paymentMethod
): void {
    if (preg_match('/^[a-f0-9]{64}$/', $reservationKey) !== 1) {
        throw new InvalidArgumentException('invalid_reservation_key');
    }

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sku = trim((string)($item['sku'] ?? ''));
        $quantity = max(1, (int)($item['quantity'] ?? 0));
        $stock = max(0, (int)($item['stock'] ?? 0));
        if ($sku === '') {
            continue;
        }
        $normalized[strtolower($sku)] = [
            'sku' => $sku,
            'quantity' => $quantity,
            'stock' => $stock,
        ];
    }
    if ($normalized === []) {
        throw new InvalidArgumentException('empty_reservation_items');
    }

    ksort($normalized, SORT_STRING);
    $pdo = sv_pdo();
    svir_ensure_schema($pdo);
    svir_reconcile($pdo);
    $ttl = svir_ttl_minutes($paymentMethod);
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttl * 60));

    $pdo->beginTransaction();
    try {
        $insertLock = $pdo->prepare(
            'INSERT IGNORE INTO inventory_reservation_locks (sku) VALUES (:sku)'
        );
        foreach ($normalized as $item) {
            $insertLock->execute([':sku' => $item['sku']]);
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $lockRows = $pdo->prepare(
            "SELECT sku FROM inventory_reservation_locks
             WHERE sku IN ({$placeholders})
             ORDER BY sku
             FOR UPDATE"
        );
        $lockRows->execute(array_column($normalized, 'sku'));
        $locked = $lockRows->fetchAll(PDO::FETCH_COLUMN);
        if (count($locked) !== count($normalized)) {
            throw new RuntimeException('inventory_lock_incomplete');
        }

        $heldStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM inventory_reservations
             WHERE sku = :sku
               AND status IN ('active', 'consumed')
               AND expires_at > NOW()"
        );
        $insert = $pdo->prepare(
            "INSERT INTO inventory_reservations
                (reservation_key, sku, quantity, source_stock, status, payment_method, expires_at)
             VALUES
                (:reservation_key, :sku, :quantity, :source_stock, 'active', :payment_method, :expires_at)"
        );

        foreach ($normalized as $item) {
            $heldStmt->execute([':sku' => $item['sku']]);
            $held = max(0, (int)$heldStmt->fetchColumn());
            $available = max(0, $item['stock'] - $held);
            if ($available < $item['quantity']) {
                throw new SvirInsufficientStock(
                    $item['sku'],
                    $available,
                    $item['quantity']
                );
            }
            $insert->execute([
                ':reservation_key' => $reservationKey,
                ':sku' => $item['sku'],
                ':quantity' => $item['quantity'],
                ':source_stock' => $item['stock'],
                ':payment_method' => strtolower(trim($paymentMethod)) ?: 'pix',
                ':expires_at' => $expiresAt,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function svir_release(string $reservationKey): void
{
    try {
        $pdo = sv_pdo();
        svir_ensure_schema($pdo);
        $stmt = $pdo->prepare(
            "UPDATE inventory_reservations
             SET status = 'released', expires_at = NOW()
             WHERE reservation_key = :reservation_key
               AND status = 'active'"
        );
        $stmt->execute([':reservation_key' => $reservationKey]);
    } catch (Throwable $error) {
        error_log('[inventory] release failed: ' . $error->getMessage());
    }
}

function svir_finalize(string $reservationKey, string $orderNumber, string $email): void
{
    $pdo = sv_pdo();
    svir_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE inventory_reservations
             SET order_number = :order_number
             WHERE reservation_key = :reservation_key
               AND status = 'active'"
        );
        $stmt->execute([
            ':order_number' => $orderNumber,
            ':reservation_key' => $reservationKey,
        ]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('reservation_finalize_missing');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    // Associacao de conta e melhor-esforco: uma falha de schema de usuarios
    // nunca pode desfazer a reserva que protege o estoque.
    if ($email !== '') {
        try {
            $associate = $pdo->prepare(
                "UPDATE orders o
                 INNER JOIN users u ON LOWER(u.email) = LOWER(:email)
                 SET o.user_id = u.id
                 WHERE o.order_number = :order_number
                   AND o.user_id IS NULL"
            );
            $associate->execute([
                ':email' => $email,
                ':order_number' => $orderNumber,
            ]);
        } catch (Throwable $error) {
            error_log('[inventory] account association failed: ' . $error->getMessage());
        }
    }
}

function svir_register_response_finalizer(
    string $reservationKey,
    string $customerEmail
): void {
    ob_start();
    register_shutdown_function(static function () use ($reservationKey, $customerEmail): void {
        $payload = json_decode((string)ob_get_contents(), true);
        if (!is_array($payload) || empty($payload['ok']) || empty($payload['order_number'])) {
            svir_release($reservationKey);
            return;
        }
        try {
            svir_finalize(
                $reservationKey,
                (string)$payload['order_number'],
                strtolower(trim($customerEmail))
            );
        } catch (Throwable $error) {
            error_log('[inventory] finalize failed: ' . $error->getMessage());
            svir_release($reservationKey);
        }
    });
}
