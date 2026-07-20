<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Finalidade do app OAuth ShopVivaliz Google Ads API para leitura de metricas e acompanhamento de campanhas proprias.">
    <title>ShopVivaliz Google Ads API App</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="icon" type="image/png" href="/images/logo-vivaliz-square.png">
    <style>
        .app-purpose { max-width: 960px; margin: 40px auto; padding: 40px 20px; color: #1f2937; line-height: 1.75; }
        .app-purpose h1 { font-size: 34px; margin-bottom: 14px; color: #123b73; }
        .app-purpose h2 { font-size: 22px; margin-top: 32px; margin-bottom: 12px; color: #123b73; }
        .app-purpose p, .app-purpose li { margin-bottom: 12px; }
        .app-purpose ul { margin: 0 0 18px 22px; }
        .purpose-box { background: #f7fafc; border: 1px solid #dbe5ef; border-radius: 8px; padding: 22px; margin: 22px 0; }
        .purpose-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .purpose-card { background: #fff; border: 1px solid #dbe5ef; border-radius: 8px; padding: 18px; }
        .purpose-card strong { display: block; color: #123b73; margin-bottom: 8px; }
        @media (max-width: 760px) { .purpose-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="app-purpose">
    <h1>ShopVivaliz Google Ads API App</h1>
    <div class="purpose-box">
        <p><strong>Finalidade do aplicativo:</strong> este app OAuth e usado pela <?= htmlspecialchars($legalName, ENT_QUOTES, 'UTF-8') ?> para conectar ferramentas internas da loja a Google Ads API.</p>
        <p>O objetivo e acompanhar campanhas proprias da ShopVivaliz, ler metricas operacionais, auditar desempenho de anuncios e validar conversoes importadas do Google Analytics 4.</p>
    </div>

    <h2>Quem usa o app</h2>
    <p>O app e destinado somente a usuarios internos e operadores autorizados da <?= htmlspecialchars($fantasyName, ENT_QUOTES, 'UTF-8') ?>. Ele nao e oferecido ao publico, clientes finais ou terceiros como produto independente.</p>

    <h2>Como a Google Ads API e usada</h2>
    <div class="purpose-grid">
        <div class="purpose-card">
            <strong>Leitura de metricas</strong>
            <p>Consultar dados de campanhas proprias, como status, orcamento, cliques, custos, termos e desempenho agregado.</p>
        </div>
        <div class="purpose-card">
            <strong>Auditoria e otimizacao</strong>
            <p>Verificar configuracoes, acompanhar conversoes e apoiar decisoes de melhoria para campanhas da propria loja.</p>
        </div>
        <div class="purpose-card">
            <strong>Conversoes GA4</strong>
            <p>Apoiar a conferencia de conversoes importadas do Google Analytics 4 para metas comerciais da ShopVivaliz.</p>
        </div>
        <div class="purpose-card">
            <strong>Seguranca operacional</strong>
            <p>Manter tokens e credenciais em ambientes privados, com acesso restrito a operadores autorizados.</p>
        </div>
    </div>

    <h2>Dados tratados</h2>
    <p>O app pode acessar informacoes da conta Google Ads autorizada, incluindo identificadores de conta, configuracoes de campanhas, metricas agregadas, dados de custo e eventos de conversao disponiveis na propria plataforma Google Ads.</p>
    <p>O app nao vende dados, nao compartilha credenciais com terceiros e nao usa os dados para gerenciar contas de anunciantes externos.</p>

    <h2>Empresa responsavel</h2>
    <ul>
        <li>Razao social: <?= htmlspecialchars($legalName, ENT_QUOTES, 'UTF-8') ?></li>
        <li>CNPJ: <?= htmlspecialchars($cnpj, ENT_QUOTES, 'UTF-8') ?></li>
        <li>Site principal: <a href="https://shopvivaliz.com.br">https://shopvivaliz.com.br</a></li>
        <li>Politica de privacidade: <a href="/politica-privacidade">https://shopvivaliz.com.br/politica-privacidade</a></li>
        <li>Termos de uso: <a href="/termos">https://shopvivaliz.com.br/termos</a></li>
    </ul>

    <h2>Contato</h2>
    <p>Duvidas sobre o app OAuth, privacidade ou uso da API podem ser encaminhadas pelos canais oficiais:</p>
    <ul>
        <li>E-mail: <a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></a></li>
        <li>Telefone: <a href="tel:<?= preg_replace('/\D/', '', $phone) ?>"><?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?></a></li>
    </ul>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
