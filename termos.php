<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap-env.php';

$company = @include(__DIR__ . '/config/company-profile.php') ?: [];
$fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
$city = $company['city'] ?? 'Divinopolis';
$state = $company['state'] ?? 'MG';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termos e Condicoes - <?= htmlspecialchars($fantasyName) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .legal-page { max-width: 920px; margin: 40px auto; padding: 40px 20px; line-height: 1.8; color: #1f2937; }
        .legal-page h1 { font-size: 32px; margin-bottom: 12px; }
        .legal-page h2 { font-size: 21px; margin-top: 34px; margin-bottom: 14px; color: #123b73; }
        .legal-page p, .legal-page li { margin-bottom: 12px; }
        .legal-page ul { margin: 0 0 18px 22px; }
        .legal-page .legal-intro { background: #f7fafc; border: 1px solid #e5edf5; border-radius: 8px; padding: 20px; margin-bottom: 26px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>
    <div class="legal-page">
        <h1>Termos e Condicoes</h1>
        <div class="legal-intro">
            <p><strong>Ultima atualizacao:</strong> 12 de julho de 2026</p>
            <p>Estes Termos disciplinam o uso do site <?= htmlspecialchars($fantasyName) ?> e as compras realizadas em nossa loja online, operada por <?= htmlspecialchars($legalName) ?>, CNPJ <?= htmlspecialchars($cnpj) ?>.</p>
        </div>

        <h2>1. Sobre a loja</h2>
        <p>A <?= htmlspecialchars($fantasyName) ?> atua no comercio eletronico com foco em utilidades, organizacao, casa, jardim, ferramentas e categorias relacionadas apresentadas no storefront oficial da marca.</p>

        <h2>2. Aceitacao dos termos</h2>
        <p>Ao navegar no site, realizar cadastro ou concluir um pedido, voce declara que leu e concorda com estes Termos, com a Politica de Privacidade e com a Politica de Trocas, Devolucoes e Entrega.</p>

        <h2>3. Cadastro e responsabilidade do cliente</h2>
        <p>O cliente deve informar dados corretos, completos e atualizados para compra, faturamento, entrega e atendimento. O uso indevido de dados de terceiros ou informacoes falsas pode levar ao cancelamento do pedido.</p>

        <h2>4. Produtos, imagens e disponibilidade</h2>
        <p>Buscamos apresentar fotos, descricoes e caracteristicas dos produtos com clareza. Ainda assim, pequenas variacoes visuais podem ocorrer conforme lote, iluminacao, tela utilizada pelo cliente ou atualizacoes do fabricante.</p>
        <p>A disponibilidade de estoque, o sortimento e as condicoes comerciais podem ser ajustados sem aviso previo, sempre respeitando pedidos ja confirmados quando houver viabilidade operacional.</p>

        <h2>5. Precos e formas de pagamento</h2>
        <p>Os precos exibidos no site valem para compras online e podem ser alterados a qualquer momento antes da conclusao do pedido. A aprovacao do pagamento e feita pelos meios disponibilizados na plataforma, incluindo modalidades divulgadas no ecossistema oficial da loja, como cartao e Pix.</p>

        <h2>6. Confirmacao e processamento do pedido</h2>
        <p>O recebimento automatico do pedido nao representa aprovacao definitiva. O pedido pode passar por validacoes cadastrais, de pagamento, antifraude, disponibilidade e logistica antes da expedicao.</p>

        <h2>7. Entrega</h2>
        <p>Realizamos envios para todo o Brasil. O prazo estimado de entrega e informado durante a compra e pode variar conforme CEP, transportadora, forma de pagamento e disponibilidade do item.</p>

        <h2>8. Trocas, devolucoes e reembolsos</h2>
        <p>Os procedimentos de arrependimento, defeito, avaria, divergencia de item e reembolso seguem a pagina <a href="/politica-devolucoes.php">Politica de Trocas e Devolucoes</a>, observando o Codigo de Defesa do Consumidor.</p>

        <h2>9. Privacidade e uso de dados</h2>
        <p>O tratamento de dados pessoais ocorre conforme a <a href="/politica-privacidade.php">Politica de Privacidade</a>, com uso voltado a atendimento, faturamento, entrega, comunicacoes da compra e melhoria da experiencia no site.</p>

        <h2>10. Propriedade intelectual</h2>
        <p>Elementos visuais, textos, marcas, logotipos, organizacao do catalogo e demais conteudos do site nao podem ser reproduzidos, copiados ou explorados comercialmente sem autorizacao previa.</p>

        <h2>11. Atendimento</h2>
        <p>Em caso de duvidas sobre pedidos, politicas ou uso da plataforma, entre em contato pelos canais oficiais da loja:</p>
        <ul>
            <li>E-mail: <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a></li>
            <li>Telefone: <a href="tel:<?= preg_replace('/\D/', '', $phone) ?>"><?= htmlspecialchars($phone) ?></a></li>
            <li>Base operacional: <?= htmlspecialchars($city) ?> - <?= htmlspecialchars($state) ?></li>
        </ul>

        <h2>12. Legislacao aplicavel</h2>
        <p>Estes Termos sao regidos pela legislacao brasileira, em especial pelo Codigo de Defesa do Consumidor e pelas normas aplicaveis ao comercio eletronico.</p>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
