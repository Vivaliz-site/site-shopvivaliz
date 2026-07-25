<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=UTF-8');
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Política de Privacidade e Proteção de Dados da <?= htmlspecialchars($fantasyName) ?>: quais dados coletamos, para que usamos, com quem compartilhamos, por quanto tempo guardamos e como exercer seus direitos LGPD.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://dev.shopvivaliz.com.br/politica-privacidade/">
    <title>Política de Privacidade e LGPD | <?= htmlspecialchars($fantasyName) ?></title>
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
        .legal-body ul { padding-left: 20px; margin-bottom: 20px; }
        .legal-box { background: #f8fafc; border-left: 4px solid #35c759; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 20px 0; }
    </style>
</head>
<body>
<?php $svNavCurrent = 'privacidade'; include __DIR__ . '/../includes/navbar.php'; ?>

<div class="legal-container">
    <div class="legal-header">
        <h1>Política de Privacidade & Proteção de Dados (LGPD)</h1>
        <p>Transparência, segurança e respeito aos seus dados pessoais em todas as etapas da sua compra.</p>
    </div>

    <div class="legal-body">
        <div class="legal-box">
            <strong>Razão Social:</strong> <?= htmlspecialchars($legalName) ?><br>
            <strong>CNPJ:</strong> <?= htmlspecialchars($cnpj) ?><br>
            <strong>Canal de Privacidade (Encarregado/DPO):</strong> <?= htmlspecialchars($email) ?><br>
            <strong>Última atualização:</strong> 25 de julho de 2026
        </div>

        <h2>1. Introdução e Compromisso</h2>
        <p>A <strong><?= htmlspecialchars($fantasyName) ?></strong> compromete-se a proteger a privacidade e a segurança dos dados pessoais de todos os seus clientes e visitantes. Esta Política de Privacidade descreve como coletamos, armazenamos, utilizamos e protegemos suas informações de acordo com a Lei Geral de Proteção de Dados Pessoais (Lei nº 13.709/2018 - LGPD).</p>

        <h2>2. Dados Pessoais Coletados</h2>
        <p>Para viabilizar a entrega dos produtos e proporcionar uma experiência de compra ágil e segura, coletamos as seguintes categorias de dados:</p>
        <ul>
            <li><strong>Dados de Identificação:</strong> Nome completo, CPF ou CNPJ, e-mail e telefone/WhatsApp de contato.</li>
            <li><strong>Dados de Entrega:</strong> Endereço completo, CEP, número, complemento, bairro, cidade e estado.</li>
            <li><strong>Dados Financeiros e de Pagamento:</strong> Processados de forma 100% criptografada através de parceiros homologados (Mercado Pago / PIX / Cartões). A ShopVivaliz não armazena o número do seu cartão de crédito.</li>
            <li><strong>Dados de Navegação:</strong> Endereço IP, tipo de navegador, páginas visitadas e histórico de carrinho para prevenção a fraudes.</li>
        </ul>

        <h2>3. Finalidade do Tratamento dos Dados</h2>
        <p>Os seus dados são utilizados estritamente para as seguintes finalidades:</p>
        <ul>
            <li>Processamento, faturamento e emissão da Nota Fiscal Eletrônica (NF-e) do seu pedido.</li>
            <li>Envio e rastreamento da encomenda junto às transportadoras parceiras (Correios, Jadlog, etc.).</li>
            <li>Atendimento ao cliente, suporte comercial e atualização do status da compra via WhatsApp e E-mail.</li>
            <li>Cumprimento de obrigações legais e regulatórias fiscais.</li>
        </ul>

        <h2>4. Compartilhamento Seguro de Dados</h2>
        <p>Seus dados pessoais jamais serão vendidos ou comercializados. O compartilhamento ocorre exclusivamente com parceiros essenciais para a operação da loja:</p>
        <ul>
            <li><strong>Gateways de Pagamento:</strong> Mercado Pago para validação e liquidação das transações.</li>
            <li><strong>Empresas de Logística:</strong> Transportadoras parceiras para a entrega física no seu endereço.</li>
            <li><strong>Plataformas ERP e Fiscais:</strong> Para emissão de documentos fiscais obrigatórios por lei.</li>
        </ul>

        <h2>5. Base Legal para o Tratamento</h2>
        <p>Tratamos seus dados pessoais com fundamento nas seguintes hipóteses legais previstas no Art. 7º da LGPD, conforme a finalidade:</p>
        <ul>
            <li><strong>Execução de contrato:</strong> dados necessários para processar, faturar e entregar seu pedido.</li>
            <li><strong>Cumprimento de obrigação legal ou regulatória:</strong> emissão de nota fiscal e retenção de dados fiscais/contábeis pelo prazo exigido em lei.</li>
            <li><strong>Legítimo interesse:</strong> prevenção a fraudes, segurança da navegação e melhoria da experiência de compra.</li>
            <li><strong>Consentimento:</strong> quando aplicável, para comunicações de marketing e uso de cookies não essenciais (você pode retirar o consentimento a qualquer momento).</li>
        </ul>

        <h2>6. Direitos do Titular (Seus Direitos LGPD)</h2>
        <p>Nos termos do Art. 18 da LGPD, como titular dos dados você tem o direito de solicitar a qualquer momento:</p>
        <ul>
            <li>Confirmação da existência de tratamento e acesso aos seus dados.</li>
            <li>Correção de dados incompletos, inexatos ou desatualizados.</li>
            <li>Anonimização, bloqueio ou eliminação de dados desnecessários, excessivos ou tratados em desconformidade com a lei.</li>
            <li>Portabilidade dos dados a outro fornecedor de serviço ou produto, mediante requisição expressa.</li>
            <li>Eliminação dos dados pessoais tratados com o seu consentimento, salvo hipóteses legais de retenção (ex.: obrigações fiscais).</li>
            <li>Informação sobre as entidades públicas e privadas com as quais compartilhamos seus dados.</li>
            <li>Informação sobre a possibilidade de não fornecer consentimento e sobre as consequências dessa recusa.</li>
            <li>Revogação do consentimento, quando o tratamento tiver essa base legal.</li>
            <li>Oposição a tratamento realizado com base em hipótese legal diversa do consentimento, em caso de descumprimento da LGPD.</li>
        </ul>
        <p>Para exercer qualquer um desses direitos, entre em contato com nosso canal de privacidade pelo e-mail <strong><?= htmlspecialchars($email) ?></strong>. Sua solicitação será respondida em até 15 dias úteis, podendo ser prorrogado uma vez mediante justificativa.</p>

        <h2>7. Prazo de Retenção dos Dados</h2>
        <p>Mantemos seus dados pessoais pelo tempo necessário para cumprir as finalidades descritas nesta Política, incluindo o cumprimento de obrigações legais, fiscais e contábeis (em regra, 5 anos após a última transação, conforme legislação fiscal e o Código de Defesa do Consumidor), ou até que você solicite a eliminação de dados tratados apenas com base em consentimento.</p>

        <h2>8. Cookies e Tecnologias de Rastreamento</h2>
        <p>Utilizamos cookies próprios e de terceiros para: (i) cookies essenciais, necessários ao funcionamento do carrinho, login e checkout; (ii) cookies de desempenho, para medir navegação e identificar melhorias; e (iii) cookies de personalização/marketing, quando aplicável. Você pode gerenciar ou desabilitar cookies não essenciais diretamente nas configurações do seu navegador; a desativação de cookies essenciais pode afetar o funcionamento do site.</p>

        <h2>9. Segurança da Informação</h2>
        <p>Adotamos medidas técnicas e administrativas aptas a proteger os dados pessoais de acessos não autorizados e de situações acidentais ou ilícitas de destruição, perda, alteração, comunicação ou difusão, incluindo criptografia no processamento de pagamentos e controle de acesso restrito às informações armazenadas.</p>

        <h2>10. Alterações desta Política</h2>
        <p>Esta Política pode ser atualizada periodicamente para refletir mudanças legais, técnicas ou operacionais. Recomendamos a revisão periódica desta página; a data da última atualização está indicada no início deste documento.</p>

        <h2>11. Outras Políticas</h2>
        <p>Esta Política de Privacidade complementa os nossos <a href="/termos">Termos e Condições Gerais de Uso</a>, a <a href="/politica-devolucoes">Política de Trocas e Devoluções</a> e a <a href="/politica-entrega">Política de Frete e Entrega</a>.</p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
