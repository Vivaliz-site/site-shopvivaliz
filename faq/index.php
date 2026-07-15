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
</head>
<body>
<?php $svNavCurrent = 'faq'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page">
    <section class="brand-hero">
        <div class="container">
            <div class="brand-hero-card">
                <span class="brand-eyebrow">FAQ</span>
                <h1>Respostas objetivas para compra, frete e atendimento.</h1>
                <p>O conteúdo abaixo foi reorganizado para leitura mais rápida, principalmente no mobile.</p>
            </div>
        </div>
    </section>
    <div class="container">
        <section class="brand-section">
            <div class="brand-grid">
                <article class="brand-card">
                    <h2>Como acompanho meu pedido?</h2>
                    <p>Após a confirmação, a equipe informa o andamento pelos canais cadastrados durante a compra.</p>
                </article>
                <article class="brand-card">
                    <h2>Como funciona o frete?</h2>
                    <p>O prazo e o valor variam conforme o CEP de destino e a disponibilidade do item no momento do pedido.</p>
                </article>
                <article class="brand-card">
                    <h2>Posso pedir atendimento antes da compra?</h2>
                    <p>Sim. Em caso de dúvidas sobre compatibilidade, estoque ou prazo, use a página de contato para falar com a equipe.</p>
                </article>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
