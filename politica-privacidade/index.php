<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Politica de privacidade da ShopVivaliz para tratamento de dados, contato e operacao da loja.">
    <title>Privacidade | ShopVivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .policy-shell {
            padding: 48px 0 64px;
        }
        .policy-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .policy-card h1,
        .policy-card h2 {
            color: #1F3A70;
            margin-bottom: 12px;
        }
        .policy-card p {
            color: #475569;
            line-height: 1.75;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<main class="policy-shell">
    <div class="container">
        <section class="policy-card">
            <h1>Politica de privacidade</h1>
            <p>A ShopVivaliz utiliza os dados informados pelo cliente para atendimento comercial, processamento de pedidos e comunicacoes relacionadas a compra.</p>
            <h2>Uso de informacoes</h2>
            <p>As informacoes sao utilizadas para contato, verificacao de pedido, operacao logistica e melhoria dos processos da loja.</p>
            <h2>Compartilhamento</h2>
            <p>Os dados podem ser tratados por ferramentas operacionais e integracoes necessarias para viabilizar a venda e o atendimento, sempre dentro do escopo comercial da loja.</p>
            <h2>Solicitacoes</h2>
            <p>Para solicitar correcao de informacoes ou esclarecer qualquer tratamento de dados, utilize a pagina de contato.</p>
        </section>
    </div>
</main>
</body>
</html>
