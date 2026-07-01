<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
define('APP_NAME', 'ShopVivaliz');

session_start();

if (empty($_SESSION['carrinho'])) {
    header('Location: /carrinho/index.php');
    exit;
}

// Processar pedido
$pedido_criado = false;
$acao = $_POST['acao'] ?? '';
if ($acao === 'finalizar_pedido') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    $numero = trim($_POST['numero'] ?? '');
    $complemento = trim($_POST['complemento'] ?? '');
    $cidade = trim($_POST['cidade'] ?? '');
    $cep = trim($_POST['cep'] ?? '');

    if ($nome && $email && $telefone && $endereco && $numero && $cidade && $cep) {
        // Criar registro de pedido em arquivo
        $logs_dir = __DIR__ . '/../logs';
        if (!is_dir($logs_dir)) {
            mkdir($logs_dir, 0755, true);
        }

        $pedido = [
            'id' => 'PED-' . date('YmdHis'),
            'timestamp' => date('c'),
            'cliente' => [
                'nome' => $nome,
                'email' => $email,
                'telefone' => $telefone,
            ],
            'endereco' => [
                'logradouro' => $endereco,
                'numero' => $numero,
                'complemento' => $complemento,
                'cidade' => $cidade,
                'cep' => $cep,
            ],
            'itens' => $_SESSION['carrinho'],
            'status' => 'pendente_pagamento',
        ];

        file_put_contents(
            $logs_dir . '/pedidos.jsonl',
            json_encode($pedido, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );

        $pedido_criado = true;
        $pedido_id = $pedido['id'];
        unset($_SESSION['carrinho']);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Checkout</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #ffffff 0%, #f7f9fc 100%);
        }
        .checkout-container {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 30px;
            margin: 30px 0;
        }
        @media (max-width: 768px) {
            .checkout-container {
                grid-template-columns: 1fr;
            }
        }
        .formulario {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .form-section {
            margin-bottom: 25px;
        }
        .form-section h3 {
            color: #1F3A70;
            margin-bottom: 15px;
            font-size: 1.1em;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .form-row.full {
            grid-template-columns: 1fr;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        .form-group input,
        .form-group textarea {
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-family: inherit;
            font-size: 0.95em;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1F3A70;
            box-shadow: 0 0 0 3px rgba(31, 58, 112, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }
        .resumo-pedido {
            background: #f5f7fa;
            padding: 20px;
            border-radius: 8px;
            height: fit-content;
        }
        .resumo-item {
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.95em;
        }
        .resumo-total {
            font-size: 1.3em;
            font-weight: bold;
            color: #1F3A70;
            margin: 15px 0;
            text-align: right;
        }
        .btn-finalizar {
            background: #1F3A70;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            font-size: 1.05em;
            font-weight: 600;
            margin-top: 20px;
        }
        .btn-finalizar:hover {
            background: #667eea;
        }
        .mensagem-sucesso {
            background: #dcfce7;
            border: 2px solid #10b981;
            color: #166534;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }
        .mensagem-sucesso h2 {
            margin: 10px 0;
        }
        .pedido-id {
            background: #f3f4f6;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 1.1em;
            margin: 10px 0;
            word-break: break-all;
        }
        .prox-passos {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <main class="container">
        <h1>Finalizar Compra</h1>

        <?php if ($pedido_criado): ?>
            <div class="mensagem-sucesso">
                <h2>✓ Pedido Recebido com Sucesso!</h2>
                <p>Seu pedido foi registrado em nosso sistema.</p>
                <div class="pedido-id"><?php echo htmlspecialchars($pedido_id); ?></div>
                <p>Um e-mail de confirmação será enviado para você em breve.</p>
                <div class="prox-passos">
                    <strong>Próximos passos:</strong>
                    <ul style="margin-top: 10px; text-align: left;">
                        <li>Você receberá um e-mail com os dados de pagamento</li>
                        <li>Após confirmação de pagamento, seu pedido será processado</li>
                        <li>Você receberá atualizações sobre o envio</li>
                    </ul>
                </div>
                <a href="/catalogo/index.php" style="display: inline-block; margin-top: 20px; background: #1F3A70; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none;">
                    ← Continuar Comprando
                </a>
            </div>
        <?php else: ?>
            <div class="checkout-container">
                <form method="POST" class="formulario">
                    <input type="hidden" name="acao" value="finalizar_pedido">

                    <div class="form-section">
                        <h3>Dados Pessoais</h3>
                        <div class="form-row full">
                            <div class="form-group">
                                <label for="nome">Nome Completo *</label>
                                <input type="text" id="nome" name="nome" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">E-mail *</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label for="telefone">Telefone *</label>
                                <input type="tel" id="telefone" name="telefone" placeholder="(11) 99999-9999" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Endereço de Entrega</h3>
                        <div class="form-row full">
                            <div class="form-group">
                                <label for="endereco">Rua/Avenida *</label>
                                <input type="text" id="endereco" name="endereco" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="numero">Número *</label>
                                <input type="text" id="numero" name="numero" required>
                            </div>
                            <div class="form-group">
                                <label for="complemento">Complemento</label>
                                <input type="text" id="complemento" name="complemento" placeholder="Apto, sala, etc">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cidade">Cidade *</label>
                                <input type="text" id="cidade" name="cidade" required>
                            </div>
                            <div class="form-group">
                                <label for="cep">CEP *</label>
                                <input type="text" id="cep" name="cep" placeholder="00000-000" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Pagamento</h3>
                        <p style="color: #666; margin-bottom: 15px;">
                            Você receberá um e-mail com as opções de pagamento após confirmar este pedido.
                        </p>
                        <div class="form-row full">
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                    <input type="checkbox" required style="width: auto;">
                                    Concordo com os termos e condições
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-finalizar">
                        Confirmar Pedido
                    </button>
                </form>

                <div class="resumo-pedido">
                    <h3 style="color: #1F3A70; margin-bottom: 15px;">Resumo do Pedido</h3>
                    <?php
                    $produtos_db = [
                        1 => ['id' => 1, 'nome' => 'Camiseta Premium', 'preco' => 79.90],
                        2 => ['id' => 2, 'nome' => 'Calça Jeans', 'preco' => 149.90],
                        3 => ['id' => 3, 'nome' => 'Tênis Esportivo', 'preco' => 199.90],
                        4 => ['id' => 4, 'nome' => 'Relógio Digital', 'preco' => 89.90],
                        5 => ['id' => 5, 'nome' => 'Mochila Impermeável', 'preco' => 129.90],
                        6 => ['id' => 6, 'nome' => 'Jaqueta de Couro', 'preco' => 299.90],
                    ];

                    $total = 0;
                    foreach ($_SESSION['carrinho'] as $id => $qtd) {
                        if (isset($produtos_db[$id])) {
                            $p = $produtos_db[$id];
                            $subtotal = $p['preco'] * $qtd;
                            $total += $subtotal;
                            echo '<div class="resumo-item">';
                            echo htmlspecialchars($p['nome']) . ' x ' . $qtd;
                            echo ' <span style="float: right;">R$ ' . number_format($subtotal, 2, ',', '.') . '</span>';
                            echo '</div>';
                        }
                    }
                    ?>
                    <div class="resumo-total">
                        Total: R$ <?php echo number_format($total, 2, ',', '.'); ?>
                    </div>
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
