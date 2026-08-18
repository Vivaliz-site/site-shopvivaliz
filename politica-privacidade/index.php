<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Política de Privacidade e Proteção de Dados (LGPD) da ShopVivaliz."><link rel="canonical" href="https://shopvivaliz.com.br/politica-privacidade/"><title>Privacidade e LGPD | <?= htmlspecialchars($fantasyName) ?></title>
<link rel="stylesheet" href="/css/style.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-2"></head><body>
<?php $svNavCurrent = 'privacidade'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="legal-container"><header class="legal-header"><h1>Política de Privacidade e Proteção de Dados (LGPD)</h1><p>Como coletamos, utilizamos, compartilhamos e protegemos dados pessoais.</p></header><section class="legal-body">
<div class="legal-box"><strong><?= htmlspecialchars($legalName) ?></strong><br>CNPJ: <?= htmlspecialchars($cnpj) ?><br>Canal para assuntos de privacidade e LGPD: <!--email_off--><?= htmlspecialchars($email) ?><!--/email_off--></div>
<h2>1. Dados que podem ser tratados</h2><p>Podemos tratar dados de identificação, contato, endereço, pedido, faturamento, atendimento, navegação e informações técnicas necessárias ao funcionamento e à segurança do site.</p>
<h2>2. Finalidades</h2><ul><li>Processar cadastro, compra, pagamento, faturamento e entrega.</li><li>Atender solicitações e informar o andamento de pedidos.</li><li>Prevenir fraude, abuso e incidentes de segurança.</li><li>Cumprir obrigações legais, fiscais, contábeis e regulatórias.</li><li>Medir e melhorar o funcionamento do site, respeitando as configurações aplicáveis de cookies.</li></ul>
<h2>3. Pagamentos</h2><p>Os pagamentos são processados por prestadores especializados. A <?= htmlspecialchars($fantasyName) ?> não precisa armazenar o número completo do cartão para concluir a compra.</p>
<h2>4. Compartilhamento</h2><p>Os dados podem ser compartilhados, na medida necessária, com meios de pagamento, plataformas de gestão, prestadores de tecnologia, serviços fiscais e transportadoras. Também poderão ser fornecidos quando houver obrigação legal ou ordem válida de autoridade competente.</p>
<h2>5. Bases legais</h2><p>O tratamento pode ocorrer para execução de contrato, cumprimento de obrigação legal ou regulatória, exercício regular de direitos, legítimo interesse, proteção do crédito e consentimento, conforme a finalidade e a legislação aplicável.</p>
<h2>6. Cookies</h2><p>O site pode utilizar cookies essenciais para sessão, carrinho, segurança e checkout, além de tecnologias de medição ou publicidade quando configuradas. O navegador permite bloquear ou excluir cookies, mas isso pode afetar funções essenciais.</p>
<h2>7. Segurança e retenção</h2><p>Adotamos medidas técnicas e administrativas proporcionais aos riscos. Os dados são mantidos pelo período necessário às finalidades informadas e aos prazos legais, fiscais, contábeis, de defesa de direitos e prevenção a fraudes.</p>
<h2>8. Direitos do titular</h2><p>Nos termos da LGPD, o titular pode solicitar confirmação de tratamento, acesso, correção, informação sobre compartilhamento e, quando aplicável, anonimização, bloqueio, eliminação, portabilidade, oposição ou revogação do consentimento. Algumas solicitações podem ser limitadas por obrigações legais de conservação.</p>
<h2>9. Como exercer seus direitos</h2><p>Envie a solicitação para <strong><!--email_off--><?= htmlspecialchars($email) ?><!--/email_off--></strong>. Para proteger o titular, poderemos pedir informações razoáveis de verificação de identidade.</p>
<h2>10. Atualizações</h2><p>Esta política pode ser atualizada para refletir mudanças legais, técnicas ou operacionais.</p>
<p class="policy-note">Última atualização: 28 de julho de 2026.</p>
</section></main><?php include __DIR__ . '/../includes/footer.php'; ?></body></html>
