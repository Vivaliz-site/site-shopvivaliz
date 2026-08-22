<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Consulte a política de entrega da ShopVivaliz: cálculo do frete, prazos, rastreamento, tentativas de entrega e orientações para receber seu pedido com segurança.">
<link rel="canonical" href="https://shopvivaliz.com.br/politica-entrega/"><title>Política de Entrega | <?= htmlspecialchars($fantasyName) ?></title>
<link rel="stylesheet" href="/css/style.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1">
</head><body>
<?php $svNavCurrent = 'entrega'; include __DIR__ . '/includes/navbar.php'; ?>
<main class="legal-container">
<header class="legal-header"><h1>Política de Entrega</h1><p>Veja como o frete é calculado, como acompanhar o pedido e quais cuidados tomar no recebimento.</p></header>
<section class="legal-body">
<h2>1. Cálculo do frete e prazo</h2><p>Informe o CEP no carrinho para consultar as modalidades disponíveis, seus valores e as estimativas de entrega. As opções podem variar conforme o produto, o destino e a transportadora disponível.</p>
<h2>2. Preparação e despacho</h2><p>Após a confirmação do pagamento, o pedido entra em separação e preparação. A estimativa apresentada no checkout considera o processamento do pedido e o transporte. Não prometemos despacho em prazo fixo quando ele não estiver informado no próprio pedido.</p>
<h2>3. Rastreamento</h2><p>Quando a transportadora disponibilizar o rastreio, o código ou link será enviado pelos canais cadastrados e poderá aparecer na área de pedidos. Algumas modalidades atualizam o rastreamento em etapas.</p>
<h2>4. Endereço e recebimento</h2><p>Confira endereço, número, complemento e contato antes de concluir a compra. O cliente deve garantir que haja pessoa apta a receber a encomenda. Quantidade de tentativas, pontos de retirada e procedimentos de devolução seguem as regras da transportadora selecionada.</p>
<h2>5. Atrasos, avarias e ocorrências</h2><p>Em caso de atraso, embalagem violada, avaria ou divergência, registre a ocorrência e fale com a equipe informando o número do pedido. Sempre que possível, fotografe a embalagem e o produto antes do uso.</p>
<h2>6. Atendimento</h2><p>WhatsApp/telefone: <strong><?= htmlspecialchars($phone) ?></strong><br>E-mail: <strong><!--email_off--><?= htmlspecialchars($email) ?><!--/email_off--></strong></p>
<p class="policy-note">As condições válidas para cada compra são as apresentadas no carrinho e no pedido no momento da contratação.</p>
</section></main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body></html>
