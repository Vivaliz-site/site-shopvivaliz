<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$redirectStatus = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);
$currentStatus = http_response_code();
$status = in_array($redirectStatus, [500, 502, 503], true)
    ? $redirectStatus
    : (in_array($currentStatus, [500, 502, 503], true) ? $currentStatus : 500);

$copy = match ($status) {
    502 => [
        'title' => 'Serviço externo indisponível',
        'heading' => 'Uma integração não respondeu',
        'message' => 'A loja continua protegida, mas um serviço externo não respondeu corretamente. Tente novamente em alguns instantes.',
    ],
    503 => [
        'title' => 'Loja temporariamente indisponível',
        'heading' => 'Voltamos em instantes',
        'message' => 'Estamos realizando uma recuperação rápida ou manutenção. Nenhum pedido será processado de forma incompleta.',
    ],
    default => [
        'title' => 'Erro interno do servidor',
        'heading' => 'Algo deu errado do nosso lado',
        'message' => 'A operação foi interrompida com segurança. Tente novamente em alguns instantes ou fale com a equipe se o problema continuar.',
    ],
};

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
http_response_code($status);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= htmlspecialchars($copy['title'], ENT_QUOTES, 'UTF-8') ?> | Vivaliz</title>
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
            max-width: 520px;
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
        <div class="error-code"><?= $status ?></div>
        <h1><?= htmlspecialchars($copy['heading'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($copy['message'], ENT_QUOTES, 'UTF-8') ?></p>
        <div class="error-actions">
            <a class="btn btn-primary" href="/catalogo">Ver catálogo</a>
            <a class="btn btn-outline" href="/">Voltar para a home</a>
        </div>
    </div>
</main>
</body>
</html>
