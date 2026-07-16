<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Conheca a Vivaliz, nossa proposta comercial e o compromisso com atendimento, catalogo confiavel e operacao em marketplace.">
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
                <h1>Uma loja com foco em clareza visual, confiança e venda assistida.</h1>
                <p>A Vivaliz organiza o catálogo para facilitar a escolha, valorizar a marca e reduzir atrito na jornada de compra em qualquer tela.</p>
                <div class="brand-hero-actions">
                    <a class="brand-btn" href="/catalogo">Ver catálogo</a>
                    <a class="brand-btn-secondary" href="/contato">Falar com a equipe</a>
                </div>
                <div class="brand-kpis">
                    <div class="brand-kpi"><strong>Curadoria visual</strong><span>Produtos e categorias com leitura mais objetiva</span></div>
                    <div class="brand-kpi"><strong>Atendimento rápido</strong><span>Contato comercial para apoiar a decisão de compra</span></div>
                    <div class="brand-kpi"><strong>Operação digital</strong><span>Estrutura preparada para crescer com consistência</span></div>
                </div>
            </div>
        </div>
    </section>
    <div class="container">
        <section class="brand-section">
            <div class="brand-grid brand-grid-3">
        <article class="brand-card">
            <h2>Nossa proposta</h2>
            <p>A Vivaliz organiza produtos com foco em marketplace, apresentacao clara e experiencia de compra objetiva. Nosso trabalho combina curadoria de catalogo, estrutura comercial e melhoria continua da operacao digital.</p>
        </article>
        <article class="brand-card">
            <h2>Como operamos</h2>
            <ul class="brand-list">
                <li>Catalogo estruturado com imagens e informacoes comerciais consistentes.</li>
                <li>Atendimento direcionado para venda online e pos-venda responsivo.</li>
                <li>Integracao progressiva com ERP, marketplace e automacoes internas.</li>
            </ul>
        </article>
        <article class="brand-card">
            <h2>Compromisso</h2>
            <p>Nosso objetivo e transformar a loja em uma base solida de venda, com menos improviso visual e mais previsibilidade operacional. Cada pagina precisa ser util, clara e alinhada com a identidade da marca.</p>
        </article>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
