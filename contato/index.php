<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Entre em contato com a Vivaliz para suporte comercial, pedidos e atendimento.">
    <title>Contato | Vivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
</head>
<body>
<?php $svNavCurrent = 'contato'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page">
    <section class="brand-hero">
        <div class="container">
            <div class="brand-hero-card">
                <span class="brand-eyebrow">Contato Vivaliz</span>
                <h1>Atendimento direto para pedido, orçamento e suporte comercial.</h1>
                <p>Os canais abaixo concentram o atendimento da Vivaliz para acelerar respostas e orientar a compra sem ruído visual.</p>
            </div>
        </div>
    </section>
    <div class="container">
        <section class="brand-section">
            <div class="brand-grid brand-grid-2">
                <article class="brand-card">
                    <h2>Fale com a equipe</h2>
                    <p>E-mail: <a href="mailto:agentes@shopvivaliz.com.br">agentes@shopvivaliz.com.br</a></p>
                    <p>Portal de atendimento: <a href="https://dev.shopvivaliz.com.br">dev.shopvivaliz.com.br</a></p>
                    <p>Use este canal para apoio em pedidos, dúvidas de catálogo e acompanhamento comercial.</p>
                </article>
                <article class="brand-card">
                    <h2>Horário de operação</h2>
                    <p>Atendimento comercial em dias úteis, com priorização para cotações, suporte de compra e retorno operacional.</p>
                    <p>Mensagens fora do horário entram na fila e são respondidas no próximo ciclo útil.</p>
                </article>
            </div>
        </section>
    </div>
</main>
</body>
</html>
