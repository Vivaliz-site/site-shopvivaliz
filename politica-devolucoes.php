<?php
declare(strict_types=1);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br';
define('BASE_URL', $scheme . '://' . $host);
define('APP_NAME', 'ShopVivaliz');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Política de Trocas e Devoluções da ShopVivaliz - Conheça seus direitos">
    <title>Política de Trocas e Devoluções - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/category-images.css">
    <link rel="stylesheet" href="/css/visual-enhancements.css">
    <style>
        .legal-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
        }

        .legal-header {
            text-align: center;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid #e0e0e0;
        }

        .legal-header h1 {
            font-size: 2rem;
            color: #212121;
            margin-bottom: 0.5rem;
        }

        .last-updated {
            color: #666;
            font-size: 0.95rem;
        }

        .toc {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .toc h2 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: #333;
        }

        .toc ul {
            list-style: none;
        }

        .toc li {
            margin-bottom: 0.5rem;
        }

        .toc a {
            color: #1976d2;
            text-decoration: none;
            transition: all 0.2s;
        }

        .toc a:hover {
            color: #f57c00;
            text-decoration: underline;
        }

        .legal-section {
            margin-bottom: 2.5rem;
        }

        .legal-section h2 {
            font-size: 1.3rem;
            color: #1976d2;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e0e0e0;
            scroll-margin-top: 80px;
        }

        .legal-section h3 {
            font-size: 1.05rem;
            color: #333;
            margin-top: 1.5rem;
            margin-bottom: 0.8rem;
            font-weight: 600;
        }

        .legal-section p {
            margin-bottom: 1rem;
            line-height: 1.8;
            color: #424242;
        }

        .legal-section ul,
        .legal-section ol {
            margin-left: 1.5rem;
            margin-bottom: 1rem;
        }

        .legal-section li {
            margin-bottom: 0.5rem;
            line-height: 1.8;
            color: #424242;
        }

        .highlight-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            margin: 1.5rem 0;
            border-radius: 4px;
        }

        .highlight-box strong {
            color: #d39e00;
        }

        .success-box {
            background: #e8f5e9;
            border-left: 4px solid #388e3c;
            padding: 1rem;
            margin: 1.5rem 0;
            border-radius: 4px;
        }

        .warning-box {
            background: #ffebee;
            border-left: 4px solid #c62828;
            padding: 1rem;
            margin: 1.5rem 0;
            border-radius: 4px;
        }

        .contact-box {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 1.5rem;
            margin: 2rem 0;
            border-radius: 4px;
        }

        .contact-box h3 {
            margin-top: 0;
        }

        .contact-box a {
            color: #1976d2;
            text-decoration: none;
        }

        .contact-box a:hover {
            text-decoration: underline;
        }

        .process-box {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            border: 1px solid #e0e0e0;
        }

        .process-box ol {
            margin-bottom: 0;
        }

        .table-responsive {
            overflow-x: auto;
            margin: 1.5rem 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: #f5f5f5;
            font-weight: 600;
            color: #333;
        }

        .back-to-top {
            text-align: center;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid #e0e0e0;
        }

        .back-to-top a {
            color: #1976d2;
            text-decoration: none;
            font-weight: 500;
        }

        .back-to-top a:hover {
            color: #f57c00;
        }

        .related-policies {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 3rem;
        }

        .related-policies h3 {
            margin-top: 0;
        }

        .related-policies a {
            display: inline-block;
            margin-right: 1.5rem;
            margin-bottom: 0.5rem;
            color: #1976d2;
            text-decoration: none;
        }

        .related-policies a:hover {
            color: #f57c00;
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .legal-container {
                padding: 1rem;
            }

            .legal-header h1 {
                font-size: 1.5rem;
            }

            .legal-section h2 {
                font-size: 1.1rem;
            }

            .toc {
                font-size: 0.9rem;
            }

            table {
                font-size: 0.9rem;
            }

            th, td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <div class="legal-container">
        <div class="legal-header">
            <h1>🔄 Política de Trocas e Devoluções</h1>
            <p class="last-updated">Última atualização: Julho de 2026</p>
        </div>

        <div class="toc">
            <h2>📑 Índice</h2>
            <ul>
                <li><a href="#direitos">Seus Direitos como Consumidor</a></li>
                <li><a href="#arrependimento">Direito de Arrependimento</a></li>
                <li><a href="#defeito">Produtos com Defeito ou Danificados</a></li>
                <li><a href="#processo">Processo de Devolução</a></li>
                <li><a href="#troca">Solicitação de Troca</a></li>
                <li><a href="#reembolso">Reembolso</a></li>
                <li><a href="#condicoes">Condições para Aceitar Devolução</a></li>
                <li><a href="#restricoes">Produtos não Passíveis de Devolução</a></li>
                <li><a href="#custos">Custos de Envio</a></li>
                <li><a href="#prazos">Prazos</a></li>
                <li><a href="#contato">Contato e Suporte</a></li>
            </ul>
        </div>

        <div class="legal-section" id="direitos">
            <h2>1. Seus Direitos como Consumidor</h2>
            <p>A ShopVivaliz respeita completamente os direitos dos consumidores garantidos pelo Código de Defesa do Consumidor (Lei nº 8.078/1990).</p>
            <p>Você tem direito a:</p>
            <ul>
                <li>Receber produtos em conformidade com o anunciado</li>
                <li>Devolução em caso de arrependimento (compra não presencial)</li>
                <li>Substituição de produtos com defeito ou danificados</li>
                <li>Reembolso integral em caso de não conformidade grave</li>
                <li>Atendimento ao cliente eficiente e transparente</li>
            </ul>
            <div class="success-box">
                <strong>✓ Garantia:</strong> A ShopVivaliz garante a satisfação de seus clientes e facilita ao máximo o processo de devolução.
            </div>
        </div>

        <div class="legal-section" id="arrependimento">
            <h2>2. Direito de Arrependimento</h2>
            <p>Conforme o direito de arrependimento previsto no Código de Defesa do Consumidor e regulações de comércio eletrônico, você poderá desistir da compra e solicitar devolução dentro de <strong>7 (sete) dias corridos</strong> contados a partir do recebimento do produto.</p>

            <h3>2.1 Como Funciona</h3>
            <p>Este direito é válido para:</p>
            <ul>
                <li>Qualquer produto comprado no site (sem exceções específicas)</li>
                <li>Mesmo que o produto esteja em perfeito estado</li>
                <li>Sem necessidade de justificar o motivo</li>
            </ul>

            <h3>2.2 Prazos</h3>
            <ul>
                <li><strong>Solicitação:</strong> Até 7 dias após recebimento</li>
                <li><strong>Envio de devolução:</strong> Cliente deverá enviar dentro de 14 dias após a solicitação</li>
                <li><strong>Processamento:</strong> Reembolso processado em até 30 dias após recebimento</li>
            </ul>

            <div class="highlight-box">
                <strong>⏰ Importante:</strong> O prazo começa a contar a partir do recebimento fisicamente do produto, não da data de compra.
            </div>
        </div>

        <div class="legal-section" id="defeito">
            <h2>3. Produtos com Defeito ou Danificados</h2>
            <p>Se você recebeu um produto danificado, com defeito de fabricação ou não conforme anunciado, você poderá solicitar:</p>
            <ul>
                <li>Substituição por um produto idêntico ou equivalente</li>
                <li>Reparo do produto (se possível)</li>
                <li>Reembolso integral do valor pago</li>
            </ul>

            <h3>3.1 Prazos para Reclamações</h3>
            <ul>
                <li><strong>Defeitos Aparentes:</strong> Comunicar imediatamente ou em até 30 dias após o recebimento</li>
                <li><strong>Defeitos Ocultos:</strong> Comunicar em até 90 dias após o recebimento</li>
            </ul>

            <h3>3.2 Documentação</h3>
            <p>Para agilizar o processo, envie:</p>
            <ul>
                <li>Fotos claras do produto e da embalagem</li>
                <li>Descrição detalhada do defeito</li>
                <li>Número do pedido</li>
                <li>Comprovante de entrega</li>
            </ul>
        </div>

        <div class="legal-section" id="processo">
            <h2>4. Processo de Devolução</h2>
            <div class="process-box">
                <h3>🔢 Passo a Passo</h3>
                <ol>
                    <li><strong>Solicitar:</strong> Entre em contato conosco via email ou sistema informando o número do pedido e motivo</li>
                    <li><strong>Receber Instruções:</strong> Enviaremos etiqueta de devolução (frete pré-pago para defeitos) ou orientações de retorno</li>
                    <li><strong>Preparar Embalagem:</strong> Embale o produto de forma segura com acessórios, manual e embalagem original se possível</li>
                    <li><strong>Enviar Devolução:</strong> Use a etiqueta fornecida ou envie via transportadora indicada</li>
                    <li><strong>Acompanhar:</strong> Você receberá código de rastreamento para acompanhar a devolução</li>
                    <li><strong>Receber Reembolso:</strong> Após inspeção, processaremos o reembolso em sua conta</li>
                </ol>
            </div>
        </div>

        <div class="legal-section" id="troca">
            <h2>5. Solicitação de Troca</h2>
            <p>Você pode solicitar troca de um produto por outro (mesmo ou diferente) desde que o original atenda aos critérios de aceitação.</p>

            <h3>5.1 Opções de Troca</h3>
            <ul>
                <li><strong>Mesmo Produto:</strong> Por outro em melhor estado, tamanho ou cor</li>
                <li><strong>Produto Diferente:</strong> Por outro da nossa loja com valor igual ou superior</li>
            </ul>

            <h3>5.2 Diferença de Valor</h3>
            <ul>
                <li><strong>Produto novo vale menos:</strong> Devolveremos a diferença em crédito ou dinheiro</li>
                <li><strong>Produto novo vale mais:</strong> Você paga a diferença antes do envio</li>
            </ul>

            <div class="success-box">
                <strong>💡 Dica:</strong> Trocas geralmente são processadas mais rapidamente que devoluções, já que não geram reembolso direto.
            </div>
        </div>

        <div class="legal-section" id="reembolso">
            <h2>6. Reembolso</h2>
            <h3>6.1 Como é Processado</h3>
            <p>O reembolso será realizado para o mesmo meio de pagamento utilizado na compra:</p>
            <ul>
                <li><strong>Cartão de Crédito:</strong> Crédito na fatura em até 30 dias (depende do banco)</li>
                <li><strong>Transferência Bancária:</strong> TED/DOC em até 3 dias úteis</li>
                <li><strong>Carteira Digital:</strong> Crédito imediato na conta</li>
                <li><strong>Boleto:</strong> Transferência bancária em até 3 dias úteis</li>
            </ul>

            <h3>6.2 Prazos</h3>
            <ul>
                <li><strong>Inspeção do Produto:</strong> Até 7 dias após recebimento</li>
                <li><strong>Processamento do Reembolso:</strong> Até 30 dias após aprovação</li>
            </ul>

            <div class="highlight-box">
                <strong>⚠️ Nota:</strong> Não há reembolso de fretes já pagos pelo cliente em caso de arrependimento, exceto quando há defeito comprovado do produto.
            </div>
        </div>

        <div class="legal-section" id="condicoes">
            <h2>7. Condições para Aceitar Devolução</h2>
            <p>Para que a devolução seja aceita, o produto deve atender aos seguintes critérios:</p>

            <div class="table-responsive">
                <table>
                    <tr>
                        <th>Critério</th>
                        <th>Aceitável</th>
                        <th>Não Aceitável</th>
                    </tr>
                    <tr>
                        <td>Conservação</td>
                        <td>Sem uso ou pouco usado</td>
                        <td>Muito danificado ou quebrado</td>
                    </tr>
                    <tr>
                        <td>Embalagem</td>
                        <td>Original ou caixa adequada</td>
                        <td>Embalagem destruída ou perdida</td>
                    </tr>
                    <tr>
                        <td>Acessórios</td>
                        <td>Com todos os itens inclusos</td>
                        <td>Faltando manual ou acessórios</td>
                    </tr>
                    <tr>
                        <td>Etiquetas</td>
                        <td>Etiquetas presentes</td>
                        <td>Etiquetas removidas ou rasgadas</td>
                    </tr>
                    <tr>
                        <td>Odor/Manchas</td>
                        <td>Sem odor ou manchas estranhas</td>
                        <td>Com odor forte ou manchas permanentes</td>
                    </tr>
                </table>
            </div>

            <p>Se o produto não atender a essas condições, poderemos aplicar uma taxa de reacondicionamento ou recusar a devolução.</p>
        </div>

        <div class="legal-section" id="restricoes">
            <h2>8. Produtos Não Passíveis de Devolução</h2>
            <p>Os seguintes produtos NÃO podem ser devolvidos:</p>

            <div class="warning-box">
                <ul>
                    <li>❌ Produtos consumíveis ou de higiene pessoal já abertos</li>
                    <li>❌ Produtos com selo de segurança quebrado (alimentos, medicamentos)</li>
                    <li>❌ Plantas e flores (exceto se recebidas mortas/danificadas)</li>
                    <li>❌ Produtos personalizados ou sob encomenda (após confirmação)</li>
                    <li>❌ Promoções finais ou de liquidação (verifique no anúncio)</li>
                    <li>❌ Produtos comprados como "caixa fechada" ou "sem abrir"</li>
                </ul>
            </div>

            <p><em>Nota: Caso em caso de defeito comprovado, mesmo esses produtos podem gerar direito a reembolso conforme legislação consumerista.</em></p>
        </div>

        <div class="legal-section" id="custos">
            <h2>9. Custos de Envio de Devolução</h2>

            <h3>9.1 Frete Pré-pago (ShopVivaliz Paga)</h3>
            <p>A ShopVivaliz arca com o frete de devolução quando:</p>
            <ul>
                <li>✓ Produto chegou com defeito ou danificado</li>
                <li>✓ Produto não corresponde ao anunciado</li>
                <li>✓ Erro de envio (produto errado)</li>
            </ul>

            <h3>9.2 Frete por Conta do Cliente</h3>
            <p>O cliente arca com o frete de devolução quando:</p>
            <ul>
                <li>Arrependimento (exercendo direito legal)</li>
                <li>Produto está em perfeito estado e conforme descrito</li>
                <li>Escolhe trocar por produto de valor inferior</li>
            </ul>

            <div class="highlight-box">
                <strong>📦 Dica:</strong> Sempre solicite a etiqueta de devolução pela plataforma para ter comprovação e acompanhamento do retorno.
            </div>
        </div>

        <div class="legal-section" id="prazos">
            <h2>10. Prazos Resumidos</h2>
            <div class="table-responsive">
                <table>
                    <tr>
                        <th>Etapa</th>
                        <th>Prazo</th>
                        <th>Observação</th>
                    </tr>
                    <tr>
                        <td>Solicitar Devolução</td>
                        <td>Até 7 dias</td>
                        <td>A contar do recebimento do produto</td>
                    </tr>
                    <tr>
                        <td>Enviar Produto Devolvido</td>
                        <td>Até 14 dias</td>
                        <td>Após aprovação da solicitação</td>
                    </tr>
                    <tr>
                        <td>Recebimento e Inspeção</td>
                        <td>Até 7 dias</td>
                        <td>Verificamos condições de retorno</td>
                    </tr>
                    <tr>
                        <td>Processamento Reembolso</td>
                        <td>Até 30 dias</td>
                        <td>Após aprovação da devolução</td>
                    </tr>
                    <tr>
                        <td>Disponibilidade do Reembolso</td>
                        <td>Até 30 dias</td>
                        <td>Depende da instituição financeira</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="legal-section" id="contato">
            <h2>11. Contato e Suporte</h2>
            <div class="contact-box">
                <h3>📞 Entre em Contato para Devolução</h3>
                <p>Para iniciar um processo de devolução, troca ou reembolso, entre em contato conosco:</p>
                <ul>
                    <li><strong>Email:</strong> <a href="mailto:devolucoes@shopvivaliz.com.br">devolucoes@shopvivaliz.com.br</a></li>
                    <li><strong>Suporte Geral:</strong> <a href="mailto:suporte@shopvivaliz.com.br">suporte@shopvivaliz.com.br</a></li>
                    <li><strong>WhatsApp:</strong> (11) 99XXX-XXXX</li>
                    <li><strong>Horário:</strong> Seg-Sex, 9h às 18h (horário de Brasília)</li>
                </ul>
                <p><strong>Resposta:</strong> Respondemos em até 24 horas úteis</p>
            </div>

            <h3>Informações Necessárias</h3>
            <p>Ao entrar em contato, tenha à mão:</p>
            <ul>
                <li>Número do pedido</li>
                <li>Data de recebimento do produto</li>
                <li>Motivo da devolução</li>
                <li>Fotos do produto (se aplicável)</li>
            </ul>
        </div>

        <div class="related-policies">
            <h3>📚 Outras Políticas</h3>
            <a href="/termos.php">Termos e Condições de Uso</a>
            <a href="/politica-privacidade.php">Política de Privacidade</a>
            <a href="/politica-entrega.php">Política de Entrega e Frete</a>
        </div>

        <div class="back-to-top">
            <a href="#direitos">↑ Voltar ao topo</a>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
