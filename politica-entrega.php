<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Politica de Frete - <?= htmlspecialchars($fantasyName) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .legal-page { max-width: 920px; margin: 40px auto; padding: 40px 20px; line-height: 1.8; color: #1f2937; }
        .legal-page h1 { font-size: 32px; margin-bottom: 12px; }
        .legal-page h2 { margin-top: 32px; margin-bottom: 14px; color: #123b73; }
        .legal-page p, .legal-page li { margin-bottom: 12px; }
        .legal-page ul { margin: 0 0 18px 22px; }
        .legal-page .legal-intro { background: #f7fafc; border: 1px solid #e5edf5; border-radius: 8px; padding: 20px; margin-bottom: 26px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>
<div class="legal-page">
    <h1>Politica de Frete</h1>
    <div class="legal-intro">
        <p><strong>Ultima atualizacao:</strong> 12 de julho de 2026</p>
        <p>A <?= htmlspecialchars($fantasyName) ?> realiza entregas para todo o Brasil, com prazo e custo calculados conforme CEP, peso, volume, disponibilidade e modalidade selecionada no checkout.</p>
    </div>

    <h2>1. Cobertura e calculo</h2>
    <p>O valor do frete e o prazo estimado sao exibidos antes da finalizacao da compra. As condicoes podem variar de acordo com a regiao, dimensoes do produto, transportadora e consolidacao logistica.</p>

    <h2>2. Prazo de entrega</h2>
    <p>O prazo passa a contar apos a confirmacao de pagamento e aprovacao do pedido. Datas estimadas podem sofrer ajuste por fatores externos, como indisponibilidade temporaria da transportadora, restricoes regionais, eventos climaticos ou tentativas frustradas de entrega.</p>

    <h2>3. Rastreamento</h2>
    <p>Quando houver codigo de rastreio ou atualizacao equivalente, o cliente podera acompanhar a expedicao pelos meios informados na operacao do pedido.</p>

    <h2>4. Recebimento</h2>
    <p>Recomendamos verificar a embalagem e o produto no ato da entrega. Em caso de avaria aparente, divergencia de item ou violacao da embalagem, registre a ocorrencia e entre em contato com a loja o quanto antes.</p>

    <h2>5. Tentativas, reentrega e dados incorretos</h2>
    <p>Problemas causados por endereco incompleto, destinatario ausente, recusa injustificada ou erro cadastral podem gerar novo prazo e eventual cobranca adicional de frete para reenvio.</p>

    <h2>6. Atendimento sobre entrega</h2>
    <p>Duvidas sobre envio, prazo ou rastreio podem ser encaminhadas para <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
