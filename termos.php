<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Consulte os Termos e Condições da ShopVivaliz para entender regras de compra, pagamento, entrega, uso do site, responsabilidades e atendimento."><link rel="canonical" href="https://shopvivaliz.com.br/termos/"><title>Termos e Condições | <?= htmlspecialchars($fantasyName) ?></title>
<link rel="stylesheet" href="/css/style.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1"></head><body>
<?php $svNavCurrent = 'termos'; include __DIR__ . '/includes/navbar.php'; ?>
<main class="legal-container"><header class="legal-header"><h1>Termos e Condições</h1><p>Regras para uso do site, cadastro, compra, pagamento, entrega e atendimento.</p></header><section class="legal-body">
<div class="legal-box"><strong><?= htmlspecialchars($legalName) ?></strong><br>CNPJ: <?= htmlspecialchars($cnpj) ?><br>E-mail: <!--email_off--><?= htmlspecialchars($email) ?><!--/email_off--><br>Telefone/WhatsApp: <?= htmlspecialchars($phone) ?></div>
<h2>1. Uso do site</h2><p>Ao navegar ou comprar na <?= htmlspecialchars($fantasyName) ?>, o usuário concorda com estes termos e com as políticas publicadas no site. O serviço deve ser utilizado de forma lícita e com informações verdadeiras.</p>
<h2>2. Cadastro e dados do pedido</h2><p>O cliente é responsável por conferir nome, documento, endereço, contato e demais dados antes de concluir a compra. Informações incorretas podem impedir faturamento, contato ou entrega.</p>
<h2>3. Produtos, preços e estoque</h2><p>Preços, disponibilidade, imagens e descrições são apresentados na página do produto e confirmados no carrinho. Erros materiais evidentes podem ser corrigidos antes da confirmação definitiva, com comunicação ao cliente e possibilidade de cancelamento e reembolso.</p>
<h2>4. Pagamento</h2><p>As formas de pagamento e eventual parcelamento disponíveis são as exibidas no checkout no momento da compra. A aprovação depende do meio de pagamento e de seus procedimentos de segurança.</p>
<h2>5. Cupons e campanhas</h2><p>Cupons, descontos e campanhas são temporários e seguem as condições informadas na própria oferta, incluindo validade, produtos elegíveis, quantidade de usos e possibilidade de encerramento.</p>
<h2>6. Entrega</h2><p>O valor e o prazo do frete são calculados no carrinho conforme CEP, produtos e transportadora disponível. Consulte a <a href="/politica-entrega">Política de Entrega</a>.</p>
<h2>7. Cancelamento, troca e devolução</h2><p>Solicitações são analisadas conforme o estágio do pedido, o Código de Defesa do Consumidor e a <a href="/politica-devolucoes">Política de Trocas e Devoluções</a>.</p>
<h2>8. Privacidade</h2><p>O tratamento de dados pessoais segue a <a href="/politica-privacidade/">Política de Privacidade</a>.</p>
<h2>9. Conteúdo e propriedade intelectual</h2><p>Marcas, textos, imagens, layout e demais conteúdos do site não podem ser reproduzidos ou explorados sem autorização, salvo usos permitidos por lei.</p>
<h2>10. Atendimento e alterações</h2><p>Dúvidas podem ser encaminhadas pelos canais informados acima. Estes termos podem ser atualizados para refletir mudanças legais ou operacionais; a versão válida é a publicada nesta página.</p>
<p class="policy-note">Última atualização: 28 de julho de 2026.</p>
</section></main><?php include __DIR__ . '/includes/footer.php'; ?></body></html>
