<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Perguntas frequentes sobre pedidos, pagamento, frete e atendimento da ShopVivaliz.">
    <title>FAQ | ShopVivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .faq-shell {
            padding: 48px 0 64px;
        }
        .faq-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
            margin-bottom: 16px;
        }
        .faq-card h1,
        .faq-card h2 {
            color: #1F3A70;
            margin-bottom: 10px;
        }
        .faq-card p {
            color: #475569;
            line-height: 1.7;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<main class="faq-shell">
    <div class="container">
        <section class="faq-card">
            <h1>Perguntas frequentes</h1>
            <p>Reunimos as orientações principais para compra, pagamento, frete e acompanhamento de pedido.</p>
        </section>
        <section class="faq-card">
            <h2>Como acompanho meu pedido?</h2>
            <p>Após a confirmação do pedido, nossa equipe informa o andamento pelos canais de atendimento cadastrados na compra.</p>
        </section>
        <section class="faq-card">
            <h2>Como funciona o frete?</h2>
            <p>O valor e o prazo são calculados conforme o CEP de entrega e a disponibilidade do item no momento da compra.</p>
        </section>
        <section class="faq-card">
            <h2>Posso pedir atendimento antes da compra?</h2>
            <p>Sim. Para dúvidas de compatibilidade, estoque ou prazo, use a página de contato e fale com a equipe da Vivaliz.</p>
        </section>
    </div>
</main>
</body>
</html>
