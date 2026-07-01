<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Entre em contato com a ShopVivaliz para suporte comercial, pedidos e atendimento.">
    <title>Contato | ShopVivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .page-shell {
            padding: 48px 0 64px;
        }
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }
        .contact-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .contact-card h1,
        .contact-card h2 {
            color: #1F3A70;
            margin-bottom: 12px;
        }
        .contact-card p {
            color: #475569;
            line-height: 1.7;
            margin-bottom: 10px;
        }
        .contact-card a {
            color: #1F3A70;
            font-weight: 600;
            text-decoration: none;
        }
        .contact-list {
            display: grid;
            gap: 10px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<main class="page-shell">
    <div class="container contact-grid">
        <section class="contact-card">
            <h1>Contato</h1>
            <p>Se precisar de ajuda com pedido, informacoes comerciais ou acompanhamento de compra, fale com a ShopVivaliz pelos canais abaixo.</p>
            <div class="contact-list">
                <p>E-mail: <a href="mailto:agentes@shopvivaliz.com.br">agentes@shopvivaliz.com.br</a></p>
                <p>Site de atendimento: <a href="https://dev.shopvivaliz.com.br">dev.shopvivaliz.com.br</a></p>
            </div>
        </section>
        <section class="contact-card">
            <h2>Horarios</h2>
            <p>Atendimento comercial em dias uteis, com retorno priorizado para pedidos, cotacoes e suporte de compra.</p>
            <p>As mensagens recebidas fora do horario comercial entram na fila para resposta no proximo ciclo util.</p>
        </section>
    </div>
</main>
</body>
</html>
