<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
define('APP_NAME', 'ShopVivaliz');
define('BASE_URL', 'https://dev.shopvivaliz.com.br');

session_start();

// Dados de teste
$produtos_db = [
    1 => ['id' => 1, 'nome' => 'Camiseta Premium', 'preco' => 79.90, 'categoria' => 'Roupas', 'imagem' => '👕', 'descricao' => 'Camiseta de alta qualidade, 100% algodão', 'estoque' => 50],
    2 => ['id' => 2, 'nome' => 'Calça Jeans', 'preco' => 149.90, 'categoria' => 'Roupas', 'imagem' => '👖', 'descricao' => 'Calça jeans azul clássica', 'estoque' => 30],
    3 => ['id' => 3, 'nome' => 'Tênis Esportivo', 'preco' => 199.90, 'categoria' => 'Calçados', 'imagem' => '👟', 'descricao' => 'Tênis para corrida e esportes', 'estoque' => 25],
    4 => ['id' => 4, 'nome' => 'Relógio Digital', 'preco' => 89.90, 'categoria' => 'Acessórios', 'imagem' => '⌚', 'descricao' => 'Relógio digital com múltiplas funções', 'estoque' => 40],
    5 => ['id' => 5, 'nome' => 'Mochila Impermeável', 'preco' => 129.90, 'categoria' => 'Acessórios', 'imagem' => '🎒', 'descricao' => 'Mochila impermeável ideal para viagens', 'estoque' => 35],
    6 => ['id' => 6, 'nome' => 'Jaqueta de Couro', 'preco' => 299.90, 'categoria' => 'Roupas', 'imagem' => '🧥', 'descricao' => 'Jaqueta de couro genuína', 'estoque' => 15],
];

$id = $_GET['id'] ?? 0;
$produto = $produtos_db[$id] ?? null;

if (!$produto) {
    header('HTTP/1.0 404 Not Found');
    echo 'Produto não encontrado';
    exit;
}

// Adicionar ao carrinho
if ($_POST['acao'] === 'add_carrinho') {
    $qtd = (int)$_POST['quantidade'] ?? 1;
    if (!isset($_SESSION['carrinho'])) {
        $_SESSION['carrinho'] = [];
    }
    if (isset($_SESSION['carrinho'][$id])) {
        $_SESSION['carrinho'][$id] += $qtd;
    } else {
        $_SESSION['carrinho'][$id] = $qtd;
    }
    $msg_sucesso = "Produto adicionado ao carrinho!";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - <?php echo htmlspecialchars($produto['nome']); ?></title>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .produto-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 30px 0;
        }
        @media (max-width: 768px) {
            .produto-container {
                grid-template-columns: 1fr;
            }
        }
        .produto-imagem {
            font-size: 8em;
            text-align: center;
            background: #f5f7fa;
            padding: 40px;
            border-radius: 8px;
        }
        .produto-info h2 {
            font-size: 1.8em;
            color: #1f2937;
            margin: 20px 0;
        }
        .produto-preco {
            font-size: 2.5em;
            color: #667eea;
            font-weight: bold;
            margin: 20px 0;
        }
        .produto-estoque {
            color: #10b981;
            font-weight: 600;
            margin: 10px 0;
        }
        .produto-descricao {
            color: #666;
            line-height: 1.6;
            margin: 20px 0;
        }
        .form-adicionar {
            background: #f5f7fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .form-group {
            margin: 15px 0;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100px;
            padding: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }
        .btn-carrinho {
            background: #667eea;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: 600;
            width: 100%;
            margin-top: 15px;
        }
        .btn-carrinho:hover {
            background: #764ba2;
        }
        .msg-sucesso {
            background: #dcfce7;
            color: #166534;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <main class="container">
        <?php if (isset($msg_sucesso)): ?>
            <div class="msg-sucesso">✅ <?php echo $msg_sucesso; ?></div>
        <?php endif; ?>

        <div class="produto-container">
            <div class="produto-imagem">
                <?php echo $produto['imagem']; ?>
            </div>
            <div class="produto-info">
                <h2><?php echo htmlspecialchars($produto['nome']); ?></h2>
                <div class="produto-preco">
                    R$ <?php echo number_format($produto['preco'], 2, ',', '.'); ?>
                </div>
                <div class="produto-estoque">
                    ✓ <?php echo $produto['estoque']; ?> em estoque
                </div>
                <p class="produto-descricao">
                    <?php echo htmlspecialchars($produto['descricao']); ?>
                </p>

                <form method="POST" class="form-adicionar">
                    <input type="hidden" name="acao" value="add_carrinho">
                    <div class="form-group">
                        <label for="quantidade">Quantidade:</label>
                        <input type="number" id="quantidade" name="quantidade" value="1" min="1" max="<?php echo $produto['estoque']; ?>">
                    </div>
                    <button type="submit" class="btn-carrinho">
                        🛒 Adicionar ao Carrinho
                    </button>
                </form>

                <a href="/catalogo/index.php" style="display: inline-block; margin-top: 20px; color: #667eea;">
                    ← Voltar ao Catálogo
                </a>
            </div>
        </div>
    </main>
    <footer>
        <div class="container">
            <p>&copy; 2026 ShopVivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>
</body>
</html>