<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap-env.php';
$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termos e Condições - <?= htmlspecialchars($fantasyName) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .legal-page { max-width: 900px; margin: 40px auto; padding: 40px 20px; line-height: 1.8; }
        .legal-page h1 { font-size: 32px; margin-bottom: 30px; }
        .legal-page h2 { font-size: 20px; margin-top: 40px; margin-bottom: 20px; }
        .legal-page p { margin-bottom: 15px; color: #333; }
        .legal-page ul { margin-bottom: 15px; margin-left: 20px; }
        .legal-page li { margin-bottom: 8px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>
    <div class="legal-page">
        <h1>Termos e Condições de Uso</h1>
        <p><strong>Última atualização:</strong> 11 de julho de 2026</p>
        <h2>1. Aceitar os Termos</h2>
        <p>Ao acessar e usar o website <?= htmlspecialchars($fantasyName) ?> você concorda em estar vinculado a estes Termos e Condições.</p>
        <h2>2. Descrição do Serviço</h2>
        <p>A Plataforma é um e-commerce que oferece produtos para venda online em todo o território nacional.</p>
        <h2>3. Usuário e Cadastro</h2>
        <p>Para realizar compras, é necessário criar uma conta com informações precisas. Você é responsável por manter a confidencialidade de sua senha.</p>
        <h2>4. Produtos e Preços</h2>
        <p>Todos os produtos, descrições e preços estão sujeitos a mudanças sem aviso prévio.</p>
        <h2>5. Processamento de Pedidos</h2>
        <p>A confirmação do pedido não garante que será aceito ou despachado. Reservamos o direito de recusar ou cancelar qualquer pedido.</p>
        <h2>6. Pagamento</h2>
        <p>Todos os pagamentos devem ser realizados através dos métodos oferecidos na Plataforma.</p>
        <h2>7. Frete e Entrega</h2>
        <p>O prazo de entrega é informado no momento da compra e pode variar conforme a localização.</p>
        <h2>8. Devoluções e Reembolsos</h2>
        <p>Consulte nossa Política de Trocas e Devoluções para informações completas.</p>
        <h2>9. Lei Aplicável</h2>
        <p>Estes Termos são regidos pelas leis da República Federativa do Brasil, especificamente pelo Código de Defesa do Consumidor.</p>
        <h2>10. Contactar-nos</h2>
        <p>Dúvidas? Entre em contato: <a href="mailto:atendimento@shopvivaliz.com.br">atendimento@shopvivaliz.com.br</a></p>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
