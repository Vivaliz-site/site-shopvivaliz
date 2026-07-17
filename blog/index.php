<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Conteudos da Vivaliz sobre catalogo, produtos, marketplace e operacao digital.">
    <title>Blog | Vivaliz</title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
        .brand-card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--accent-bg, #ecfdf5);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 14px;
        }
    </style>
</head>
<body>
<?php $svNavCurrent = 'blog'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page">
    <section class="brand-hero">
        <div class="container">
            <div class="brand-hero-card">
                <span class="brand-eyebrow">Conteúdos</span>
                <h1>Leitura curta e útil sobre catálogo, operação e apresentação de produtos.</h1>
                <p>O blog agora segue o mesmo padrão visual da marca para manter consistência em desktop e mobile.</p>
            </div>
        </div>
    </section>
    <div class="container">
        <section class="brand-section">
            <div class="brand-grid brand-grid-3">
                <article class="brand-card">
                    <div class="brand-card-icon">📸</div>
                    <h2>Como organizar um catálogo mais confiável</h2>
                    <p>Imagens consistentes, títulos objetivos e informação comercial clara ajudam a converter melhor.</p>
                </article>
                <article class="brand-card">
                    <div class="brand-card-icon">🎯</div>
                    <h2>Marketplace exige padrão</h2>
                    <p>Padronização visual, SEO técnico e operação previsível reduzem retrabalho e melhoram escala.</p>
                </article>
                <article class="brand-card">
                    <div class="brand-card-icon">⚡</div>
                    <h2>Melhoria contínua da operação</h2>
                    <p>Ajustes frequentes em navegação e apresentação deixam a experiência mais leve e profissional.</p>
                </article>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
