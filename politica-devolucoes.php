<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Política oficial de Trocas e Devoluções da ShopVivaliz. Saiba como solicitar sua troca em até 7 dias sem burocracia.">
    <title>Política de Trocas e Devoluções | <?= htmlspecialchars($fantasyName) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .legal-container { max-width: 900px; margin: 40px auto; padding: 0 20px; font-family: Inter, system-ui, sans-serif; color: #1e293b; line-height: 1.8; }
        .legal-header { background: linear-gradient(135deg, #0b4f88, #07345d); color: #ffffff; padding: 40px 32px; border-radius: 16px; margin-bottom: 32px; box-shadow: 0 10px 30px rgba(11,79,136,0.15); }
        .legal-header h1 { font-size: 28px; font-weight: 800; margin-bottom: 8px; color: #ffffff; }
        .legal-header p { font-size: 14px; color: rgba(255,255,255,0.85); margin: 0; }
        .legal-body { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
        .legal-body h2 { font-size: 20px; font-weight: 700; color: #0b4f88; margin-top: 32px; margin-bottom: 12px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }
        .legal-body h2:first-of-type { margin-top: 0; }
        .legal-body p, .legal-body li { font-size: 15px; color: #334155; margin-bottom: 14px; }
        .legal-body ul, .legal-body ol { padding-left: 20px; margin-bottom: 20px; }
        .legal-box { background: #ecfdf5; border-left: 4px solid #35c759; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 20px 0; color: #065f46; }
    </style>
</head>
<body>
<?php $svNavCurrent = 'devolucoes'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="legal-container">
    <div class="legal-header">
        <h1>Política de Trocas e Devoluções</h1>
        <p>Garantia de 7 dias sem burocracia para você comprar com 100% de tranquilidade.</p>
    </div>

    <div class="legal-body">
        <div class="legal-box">
            <strong>Atendimento Rápido para Troca ou Devolução:</strong><br>
            💬 WhatsApp: <strong><?= htmlspecialchars($phone) ?></strong><br>
            ✉️ E-mail: <strong><?= htmlspecialchars($email) ?></strong><br>
            ⏱️ Prazo de resposta: até 24 horas úteis.
        </div>

        <h2>1. Direito de Arrependimento (7 Dias CDC)</h2>
        <p>Conforme estabelecido pelo Artigo 49 do Código de Defesa do Consumidor (CDC), você tem até <strong>7 (sete) dias corridos</strong> após o recebimento do produto para solicitar a devolução ou troca por arrependimento, sem qualquer custo adicional.</p>

        <h2>2. Condições para Troca ou Devolução</h2>
        <p>Para que o retorno seja aprovado, o item devolvido deve cumprir os seguintes critérios simples:</p>
        <ul>
            <li>Estar acompanhado da Nota Fiscal Eletrônica (NF-e) ou declaração de conteúdo.</li>
            <li>Estar em sua embalagem original ou embalagem segura para transporte.</li>
            <li>Não apresentar marcas de mau uso, instalações incorretas ou avarias causadas por terceiros.</li>
        </ul>

        <h2>3. Passos para Solicitar sua Devolução</h2>
        <ol>
            <li>Entre em contato com nosso atendimento pelo WhatsApp <strong><?= htmlspecialchars($phone) ?></strong> ou e-mail <strong><?= htmlspecialchars($email) ?></strong> informando o número do seu pedido e o motivo.</li>
            <li>Nossa equipe gerará um <strong>código de postagem reversa gratuito</strong> dos Correios ou transportadora.</li>
            <li>Leve o produto embalado até a agência mais próxima e apresente o código de autorização.</li>
            <li>Assim que o produto chegar ao nosso centro de distribuição e passar pela conferência (até 2 dias úteis), realizaremos o reembolso integral ou o envio do novo item.</li>
        </ol>

        <h2>4. Formas de Reembolso</h2>
        <ul>
            <li><strong>Pagamentos via PIX:</strong> Reembolso efetuado diretamente na mesma conta bancária em até 24 horas úteis.</li>
            <li><strong>Pagamentos via Cartão de Crédito:</strong> Estorno solicitado imediatamente junto à administradora do cartão (o crédito poderá ser visualizado em até 2 faturas subsequentes, conforme regras da emissora).</li>
            <li><strong>Pagamentos via Boleto Bancário:</strong> Depósito/transferência em conta corrente de titularidade do comprador.</li>
        </ul>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
