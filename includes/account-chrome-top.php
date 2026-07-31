<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($svAccountPageTitle ?? 'Minha Conta'); ?> - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/dazzle-v1.css?v=1.2.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        header.sv-account-header {
            background: #173b63;
            color: white;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        header.sv-account-header .logo { font-size: 22px; font-weight: bold; }
        header.sv-account-header .user-info { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        header.sv-account-header .user-info a { color: white; text-decoration: none; font-size: 14px; }
        header.sv-account-header .logout-btn { background: #d32f2f; padding: 8px 15px; border-radius: 4px; font-size: 12px; }
        header.sv-account-header .logout-btn:hover { background: #b71c1c; }

        .sv-account-shell {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 24px;
            align-items: start;
        }
        .sv-account-nav {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .sv-account-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            text-decoration: none;
            color: #333;
            font-size: 14px;
            border-bottom: 1px solid #f0f0f0;
        }
        .sv-account-nav a:last-child { border-bottom: none; }
        .sv-account-nav a:hover { background: #f7f9fc; }
        .sv-account-nav a.active { background: #173b63; color: white; font-weight: 600; }

        .sv-account-main {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 28px;
            min-height: 300px;
        }
        .sv-account-main h1 { font-size: 24px; margin-bottom: 6px; }
        .sv-account-main .sv-subtitle { color: #666; margin-bottom: 24px; font-size: 14px; }

        .sv-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: #173b63;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .sv-btn:hover { background: #0f2a47; }
        .sv-btn.secondary { background: #eef2f7; color: #173b63; }
        .sv-btn.secondary:hover { background: #dfe6ee; }
        .sv-btn.danger { background: #d32f2f; }
        .sv-btn.danger:hover { background: #b71c1c; }
        .sv-btn[disabled], .sv-btn.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

        .sv-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: white;
            border-radius: 50%;
            animation: sv-spin 0.7s linear infinite;
            vertical-align: middle;
        }
        @keyframes sv-spin { to { transform: rotate(360deg); } }

        .sv-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #173b63;
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            font-size: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            z-index: 9999;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.25s;
            pointer-events: none;
        }
        .sv-toast.show { opacity: 1; transform: translateY(0); }
        .sv-toast.error { background: #d32f2f; }

        @media (max-width: 768px) {
            .sv-account-shell { grid-template-columns: 1fr; }
            .sv-account-nav { display: flex; overflow-x: auto; }
            .sv-account-nav a { flex: 0 0 auto; border-bottom: none; border-right: 1px solid #f0f0f0; }
        }
    </style>
</head>
<body>
    <header class="sv-account-header">
        <div class="logo">ShopVivaliz</div>
        <div class="user-info">
            <div>
                <div style="font-size: 14px;">Olá, <?php echo htmlspecialchars($svAccountUser['name'] ?: 'Cliente'); ?></div>
                <div style="font-size: 12px; opacity: 0.8;"><?php echo htmlspecialchars($svAccountUser['email']); ?></div>
            </div>
            <a href="/">← Voltar à loja</a>
            <a href="/auth/logout.php" class="logout-btn">Sair</a>
        </div>
    </header>

    <div class="sv-account-shell">
        <nav class="sv-account-nav">
            <?php foreach (sv_account_nav_items() as $key => $item): ?>
                <a href="<?php echo htmlspecialchars($item['href']); ?>" class="<?php echo $key === ($svAccountActive ?? '') ? 'active' : ''; ?>">
                    <span><?php echo $item['icon']; ?></span> <?php echo htmlspecialchars($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <main class="sv-account-main">
