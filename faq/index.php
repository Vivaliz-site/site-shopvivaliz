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
            <p>Reunimos as orientacoes principais para compra, pagamento e acompanhamento de pedido.</p>
        </section>
        <section class="faq-card">
            <h2>Como acompanho meu pedido?</h2>
            <p>Assim que o fluxo comercial estiver completo, o acompanhamento sera informado pelos canais de atendimento e pelos registros internos do pedido.</p>
        </section>
        <section class="faq-card">
            <h2>O frete ja e calculado na loja?</h2>
            <p>A estrutura de frete esta em evolucao. Em alguns fluxos o calculo ainda depende de integracao e validacao de CEP.</p>
        </section>
        <section class="faq-card">
            <h2>Posso pedir atendimento antes da compra?</h2>
            <p>Sim. Para duvidas de compatibilidade, estoque ou prazo, use a pagina de contato.</p>
        </section>
    </div>
</main>
</body>
</html>
