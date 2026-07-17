<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Conheça a Vivaliz: uma loja online de rodízios, ferragens e utilidades para casa, com catálogo organizado, atendimento rápido e entrega para todo o Brasil.">
    <title>Sobre | Vivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
</head>
<body>
<?php $svNavCurrent = 'sobre'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page">
    <section class="brand-hero">
        <div class="container">
            <div class="brand-hero-card">
                <span class="brand-eyebrow">Sobre a Vivaliz</span>
                <h1>Produtos para casa, entrega para todo o Brasil.</h1>
                <p>A Vivaliz é uma loja online de rodízios, ferragens e utilidades para casa. Organizamos o catálogo para você encontrar o que precisa rápido e comprar com segurança.</p>
                <div class="brand-hero-actions">
                    <a class="brand-btn" href="/catalogo">Ver catálogo</a>
                    <a class="brand-btn-secondary" href="/contato">Falar com a equipe</a>
                </div>
                <div class="brand-kpis">
                    <div class="brand-kpi"><strong>Catálogo organizado</strong><span>Produtos com fotos reais e descrições claras</span></div>
                    <div class="brand-kpi"><strong>Atendimento rápido</strong><span>Time pronto para tirar dúvidas antes e depois da compra</span></div>
                    <div class="brand-kpi"><strong>Entrega para todo o Brasil</strong><span>Frete calculado no carrinho, com prazo e valor exibidos antes de fechar o pedido</span></div>
                </div>
            </div>
        </div>
    </section>
    <div class="container">
        <section class="brand-section">
            <div class="brand-grid brand-grid-3">
        <article class="brand-card">
            <h2>Quem somos</h2>
            <p>Vendemos rodízios, ferragens, utilidades domésticas e itens para casa com fotos reais e informações claras sobre cada produto, para você comprar com confiança.</p>
        </article>
        <article class="brand-card">
            <h2>Como trabalhamos</h2>
            <ul class="brand-list">
                <li>Catálogo com fotos e informações atualizadas dos produtos.</li>
                <li>Atendimento pelo WhatsApp e e-mail, antes e depois da compra.</li>
                <li>Pagamento seguro via Pix, boleto ou cartão, com envio para todo o país.</li>
            </ul>
        </article>
        <article class="brand-card">
            <h2>Nosso compromisso</h2>
            <p>Queremos que comprar na Vivaliz seja simples: informação clara, atendimento de verdade e um processo de compra sem complicação, do carrinho até a entrega.</p>
        </article>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
