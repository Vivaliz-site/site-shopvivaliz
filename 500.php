<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Erro interno do servidor | Vivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .error-shell {
            min-height: 60vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 48px 24px;
        }
        .error-code {
            font-size: 88px;
            font-weight: 900;
            color: var(--primary, #2dbb57);
            line-height: 1;
            margin-bottom: 12px;
        }
        .error-shell h1 {
            margin: 0 0 12px;
            font-size: 26px;
        }
        .error-shell p {
            color: var(--muted, #64748b);
            max-width: 440px;
            margin: 0 auto 28px;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .error-actions .btn-primary {
            background: var(--primary, #2dbb57);
            color: #fff;
        }
        .error-actions .btn-outline {
            background: #fff;
            color: var(--dark, #173B63);
            border: 2px solid var(--dark, #173B63);
        }
    </style>
</head>
<body>
<?php $svNavCurrent = ''; include __DIR__ . '/includes/navbar.php'; ?>
<main class="error-shell">
    <div>
        <div class="error-code">500</div>
        <h1>Erro interno do servidor</h1>
        <p>Algo deu errado do nosso lado. Tenta novamente em alguns instantes, ou fala com a gente se o problema continuar.</p>
        <div class="error-actions">
            <a class="btn btn-primary" href="/catalogo">Ver catálogo</a>
            <a class="btn btn-outline" href="/">Voltar para a home</a>
        </div>
    </div>
</main>
</body>
</html>
