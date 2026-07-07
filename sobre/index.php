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
    <style>
        .page-hero {
            background: linear-gradient(135deg, #1F3A70 0%, #2ECC71 130%);
            color: white;
            padding: 56px 0 44px;
        }
        .page-hero h1 {
            font-size: 34px;
            margin-bottom: 12px;
        }
        .page-shell {
            padding: 36px 0 56px;
        }
        .content-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
            margin-bottom: 18px;
        }
        .content-card h2 {
            color: #1F3A70;
            margin-bottom: 10px;
        }
        .content-card p,
        .content-card li {
            color: #475569;
            line-height: 1.7;
        }
        .content-card ul {
            padding-left: 18px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<section class="page-hero">
    <div class="container">
        <h1>Vivaliz</h1>
        <p>Operacao focada em catalogo confiavel, boa apresentacao e atendimento direto para venda online.</p>
    </div>
</section>
<main class="page-shell">
    <div class="container">
        <section class="content-card">
            <h2>Nossa proposta</h2>
            <p>A Vivaliz organiza produtos com foco em marketplace, apresentacao clara e experiencia de compra objetiva. Nosso trabalho combina curadoria de catalogo, estrutura comercial e melhoria continua da operacao digital.</p>
        </section>
        <section class="content-card">
            <h2>Como operamos</h2>
            <ul>
                <li>Catalogo estruturado com imagens e informacoes comerciais consistentes.</li>
                <li>Atendimento direcionado para venda online e pos-venda responsivo.</li>
                <li>Integracao progressiva com ERP, marketplace e automacoes internas.</li>
            </ul>
        </section>
        <section class="content-card">
            <h2>Compromisso</h2>
            <p>Nosso objetivo e transformar a loja em uma base solida de venda, com menos improviso visual e mais previsibilidade operacional. Cada pagina precisa ser util, clara e alinhada com a identidade da marca.</p>
        </section>
    </div>
</main>
</body>
</html>
