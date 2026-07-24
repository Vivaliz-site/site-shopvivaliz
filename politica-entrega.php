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
    <meta name="description" content="Política oficial de Envio, Frete Grátis e Prazos de Entrega da ShopVivaliz para todo o Brasil.">
    <title>Política de Frete e Entrega | <?= htmlspecialchars($fantasyName) ?></title>
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
        .legal-box { background: #eff6ff; border-left: 4px solid #0b4f88; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 20px 0; color: #1e40af; }
    </style>
</head>
<body>
<?php $svNavCurrent = 'entrega'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="legal-container">
    <div class="legal-header">
        <h1>Política de Frete e Entrega</h1>
        <p>Logística ágil, rastreamento em tempo real e entrega garantida em todo o território nacional.</p>
    </div>

    <div class="legal-body">
        <div class="legal-box">
            🚚 <strong>FRETE GRÁTIS:</strong> Válido para todo o Brasil em compras acima de <strong>R$ 199,00</strong>.<br>
            📦 <strong>Prazo de Despacho:</strong> Envio em até 24 horas úteis após a confirmação do pagamento.
        </div>

        <h2>1. Prazos e Modos de Envio</h2>
        <p>A <strong><?= htmlspecialchars($fantasyName) ?></strong> realiza envios para todas as regiões do Brasil por meio de transportadoras parceiras homologadas (Correios, Jadlog, Loggi, etc.). O prazo final de entrega é calculado no momento do checkout com base no seu CEP.</p>

        <h2>2. Regras para Frete Grátis</h2>
        <ul>
            <li>Aplicado automaticamente no carrinho para pedidos com valor total igual ou superior a <strong>R$ 199,00</strong>.</li>
            <li>A modalidade de frete grátis é enviada via opção padrão econômica (PAC ou Transportadora Regional).</li>
            <li>Caso deseje um envio expresso (Sedex), o valor diferencial poderá ser selecionado na finalização da compra.</li>
        </ul>

        <h2>3. Rastreamento do Pedido</h2>
        <p>Assim que o pedido for despachado em nosso centro de distribuição, você receberá por e-mail e WhatsApp o código de rastreamento oficial para acompanhar o deslocamento da mercadoria passo a passo em tempo real.</p>

        <h2>4. Tentativas de Entrega</h2>
        <p>As transportadoras realizam até <strong>3 (três) tentativas de entrega</strong> no endereço informado. Certifique-se de que haverá alguém responsável no local para receber a encomenda.</p>

        <h2>5. Dúvidas Logísticas</h2>
        <p>Caso precise alterar alguma informação ou tenha dúvidas sobre o trânsito da sua encomenda, entre em contato imediatamente com nossa central de atendimento:</p>
        <ul>
            <li>💬 WhatsApp: <strong><?= htmlspecialchars($phone) ?></strong></li>
            <li>✉️ E-mail: <strong><?= htmlspecialchars($email) ?></strong></li>
        </ul>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
