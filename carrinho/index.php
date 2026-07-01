<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
define('APP_NAME', 'ShopVivaliz');

session_start();

// Base de dados de produtos
$produtos_db = [
    1 => ['id' => 1, 'nome' => 'Camiseta Premium', 'preco' => 79.90],
    2 => ['id' => 2, 'nome' => 'Calça Jeans', 'preco' => 149.90],
    3 => ['id' => 3, 'nome' => 'Tênis Esportivo', 'preco' => 199.90],
    4 => ['id' => 4, 'nome' => 'Relógio Digital', 'preco' => 89.90],
    5 => ['id' => 5, 'nome' => 'Mochila Impermeável', 'preco' => 129.90],
    6 => ['id' => 6, 'nome' => 'Jaqueta de Couro', 'preco' => 299.90],
];

$carrinho = $_SESSION['carrinho'] ?? [];
$acao = $_POST['acao'] ?? '';

// Processar ações
if ($acao === 'atualizar_quantidade') {
    $id = (int)$_POST['id'];
    $qtd = (int)$_POST['quantidade'];
    if ($qtd > 0) {
        $_SESSION['carrinho'][$id] = $qtd;
    } else {
        unset($_SESSION['carrinho'][$id]);
    }
    $carrinho = $_SESSION['carrinho'] ?? [];
}

if ($acao === 'remover') {
    $id = (int)$_POST['id'];
    unset($_SESSION['carrinho'][$id]);
    $carrinho = $_SESSION['carrinho'] ?? [];
}

// Calcular total
$total = 0;
$itens = [];
foreach ($carrinho as $id => $qtd) {
    if (isset($produtos_db[$id])) {
        $p = $produtos_db[$id];
        $subtotal = $p['preco'] * $qtd;
        $total += $subtotal;
        $itens[] = ['id' => $id, 'nome' => $p['nome'], 'preco' => $p['preco'], 'qtd' => $qtd, 'subtotal' => $subtotal];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Carrinho</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .carrinho-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin: 30px 0;
        }
        @media (max-width: 768px) {
            .carrinho-container {
                grid-template-columns: 1fr;
            }
        }
        .carrinho-tabela {
            width: 100%;
            border-collapse: collapse;
        }
        .carrinho-tabela th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
        }
        .carrinho-tabela td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .carrinho-tabela input {
            width: 60px;
            padding: 5px;
            border: 1px solid #e5e7eb;
        }
        .carrinho-vazio {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .resumo-pedido {
            background: #f5f7fa;
            padding: 20px;
            border-radius: 8px;
            height: fit-content;
        }
        .resumo-linha {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .resumo-total {
            font-size: 1.5em;
            font-weight: bold;
            color: #667eea;
            margin: 20px 0;
            text-align: right;
        }
        .btn-checkout {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            font-size: 1.1em;
            font-weight: 600;
            margin-top: 15px;
        }
        .btn-checkout:hover {
            background: #764ba2;
        }
        .btn-continuar {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 10px;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        .btn-remover {
            background: #ef4444;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <main class="container">
        <h1>🛒 Carrinho de Compras</h1>

        <?php if (empty($itens)): ?>
            <div class="carrinho-vazio">
                <p>Seu carrinho está vazio</p>
                <a href="/catalogo/index.php" class="btn-checkout">← Continuar Comprando</a>
            </div>
        <?php else: ?>
            <div class="carrinho-container">
                <div>
                    <table class="carrinho-tabela">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th>Preço</th>
                                <th>Quantidade</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itens as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['nome']); ?></td>
                                    <td>R$ <?php echo number_format($item['preco'], 2, ',', '.'); ?></td>
                                    <td>
                                        <form method="POST" style="display: flex; gap: 5px;">
                                            <input type="hidden" name="acao" value="atualizar_quantidade">
                                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                            <input type="number" name="quantidade" value="<?php echo $item['qtd']; ?>" min="1">
                                            <button type="submit" style="padding: 5px 10px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer;">✓</button>
                                        </form>
                                    </td>
                                    <td>R$ <?php echo number_format($item['subtotal'], 2, ',', '.'); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="acao" value="remover">
                                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" class="btn-remover">✕</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="resumo-pedido">
                    <h3>Resumo do Pedido</h3>
                    <div class="resumo-linha">
                        <span>Subtotal:</span>
                        <span>R$ <?php echo number_format($total, 2, ',', '.'); ?></span>
                    </div>
                    <div class="resumo-linha">
                        <span>Frete:</span>
                        <span>R$ 0,00</span>
                    </div>
                    <div class="resumo-total">
                        Total: R$ <?php echo number_format($total, 2, ',', '.'); ?>
                    </div>
                    <a href="/checkout/index.php">
                        <button class="btn-checkout">💳 Finalizar Compra</button>
                    </a>
                    <a href="/catalogo/index.php">
                        <button class="btn-continuar">← Continuar Comprando</button>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>
</body>
</html>
