<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Acesso restrito | Vivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .error-shell{min-height:60vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:48px 24px}
        .error-code{font-size:88px;font-weight:900;color:var(--dark,#173b63);line-height:1;margin-bottom:12px}
        .error-shell h1{margin:0 0 12px;font-size:26px}
        .error-shell p{color:var(--muted,#64748b);max-width:500px;margin:0 auto 28px}
        .error-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
        .error-actions .btn-primary{background:var(--primary,#2dbb57);color:#fff}
        .error-actions .btn-outline{background:#fff;color:var(--dark,#173b63);border:2px solid var(--dark,#173b63)}
    </style>
</head>
<body>
<?php $svNavCurrent = ''; include __DIR__ . '/includes/navbar.php'; ?>
<main class="error-shell">
    <div>
        <div class="error-code">403</div>
        <h1>Área protegida</h1>
        <p>Este endereço não está disponível para acesso público. Nenhum dado foi exposto. Use uma das opções abaixo para continuar navegando com segurança.</p>
        <div class="error-actions">
            <a class="btn btn-primary" href="/catalogo">Ver catálogo</a>
            <a class="btn btn-outline" href="/">Voltar para a home</a>
        </div>
    </div>
</main>
</body>
</html>
