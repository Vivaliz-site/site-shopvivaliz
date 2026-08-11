<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$root = dirname(__DIR__);
$cssDir = $root . '/storage/css-custom';
$message = '';
$messageType = 'success';

if (!is_dir($cssDir) && !@mkdir($cssDir, 0755, true) && !is_dir($cssDir)) {
    $message = 'Não foi possível preparar a pasta de CSS customizado.';
    $messageType = 'error';
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

$page = isset($_GET['page']) ? basename((string)$_GET['page']) : 'global';
if (!array_key_exists($page, $pages)) {
    $page = 'global';
}

$cssFile = $cssDir . '/' . $page . '.css';
$cssContent = is_file($cssFile) ? (string)file_get_contents($cssFile) : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $css = (string)($_POST['css'] ?? '');
        if (!is_dir($cssDir) || @file_put_contents($cssFile, $css, LOCK_EX) === false) {
            $message = 'Não foi possível salvar o CSS. Verifique a permissão da pasta de armazenamento.';
            $messageType = 'error';
        } else {
            $cssContent = $css;
            $message = 'CSS salvo com sucesso para: ' . $pages[$page];
        }
    } elseif ($action === 'reset') {
        if (is_file($cssFile) && !@unlink($cssFile)) {
            $message = 'Não foi possível remover o CSS customizado.';
            $messageType = 'error';
        } else {
            $cssContent = '';
            $message = 'CSS resetado para: ' . $pages[$page];
        }
    }
}

$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Editor CSS — ShopVivaliz Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f4f6fb;color:#263238;min-height:100vh}
        .container{width:min(1400px,calc(100% - 28px));margin:0 auto;padding:22px 0 40px}
        header{background:#173b63;color:#fff;padding:20px;border-radius:14px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;box-shadow:0 8px 24px rgba(23,59,99,.16)}
        header h1{font-size:clamp(22px,5vw,28px)}
        header a{color:#fff;text-decoration:none;padding:9px 14px;background:rgba(255,255,255,.16);border-radius:8px;font-size:14px;font-weight:700}
        .layout{display:grid;grid-template-columns:minmax(210px,250px) minmax(0,1fr);gap:20px;align-items:start}
        .sidebar,.main{background:#fff;padding:20px;border-radius:14px;border:1px solid #e5e9f0;box-shadow:0 6px 22px rgba(17,24,39,.06)}
        .sidebar h3{font-size:13px;text-transform:uppercase;color:#64748b;margin-bottom:12px;letter-spacing:.04em}
        .sidebar ul{list-style:none;display:grid;gap:6px}
        .sidebar a{display:block;padding:10px 12px;text-decoration:none;color:#334155;border-radius:8px;font-size:14px;border-left:3px solid transparent}
        .sidebar a:hover{background:#f1f5f9;border-left-color:#173b63}.sidebar a.active{background:#e8f0f7;border-left-color:#173b63;color:#173b63;font-weight:800}
        .main h2{margin-bottom:18px;font-size:19px;color:#173b63;overflow-wrap:anywhere}
        .message{padding:12px 14px;border-radius:9px;margin-bottom:18px;font-weight:700}.message.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.message.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
        .editor-wrapper{display:flex;flex-direction:column;gap:14px}
        textarea{width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;padding:15px;border:1px solid #cbd5e1;border-radius:9px;background:#f8fafc;color:#1e293b;line-height:1.55;resize:vertical;min-height:430px}
        textarea:focus{outline:none;border-color:#173b63;box-shadow:0 0 0 3px rgba(23,59,99,.1)}
        .actions{display:flex;gap:10px;flex-wrap:wrap}button{padding:11px 18px;border:0;border-radius:8px;font-size:14px;font-weight:800;cursor:pointer}.btn-save{background:#15803d;color:#fff}.btn-reset{background:#b91c1c;color:#fff}
        .info{background:#eff6ff;border-left:4px solid #2563eb;padding:13px 15px;border-radius:8px;font-size:13px;color:#1e40af;overflow-wrap:anywhere}.info strong{display:inline-block;margin-top:5px}
        @media(max-width:760px){.container{width:min(100% - 20px,1400px);padding-top:10px}.layout{grid-template-columns:1fr}.sidebar{padding:14px}.sidebar ul{grid-template-columns:repeat(2,minmax(0,1fr))}.sidebar a{height:100%}.main{padding:16px}textarea{min-height:330px}.actions button{flex:1 1 150px}}
        @media(max-width:430px){.sidebar ul{grid-template-columns:1fr}header{padding:16px}.container{width:min(100% - 14px,1400px)}}
    </style>
</head>
<body>
<div class="container">
    <header>
        <div><h1>✏️ Editor CSS</h1><p>Customizações por área do site</p></div>
        <a href="/admin/">← Voltar ao Admin</a>
    </header>

    <div class="layout">
        <nav class="sidebar" aria-label="Páginas para edição de CSS">
            <h3>Páginas</h3>
            <ul>
                <?php foreach ($pages as $key => $label): ?>
                    <li><a href="?page=<?= $esc($key) ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= $esc($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <main class="main">
            <?php if ($message !== ''): ?><div class="message <?= $esc($messageType) ?>"><?= $esc($message) ?></div><?php endif; ?>
            <h2>CSS: <?= $esc($pages[$page]) ?></h2>
            <form method="post" class="editor-wrapper">
                <textarea name="css" spellcheck="false" aria-label="CSS customizado" placeholder="/* Escreva seu CSS customizado aqui */"><?= $esc($cssContent) ?></textarea>
                <div class="actions">
                    <button type="submit" name="action" value="save" class="btn-save">💾 Salvar CSS</button>
                    <button type="submit" name="action" value="reset" class="btn-reset" onclick="return confirm('Tem certeza que deseja resetar o CSS desta página?');">↺ Resetar</button>
                </div>
                <div class="info"><strong>Arquivo:</strong> <code><?= $esc($page) ?>.css</code><br><strong>Status:</strong> <?= is_file($cssFile) ? 'CSS customizado ativo' : 'Usando CSS padrão' ?></div>
            </form>
        </main>
    </div>
</div>
</body>
</html>
