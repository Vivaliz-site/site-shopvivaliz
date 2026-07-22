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
    <link rel="stylesheet" href="/css/style.css">
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
                    <p>Após a confirmação do pagamento, você recebe atualizações de status (preparação, envio e entrega) pelo e-mail e telefone cadastrados na compra. Também é possível consultar o andamento diretamente em "Minha Conta &gt; Meus Pedidos".</p>
                </article>
                <article class="brand-card">
                    <h2>Como funciona o frete?</h2>
                    <p>O prazo e o valor são calculados no carrinho a partir do seu CEP e são exibidos antes da finalização da compra. Enviamos para todo o Brasil.</p>
                </article>
                <article class="brand-card">
                    <h2>Quais formas de pagamento vocês aceitam?</h2>
                    <p>Aceitamos Pix, boleto bancário e cartão de crédito via Mercado Pago, com processamento seguro e criptografado.</p>
                </article>
                <article class="brand-card">
                    <h2>Como faço para trocar ou devolver um produto?</h2>
                    <p>Você tem até 30 dias corridos após o recebimento para solicitar troca ou devolução. Confira as condições completas na <a href="/politica-devolucoes">Política de Trocas e Devoluções</a>.</p>
                </article>
                <article class="brand-card">
                    <h2>O site é seguro para comprar?</h2>
                    <p>Sim. Todas as compras são processadas em ambiente com conexão criptografada (SSL) e checkout protegido via Mercado Pago.</p>
                </article>
                <article class="brand-card">
                    <h2>Posso pedir atendimento antes da compra?</h2>
                    <p>Sim. Em caso de dúvidas sobre compatibilidade, estoque ou prazo, use a página de <a href="/contato">contato</a> para falar com a equipe pelo WhatsApp ou e-mail.</p>
                </article>
            </div>
        </section>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
