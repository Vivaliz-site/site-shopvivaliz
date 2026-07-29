<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="description" content="Dúvidas frequentes sobre pedidos, pagamento, entrega, troca e atendimento da ShopVivaliz."><title>Dúvidas frequentes | ShopVivaliz</title><link rel="stylesheet" href="/css/responsive.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1"></head><body>
<?php $svNavCurrent = 'faq'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page"><section class="brand-hero"><div class="container"><div class="brand-hero-card"><span class="brand-eyebrow">Ajuda</span><h1>Dúvidas frequentes</h1><p>Informações claras sobre compra, pagamento, entrega, troca e atendimento.</p></div></div></section>
<div class="container"><section class="brand-section"><div class="faq-list">
<details><summary>Como acompanho meu pedido?</summary><p>Consulte a área “Minha Conta &gt; Meus Pedidos”. Quando houver rastreamento disponível, o código ou link também poderá ser enviado pelos canais cadastrados.</p></details>
<details><summary>Como o frete é calculado?</summary><p>O valor e o prazo são calculados no carrinho conforme o CEP, os produtos e as transportadoras disponíveis. Não há campanha permanente de frete grátis.</p></details>
<details><summary>Quais formas de pagamento estão disponíveis?</summary><p>As opções válidas, incluindo eventual parcelamento, são exibidas no checkout no momento da compra.</p></details>
<details><summary>Como solicitar troca ou devolução?</summary><p>Entre em contato informando o número do pedido e o motivo. Consulte também a <a href="/politica-devolucoes">Política de Trocas e Devoluções</a>.</p></details>
<details><summary>O site é seguro?</summary><p>O site utiliza conexão HTTPS e os pagamentos são processados por prestadores especializados. Nunca envie senha ou código de confirmação por mensagem.</p></details>
<details><summary>Posso tirar dúvidas antes de comprar?</summary><p>Sim. Use a página de <a href="/contato">contato</a> para falar com a equipe sobre compatibilidade, estoque, entrega ou pedido.</p></details>
</div></section></div></main><?php include __DIR__ . '/../includes/footer.php'; ?></body></html>
