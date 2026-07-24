<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/secure-session.php';
require_once __DIR__ . '/config/constants.php';

// ✅ Require login
if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Usuário';
$orders = [];
$error = '';

try {
    require_once __DIR__ . '/config/database.php';

    $db = Database::getInstance()->getConnection();

    // ✅ Fetch user orders
    $stmt = $db->prepare('
        SELECT id, order_number, customer_name, customer_email, total, status,
               payment_method, created_at
        FROM orders
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ');

    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
    }
} catch (Exception $e) {
    error_log('[meus-pedidos] ' . $e->getMessage());
    $error = 'Erro ao carregar pedidos. Tente novamente mais tarde.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pedidos - Vivaliz</title>
    <link rel="stylesheet" href="/css/shopvivaliz-core-consolidated.css?v=2026-07-19">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        h1 { font-size: 32px; margin-bottom: 30px; color: #173B63; }
        .welcome { background: white; padding: 20px; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .error { background: #fee; color: #c00; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #c00; }
        .empty { background: white; padding: 60px 20px; text-align: center; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
        th { background: #173B63; color: white; padding: 15px; text-align: left; font-weight: 600; }
        td { padding: 15px; border-bottom: 1px solid #eee; }
        .status { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Meus Pedidos</h1>
        <div class="welcome">
            <p>Bem-vindo, <strong><?= htmlspecialchars($user_name) ?></strong>!</p>
            <p>Aqui você pode acompanhar o status de todos os seus pedidos.</p>
        </div>
        <?php if ($error): ?>
            <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (empty($orders)): ?>
            <div class="empty">
                <h2>Você ainda não fez nenhum pedido</h2>
                <p>Explore nosso catálogo e faça sua primeira compra!</p>
                <a href="/catalogo" style="display: inline-block; background: #173B63; color: white; padding: 12px 24px; border-radius: 4px; text-decoration: none; margin-top: 20px;">Ver Catálogo</a>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th>Data</th>
                        <th>Total</th>
                        <th>Pagamento</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                            <td><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></td>
                            <td><strong style="color: #27ae60;">R$ <?= number_format($order['total'], 2, ',', '.') ?></strong></td>
                            <td><?= htmlspecialchars(ucfirst($order['payment_method'] ?? 'N/A')) ?></td>
                            <td><span class="status" style="background: #d4edda; color: #155724;"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div style="margin-top: 40px; text-align: center;">
            <a href="/" style="color: #173B63; text-decoration: none;">← Voltar para Home</a>
        </div>
    </div>
</body>
</html>
