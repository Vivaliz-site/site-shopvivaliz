<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Política de trocas, devoluções e reembolsos da ShopVivaliz."><link rel="canonical" href="https://shopvivaliz.com.br/politica-devolucoes"><title>Trocas e Devoluções | <?= htmlspecialchars($fantasyName) ?></title>
<link rel="stylesheet" href="/css/style.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1"></head><body>
<?php $svNavCurrent = 'devolucoes'; include __DIR__ . '/includes/navbar.php'; ?>
<main class="legal-container"><header class="legal-header"><h1>Trocas e Devoluções</h1><p>Saiba como solicitar atendimento em caso de arrependimento, defeito, avaria ou item divergente.</p></header><section class="legal-body">
<div class="legal-box"><strong>Antes de enviar qualquer produto, fale com a equipe.</strong><br>Informe o número do pedido e o motivo da solicitação pelo telefone/WhatsApp <?= htmlspecialchars($phone) ?> ou pelo e-mail <!--email_off--><?= htmlspecialchars($email) ?><!--/email_off-->.</div>
<h2>1. Direito de arrependimento</h2><p>Nas compras realizadas pela internet, o consumidor pode exercer o direito de arrependimento em até <strong>7 dias corridos contados do recebimento</strong>, nos termos do artigo 49 do Código de Defesa do Consumidor. Nessa hipótese, não é necessário justificar o motivo da desistência.</p>
<h2>2. Produto com defeito, avaria ou divergência</h2><p>Ao identificar defeito, dano no transporte, quantidade incorreta ou produto diferente do pedido, interrompa o uso e entre em contato. Fotos da embalagem, etiqueta e item recebido ajudam na análise.</p>
<h2>3. Condições para devolução</h2><ul><li>O produto deve ser enviado com os acessórios e componentes recebidos.</li><li>Embale o item de forma segura para evitar novos danos.</li><li>Quando possível, mantenha embalagem, etiquetas e comprovante da compra.</li><li>O exercício do direito de arrependimento não depende de a embalagem permanecer inviolada.</li><li>Danos causados por uso inadequado, instalação incorreta ou modificação podem exigir análise específica.</li></ul>
<h2>4. Custo e forma de envio da devolução</h2><p>Para solicitar devolução, entre em contato antes do envio para receber as instruções corretas de postagem ou coleta. Quando a devolução ocorrer dentro do prazo legal de arrependimento de 7 dias, <strong>o consumidor não paga o frete de devolução</strong>. A ShopVivaliz fornecerá as orientações e, conforme a logística disponível para o pedido, a autorização de postagem, etiqueta ou procedimento de coleta sem custo ao consumidor.</p><p>Para defeito, avaria, divergência ou outra hipótese em que a legislação determine tratamento específico, a equipe orientará o procedimento aplicável e a forma de devolução devida. Não envie produtos sem autorização e instruções de atendimento.</p>
<h2>5. Análise e solução</h2><p>Após o recebimento, o produto poderá passar por conferência. Conforme o caso e a legislação aplicável, a solução poderá envolver troca, reparo, restituição do valor ou outra alternativa acordada com o consumidor.</p>
<h2>6. Reembolso</h2><p>No direito de arrependimento, os valores pagos no pedido são restituídos integralmente, observada a legislação aplicável. O reembolso é solicitado pelo mesmo meio de pagamento quando tecnicamente possível. O prazo de visualização após o processamento depende do banco, emissor do cartão ou intermediador de pagamento. A equipe informará o andamento após a aprovação da devolução.</p>
<h2>7. Atendimento</h2><p>Telefone/WhatsApp: <strong><?= htmlspecialchars($phone) ?></strong><br>E-mail: <strong><!--email_off--><?= htmlspecialchars($email) ?><!--/email_off--></strong></p>
<p class="policy-note">Esta página não elimina direitos previstos no Código de Defesa do Consumidor.</p>
</section></main><?php include __DIR__ . '/includes/footer.php'; ?></body></html>
