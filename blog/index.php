<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Conteudos da ShopVivaliz sobre catalogo, produtos, marketplace e operacao digital.">
    <title>Blog | ShopVivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .blog-shell {
            padding: 48px 0 64px;
        }
        .blog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
        }
        .post-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .post-card h1,
        .post-card h2 {
            color: #1F3A70;
            margin-bottom: 10px;
        }
        .post-card p {
            color: #475569;
            line-height: 1.7;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<main class="blog-shell">
    <div class="container">
        <section class="post-card" style="margin-bottom:18px;">
            <h1>Conteudos da loja</h1>
            <p>Espaco reservado para publicar novidades do catalogo, guias de compra e atualizacoes da operacao ShopVivaliz.</p>
        </section>
        <div class="blog-grid">
            <article class="post-card">
                <h2>Como organizar um catalogo mais confiavel</h2>
                <p>Produtos com imagem consistente, titulo objetivo e informacao clara convertem melhor e geram menos atrito na compra.</p>
            </article>
            <article class="post-card">
                <h2>Marketplace exige padrao</h2>
                <p>Padronizacao visual, SEO tecnico e operacao previsivel sao pontos centrais para escalar catalogo com menos retrabalho.</p>
            </article>
            <article class="post-card">
                <h2>Melhoria continua da operacao</h2>
                <p>A loja esta em processo de consolidacao de paginas, fluxos e integracoes para sair do estado experimental.</p>
            </article>
        </div>
    </div>
</main>
</body>
</html>
