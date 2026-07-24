<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Termos e Condições Gerais de Uso do site ShopVivaliz.">
    <title>Termos e Condições | <?= htmlspecialchars($fantasyName) ?></title>
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
        .legal-box { background: #f8fafc; border-left: 4px solid #0b4f88; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 20px 0; }
    </style>
</head>
<body>
<?php $svNavCurrent = 'termos'; include __DIR__ . '/includes/navbar.php'; ?>

<div class="legal-container">
    <div class="legal-header">
        <h1>Termos e Condições Gerais de Uso</h1>
        <p>Regras de navegação, segurança, compra e direitos do usuário na plataforma ShopVivaliz.</p>
    </div>

    <div class="legal-body">
        <div class="legal-box">
            <strong>Razão Social:</strong> <?= htmlspecialchars($legalName) ?><br>
            <strong>CNPJ:</strong> <?= htmlspecialchars($cnpj) ?><br>
            <strong>E-mail:</strong> <?= htmlspecialchars($email) ?><br>
            <strong>Atendimento WhatsApp:</strong> <?= htmlspecialchars($phone) ?>
        </div>

        <h2>1. Aceitação dos Termos</h2>
        <p>Ao acessar e realizar compras na loja online da <strong><?= htmlspecialchars($fantasyName) ?></strong>, o usuário concorda integralmente com as condições descritas nestes Termos de Uso e nas demais políticas institucionais divulgadas no site.</p>

        <h2>2. Cadastro e Responsabilidade do Usuário</h2>
        <p>O cliente responsabiliza-se pela veracidade e exatidão dos dados informados no momento do cadastro ou checkout (nome, CPF/CNPJ, endereço e e-mail). Dados incorretos podem impossibilitar a emissão da Nota Fiscal Eletrônica (NF-e) e a entrega correta do pedido.</p>

        <h2>3. Preços, Estoque e Condições de Pagamento</h2>
        <ul>
            <li>Todos os preços e ofertas divulgados no site são válidos apenas para compras realizadas diretamente na loja online.</li>
            <li>Aceitamos pagamentos via PIX (com aprovação imediata), Boleto Bancário e Cartão de Crédito em até 12x.</li>
            <li>Compras via cupom promocional (como <strong>VOLTEI5</strong>) possuem regras descritas na própria campanha promocional.</li>
        </ul>

        <h2>4. Propriedade Intelectual</h2>
        <p>Todo o conteúdo do site (marcas, logotipos, textos, fotografias de produtos, layouts e códigos) é de propriedade exclusiva da <strong><?= htmlspecialchars($legalName) ?></strong>, protegido pelas leis brasileiras de propriedade industrial e direitos autorais.</p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
