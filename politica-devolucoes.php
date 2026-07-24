<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Politica de Trocas e Devolucoes - <?= htmlspecialchars($fantasyName) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .legal-page { max-width: 920px; margin: 40px auto; padding: 40px 20px; line-height: 1.8; color: #1f2937; }
        .legal-page h1 { font-size: 32px; margin-bottom: 12px; }
        .legal-page h2 { margin-top: 32px; margin-bottom: 14px; color: #123b73; }
        .legal-page p, .legal-page li { margin-bottom: 12px; }
        .legal-page ul, .legal-page ol { margin: 0 0 18px 22px; }
        .legal-page .legal-intro { background: #f7fafc; border: 1px solid #e5edf5; border-radius: 8px; padding: 20px; margin-bottom: 26px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>
<div class="legal-page">
    <h1>Politica de Trocas e Devolucoes</h1>
    <div class="legal-intro">
        <p><strong>Ultima atualizacao:</strong> 12 de julho de 2026</p>
        <p>A <?= htmlspecialchars($fantasyName) ?> atende solicitacoes de troca, devolucao e reembolso conforme a legislacao aplicavel e as condicoes desta pagina.</p>
    </div>

    <h2>1. Arrependimento da compra</h2>
    <p>Para compras online, o cliente pode solicitar devolucao por arrependimento em ate 7 dias corridos apos o recebimento do pedido, desde que o produto seja devolvido com seus acessorios, sem sinais de uso inadequado e em condicoes compativeis com a analise do retorno.</p>

    <h2>2. Situacoes atendidas</h2>
    <ul>
        <li>produto com defeito de fabricacao;</li>
        <li>item avariado no transporte;</li>
        <li>produto divergente do pedido;</li>
        <li>arrependimento dentro do prazo legal.</li>
    </ul>

    <h2>3. Como solicitar</h2>
    <ol>
        <li>Entre em contato pelos canais oficiais da loja.</li>
        <li>Informe numero do pedido, nome do comprador e motivo da solicitacao.</li>
        <li>Quando necessario, envie fotos do item, embalagem e etiqueta.</li>
        <li>Aguarde as orientacoes de coleta, postagem ou devolucao assistida.</li>
    </ol>

    <h2>4. Analise e aprovacao</h2>
    <p>Depois que o produto retornar ou as evidencias forem avaliadas, a solicitacao sera analisada pela equipe de atendimento. Em caso de improcedencia por mau uso, dano nao relacionado ao envio ou ausencia dos itens essenciais para avaliacao, a devolucao podera ser recusada.</p>

    <h2>5. Reembolso ou troca</h2>
    <p>Quando a devolucao for aprovada, o cliente podera receber reembolso, estorno conforme o meio de pagamento utilizado ou, quando aplicavel, substituicao por outro item equivalente. O prazo operacional informado pela loja para processamento do reembolso e de ate 10 dias uteis apos a confirmacao da devolucao aprovada.</p>

    <h2>6. Frete de retorno</h2>
    <p>Nos casos de defeito, avaria ou erro de expedicao confirmado, a loja orientara a logistica reversa sem custo para o cliente. Em pedidos cancelados por arrependimento, a tratativa seguira a analise do caso e as regras legais aplicaveis ao comercio eletronico.</p>

    <h2>7. Atendimento</h2>
    <ul>
        <li>E-mail: <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a></li>
        <li>Telefone: <a href="tel:<?= preg_replace('/\D/', '', $phone) ?>"><?= htmlspecialchars($phone) ?></a></li>
    </ul>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
