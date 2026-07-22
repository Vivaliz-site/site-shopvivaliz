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
    <title>Termos de Uso - <?= htmlspecialchars($fantasyName) ?></title>
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
        <h1>Termos e Condicoes de Uso</h1>
        <div class="legal-intro">
            <p><strong>Ultima atualizacao:</strong> Julho de 2026</p>
            <p>Estes Termos e Condicoes regulam o acesso, a navegacao e as compras realizadas no site <?= htmlspecialchars($fantasyName) ?>, operado por <?= htmlspecialchars($legalName) ?>, inscrita no CNPJ sob o numero <?= htmlspecialchars($cnpj) ?>.</p>
            <p>Ao acessar, navegar, cadastrar-se ou concluir um pedido em nossa plataforma, o usuario declara que leu, compreendeu e concorda com as regras previstas neste documento.</p>
        </div>

        <h2>1. Identificacao da plataforma</h2>
        <p>A <?= htmlspecialchars($fantasyName) ?> atua no comercio eletronico de produtos para casa, jardinagem, decoracao, organizacao, ferramentas, utilidades e categorias relacionadas apresentadas em seu catalogo online.</p>

        <h2>2. Aceitacao dos termos</h2>
        <p>O uso do site implica a aceitacao integral destes Termos, bem como das politicas complementares publicadas na plataforma, incluindo Politica de Privacidade, Politica de Trocas e Devolucoes e Politica de Entrega.</p>
        <p>Caso o usuario nao concorde com qualquer disposicao aqui estabelecida, recomenda-se que nao utilize o site nem realize compras pela plataforma.</p>

        <h2>3. Cadastro e responsabilidade do usuario</h2>
        <p>Para determinadas funcionalidades e para a realizacao de compras, o usuario devera fornecer informacoes verdadeiras, completas e atualizadas.</p>
        <ul>
            <li>manter seus dados cadastrais corretos e atualizados;</li>
            <li>preservar a confidencialidade de senhas e credenciais de acesso;</li>
            <li>utilizar apenas dados proprios ou devidamente autorizados;</li>
            <li>informar imediatamente qualquer uso indevido de sua conta.</li>
        </ul>
        <p>A loja podera suspender, restringir ou cancelar cadastros que apresentem indicios de fraude, dados inconsistentes ou uso em desacordo com a legislacao vigente.</p>

        <h2>4. Produtos, informacoes e disponibilidade</h2>
        <p>A <?= htmlspecialchars($fantasyName) ?> busca manter informacoes precisas sobre caracteristicas, especificacoes, imagens, disponibilidade e condicoes comerciais dos produtos anunciados.</p>
        <p>Podem ocorrer, no entanto, erros materiais de digitacao, atraso de atualizacao, divergencias de fornecedor ou indisponibilidade superveniente de estoque, hipoteses em que a loja podera corrigir as informacoes ou revisar a viabilidade do pedido.</p>
        <p>As imagens possuem carater ilustrativo e podem apresentar variacoes de cor, escala, acabamento ou tonalidade em funcao do lote, do fabricante ou do dispositivo utilizado pelo cliente.</p>

        <h2>5. Precos e condicoes comerciais</h2>
        <p>Os precos e condicoes exibidos no site podem ser alterados sem aviso previo antes da conclusao da compra, respeitando-se os pedidos efetivamente registrados e confirmados dentro dos criterios operacionais da plataforma.</p>
        <p>Promocoes, campanhas e ofertas especiais podem ter regras especificas, prazo determinado, limitacao de estoque e restricoes de elegibilidade.</p>

        <h2>6. Pagamentos</h2>
        <p>Os pagamentos poderao ser realizados pelas modalidades disponibilizadas no momento da compra. A aprovacao de cada transacao esta sujeita a analise, validacao e politicas dos respectivos meios de pagamento, intermediadores financeiros, operadores antifraude e instituicoes responsaveis pelo processamento.</p>
        <p>A loja podera cancelar ou revisar pedidos em caso de suspeita de fraude, inconsistencias cadastrais, falha na confirmacao do pagamento ou indisponibilidade operacional do meio escolhido.</p>

        <h2>7. Confirmacao do pedido</h2>
        <p>O recebimento automatico do pedido nao representa aprovacao definitiva da compra. O pedido pode passar por etapas de validacao cadastral, conferencia de estoque, confirmacao de frete, verificacao antifraude, conciliacao de pagamento e processamento interno antes da expedicao.</p>

        <h2>8. Entrega dos produtos</h2>
        <p>Os prazos e valores de frete sao informados durante o processo de compra e podem variar de acordo com o CEP de destino, peso, dimensoes, quantidade de itens, modalidade selecionada e disponibilidade em estoque.</p>
        <p>A contagem do prazo de entrega inicia-se apos a confirmacao do pagamento e o processamento do pedido. Eventuais atrasos causados por fatores externos, operadores logisticos, indisponibilidade momentanea de transportadora, caso fortuito ou forca maior nao configuram descumprimento automatico por parte da loja.</p>

        <h2>9. Trocas, devolucoes e cancelamentos</h2>
        <p>As regras aplicaveis a direito de arrependimento, devolucao por defeito, avaria, desacordo com o pedido, reembolso e cancelamento observam a legislacao brasileira e as condicoes descritas na <a href="/politica-devolucoes">Politica de Trocas e Devolucoes</a>.</p>

        <h2>10. Privacidade e protecao de dados</h2>
        <p>O tratamento de dados pessoais do usuario ocorre de acordo com a legislacao aplicavel e com a <a href="/politica-privacidade">Politica de Privacidade</a> publicada pela plataforma.</p>
        <p>Ao utilizar o site, o usuario declara ciencia das praticas de coleta, uso, armazenamento e compartilhamento de dados necessarios ao funcionamento da operacao.</p>

        <h2>11. Propriedade intelectual</h2>
        <p>Textos, imagens, marcas, logotipos, layouts, fotografias, elementos visuais, organizacao do catalogo e demais conteudos da plataforma sao protegidos pela legislacao de propriedade intelectual e nao podem ser reproduzidos, copiados, distribuidos ou explorados sem autorizacao previa e expressa.</p>

        <h2>12. Limitacao de responsabilidade</h2>
        <p>A <?= htmlspecialchars($fantasyName) ?> nao sera responsavel por danos decorrentes de uso inadequado dos produtos, informacoes incorretas fornecidas pelo usuario, falhas de conexao, indisponibilidades temporarias de terceiros ou eventos tecnicos fora de seu controle razoavel.</p>
        <p>A plataforma tambem nao garante disponibilidade ininterrupta do site, podendo haver pausas para manutencao, atualizacoes, melhorias tecnicas ou correcoes de seguranca.</p>

        <h2>13. Alteracoes destes termos</h2>
        <p>Estes Termos podem ser atualizados a qualquer momento para adequacao legal, tecnica, operacional ou comercial. A versao vigente sera sempre a publicada nesta pagina e passara a produzir efeitos a partir de sua disponibilizacao no site.</p>

        <h2>14. Legislacao aplicavel e foro</h2>
        <p>Estes Termos sao regidos pela legislacao brasileira, em especial pelo Codigo de Defesa do Consumidor, pelo Marco Civil da Internet, pela LGPD e pelas demais normas aplicaveis ao comercio eletronico.</p>
        <p>Fica eleito o foro do domicilio do consumidor para dirimir eventuais controversias decorrentes da utilizacao da plataforma, nos termos da legislacao aplicavel.</p>

        <h2>15. Contato</h2>
        <p>Em caso de duvidas, sugestoes ou solicitacoes relacionadas ao uso da plataforma, entre em contato pelos canais oficiais da loja:</p>
        <ul>
            <li>E-mail: <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a></li>
            <li>Telefone: <a href="tel:<?= preg_replace('/\D/', '', $phone) ?>"><?= htmlspecialchars($phone) ?></a></li>
            <li>Base operacional: <?= htmlspecialchars($city) ?> - <?= htmlspecialchars($state) ?></li>
        </ul>
    </div>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
