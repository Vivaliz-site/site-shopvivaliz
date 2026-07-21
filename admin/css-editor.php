<?php
session_start();
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: /admin/login.php');
    exit;
}

$root = dirname(__DIR__);
$cssDir = $root . '/storage/css-custom';
if (!is_dir($cssDir)) {
    mkdir($cssDir, 0755, true);
}

$page = isset($_GET['page']) ? basename($_GET['page']) : 'global';
$cssFile = $cssDir . '/' . $page . '.css';
$cssContent = is_file($cssFile) ? file_get_contents($cssFile) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $css = $_POST['css'] ?? '';
        file_put_contents($cssFile, $css);
        $message = "✓ CSS salvo com sucesso para: $page";
    } elseif ($action === 'reset') {
        if (is_file($cssFile)) {
            unlink($cssFile);
        }
        $cssContent = '';
        $message = "✓ CSS resetado para: $page";
    }
}

$pages = [
    'global' => 'Global (todas as páginas)',
    'index' => 'Home',
    'catalogo' => 'Catálogo/Produtos',
    'produto' => 'Detalhe Produto',
    'carrinho' => 'Carrinho',
    'checkout' => 'Checkout',
    'login' => 'Login',
    'admin' => 'Admin',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor CSS - Admin | Vivaliz</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background: #173B63;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 { font-size: 24px; }
        header a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            font-size: 14px;
        }
        header a:hover { background: rgba(255,255,255,0.3); }

        .layout { display: grid; grid-template-columns: 250px 1fr; gap: 20px; }

        .sidebar {
            background: white;
            padding: 20px;
            border-radius: 8px;
            height: fit-content;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .sidebar h3 {
            font-size: 14px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .sidebar ul {
            list-style: none;
        }
        .sidebar li {
            margin-bottom: 8px;
        }
        .sidebar a {
            display: block;
            padding: 10px;
            text-decoration: none;
            color: #333;
            border-radius: 4px;
            font-size: 14px;
            border-left: 3px solid transparent;
        }
        .sidebar a:hover {
            background: #f0f0f0;
            border-left-color: #173B63;
        }
        .sidebar a.active {
            background: #e8f0f7;
            border-left-color: #173B63;
            color: #173B63;
            font-weight: 600;
        }

        .main {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .main h2 {
            margin-bottom: 20px;
            font-size: 18px;
            color: #173B63;
        }

        .message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .editor-wrapper {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        textarea {
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #f8f8f8;
            color: #333;
            line-height: 1.5;
            resize: vertical;
            min-height: 400px;
        }
        textarea:focus {
            outline: none;
            border-color: #173B63;
            background: #fafafa;
            box-shadow: 0 0 0 3px rgba(23, 59, 99, 0.1);
        }

        .actions {
            display: flex;
            gap: 10px;
        }
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-save {
            background: #28a745;
            color: white;
        }
        .btn-save:hover { background: #218838; }

        .btn-reset {
            background: #dc3545;
            color: white;
        }
        .btn-reset:hover { background: #c82333; }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover { background: #5a6268; }

        .info {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 12px 15px;
            border-radius: 4px;
            font-size: 13px;
            color: #1565c0;
            margin-top: 15px;
        }
        .info strong { display: block; margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>✏️ Editor CSS</h1>
            <a href="/admin/">← Voltar ao Admin</a>
        </header>

        <div class="layout">
            <div class="sidebar">
                <h3>Páginas</h3>
                <ul>
                    <?php foreach ($pages as $key => $label): ?>
                        <li>
                            <a href="?page=<?= $key ?>"
                               class="<?= $page === $key ? 'active' : '' ?>">
                                <?= htmlspecialchars($label) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="main">
                <?php if (isset($message)): ?>
                    <div class="message"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <h2>
                    CSS: <?= htmlspecialchars($pages[$page] ?? $page) ?>
                </h2>

                <form method="POST" class="editor-wrapper">
                    <textarea name="css" placeholder="/* Escreva seu CSS customizado aqui */"><?= htmlspecialchars($cssContent) ?></textarea>

                    <div class="actions">
                        <button type="submit" name="action" value="save" class="btn-save">
                            💾 Salvar CSS
                        </button>
                        <button type="submit" name="action" value="reset" class="btn-reset"
                                onclick="return confirm('Tem certeza que deseja resetar o CSS desta página?');">
                            🔄 Resetar
                        </button>
                    </div>

                    <div class="info">
                        <strong>📍 Arquivo:</strong>
                        <code><?= $page ?>.css</code>
                        <br>
                        <strong>⚡ Status:</strong>
                        <?= is_file($cssFile) ? 'CSS customizado ativo' : 'Usando CSS padrão' ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
