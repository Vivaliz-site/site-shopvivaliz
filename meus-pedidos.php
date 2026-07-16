<?php
declare(strict_types=1);

session_start();

// Redirecionar se não está logado
if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/database.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';

try {
    $db = Database::getInstance()->getConnection();

    // Buscar pedidos do usuário
    $stmt = $db->prepare(
        'SELECT id, olist_order_id, order_total, order_status, payment_method,
                tracking_number, estimated_delivery, created_at
         FROM orders
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 50'
    );

    if (!$stmt) {
        throw new Exception('Database error');
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = [];

    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
} catch (Exception $e) {
    $orders = [];
    $error = 'Erro ao carregar pedidos';
}

$status_map = [
    'aguardando_pagamento' => 'Aguardando Pagamento',
    'pagamento_aprovado' => 'Pagamento Aprovado',
    'nota_fiscal_enviada' => 'Nota Fiscal Enviada',
    'pronto_para_enviar' => 'Pronto para Enviar',
    'enviado' => 'Enviado',
    'entregue' => 'Entregue',
    'cancelado' => 'Cancelado',
    'devolvido' => 'Devolvido',
];

$status_colors = [
    'aguardando_pagamento' => '#ff9800',
    'pagamento_aprovado' => '#2196f3',
    'nota_fiscal_enviada' => '#2196f3',
    'pronto_para_enviar' => '#ff9800',
    'enviado' => '#2196f3',
    'entregue' => '#4caf50',
    'cancelado' => '#f44336',
    'devolvido' => '#f44336',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pedidos - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/dazzle-v1.css?v=1.2.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        header {
            background: #173b63;
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-info a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }
        .container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }
        h1 {
            margin-bottom: 10px;
            font-size: 28px;
        }
        .welcome {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }
        .orders-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .order-item {
            border-bottom: 1px solid #eee;
            padding: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
            align-items: center;
            transition: background 0.3s;
        }
        .order-item:hover {
            background: #fafafa;
        }
        .order-item:last-child {
            border-bottom: none;
        }
        .order-id {
            font-weight: 600;
            color: #173b63;
        }
        .order-date {
            color: #666;
            font-size: 14px;
        }
        .order-total {
            font-weight: 600;
            font-size: 16px;
        }
        .order-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: white;
            display: inline-block;
            text-align: center;
        }
        .order-tracking {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-family: 'Courier New', monospace;
        }
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #999;
        }
        .empty-state p {
            font-size: 16px;
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #173b63;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #0f2a47;
        }
        .order-header {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
            padding: 15px 20px;
            background: #f9f9f9;
            font-weight: 600;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            border-bottom: 2px solid #173b63;
        }
        .logout-btn {
            background: #d32f2f;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 12px;
        }
        .logout-btn:hover {
            background: #b71c1c;
        }

        @media (max-width: 768px) {
            .order-header,
            .order-item {
                grid-template-columns: 1fr;
            }
            header {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">ShopVivaliz</div>
        <div class="user-info">
            <div>
                <div style="font-size: 14px;">Olá, <?php echo htmlspecialchars($user_name); ?></div>
                <div style="font-size: 12px; opacity: 0.8;"><?php echo htmlspecialchars($user_email); ?></div>
            </div>
            <a href="/" style="font-size: 14px;">← Voltar</a>
            <a href="/auth/logout.php" class="logout-btn">Sair</a>
        </div>
    </header>

    <div class="container">
        <h1>Meus Pedidos</h1>
        <p class="welcome">Acompanhe o status de seus pedidos aqui</p>

        <?php if (empty($orders)): ?>
            <div class="orders-container">
                <div class="empty-state">
                    <p>Você ainda não fez nenhum pedido</p>
                    <a href="/" class="btn">Começar a comprar</a>
                </div>
            </div>
        <?php else: ?>
            <div class="orders-container">
                <div class="order-header">
                    <div>Pedido</div>
                    <div>Data</div>
                    <div>Total</div>
                    <div>Status</div>
                </div>

                <?php foreach ($orders as $order): ?>
                    <?php
                    $status_label = $status_map[$order['order_status']] ?? ucfirst(str_replace('_', ' ', $order['order_status']));
                    $status_color = $status_colors[$order['order_status']] ?? '#999';
                    $date = date('d/m/Y H:i', strtotime($order['created_at']));
                    $total = number_format($order['order_total'], 2, ',', '.');
                    ?>
                    <div class="order-item">
                        <div>
                            <div class="order-id">#<?php echo htmlspecialchars($order['id']); ?></div>
                            <div class="order-date">ID Olist: <?php echo htmlspecialchars($order['olist_order_id'] ?? 'N/A'); ?></div>
                        </div>
                        <div><?php echo $date; ?></div>
                        <div class="order-total">R$ <?php echo $total; ?></div>
                        <div>
                            <div class="order-status" style="background-color: <?php echo htmlspecialchars($status_color); ?>;">
                                <?php echo htmlspecialchars($status_label); ?>
                            </div>
                            <?php if ($order['tracking_number']): ?>
                                <div class="order-tracking">
                                    📦 <?php echo htmlspecialchars($order['tracking_number']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($order['estimated_delivery']): ?>
                                <div class="order-tracking">
                                    Entrega: <?php echo date('d/m/Y', strtotime($order['estimated_delivery'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
