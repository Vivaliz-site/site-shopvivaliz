<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
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
    <title>Politica de Privacidade - <?= htmlspecialchars($fantasyName) ?></title>
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
<?php include __DIR__ . '/includes/header.php'; ?>
<div class="legal-page">
    <h1>Politica de Privacidade</h1>
    <div class="legal-intro">
        <p><strong>Ultima atualizacao:</strong> 12 de julho de 2026</p>
        <p>Esta Politica explica como a <?= htmlspecialchars($legalName) ?>, CNPJ <?= htmlspecialchars($cnpj) ?>, trata dados pessoais coletados no site <?= htmlspecialchars($fantasyName) ?>.</p>
    </div>

    <h2>1. Dados que podemos coletar</h2>
    <p>Podemos coletar dados informados diretamente por voce durante navegacao, cadastro, compra, atendimento ou contato com a loja, como:</p>
    <ul>
        <li>nome completo;</li>
        <li>CPF ou CNPJ, quando necessario para faturamento;</li>
        <li>e-mail, telefone e endereco de entrega;</li>
        <li>informacoes do pedido, historico de compras e interacoes com atendimento.</li>
    </ul>

    <h2>2. Finalidades do uso</h2>
    <p>Os dados sao usados para viabilizar a operacao da loja e a experiencia do cliente, incluindo:</p>
    <ul>
        <li>processamento, faturamento e entrega de pedidos;</li>
        <li>atualizacoes sobre pagamento, envio, cancelamento, troca ou devolucao;</li>
        <li>prevencao a fraude e seguranca operacional;</li>
        <li>cumprimento de obrigacoes legais e regulatorias;</li>
        <li>melhoria de navegacao, performance e atendimento.</li>
    </ul>

    <h2>3. Compartilhamento de dados</h2>
    <p>Os dados podem ser compartilhados apenas quando necessario com provedores de pagamento, plataformas de e-commerce, operadores logisticos, sistemas antifraude, emissores fiscais e parceiros tecnicos que apoiem a operacao da loja, sempre dentro da finalidade da compra ou de obrigacao legal.</p>

    <h2>4. Cookies e tecnologias semelhantes</h2>
    <p>Utilizamos cookies e recursos similares para manter sessoes, lembrar preferencias, medir navegacao, analisar desempenho e apoiar melhorias no site. Parte desses recursos pode depender das configuracoes do navegador do usuario.</p>

    <h2>5. Armazenamento e seguranca</h2>
    <p>Adotamos medidas tecnicas e administrativas razoaveis para reduzir risco de acesso nao autorizado, alteracao indevida, perda ou divulgacao inadequada de dados pessoais.</p>

    <h2>6. Direitos do titular</h2>
    <p>Nos termos da LGPD, voce pode solicitar confirmacao de tratamento, acesso, correcao, atualizacao ou esclarecimentos sobre seus dados pessoais, observadas as hipoteses legais de retencao.</p>

    <h2>7. Atendimento sobre privacidade</h2>
    <p>Solicitacoes relacionadas a privacidade, dados cadastrais ou exclusao devem ser encaminhadas pelos canais oficiais:</p>
    <ul>
        <li>E-mail: <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a></li>
        <li>Telefone: <a href="tel:<?= preg_replace('/\D/', '', $phone) ?>"><?= htmlspecialchars($phone) ?></a></li>
    </ul>

    <h2>8. Atualizacoes desta politica</h2>
    <p>Esta Politica pode ser revisada para refletir mudancas operacionais, tecnicas ou legais. A versao vigente sera sempre a publicada nesta pagina.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
