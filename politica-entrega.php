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
    <meta name="description" content="Política de Entrega e Frete da ShopVivaliz - Saiba como funciona o envio dos produtos">
    <title>Política de Entrega e Frete - ShopVivaliz</title>
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

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 1rem;
            margin: 1.5rem 0;
            border-radius: 4px;
        }

        .contact-box {
            background: #fce4ec;
            border-left: 4px solid #c2185b;
            padding: 1.5rem;
            margin: 2rem 0;
            border-radius: 4px;
        }

        .contact-box h3 {
            margin-top: 0;
        }

        .contact-box a {
            color: #c2185b;
            text-decoration: none;
        }

        .contact-box a:hover {
            text-decoration: underline;
        }

        .shipping-card {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .shipping-card h4 {
            margin-top: 0;
            color: #1976d2;
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

        .badge {
            display: inline-block;
            background: #1976d2;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-right: 0.5rem;
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

            .shipping-card {
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <div class="legal-container">
        <div class="legal-header">
            <h1>📦 Política de Entrega e Frete</h1>
            <p class="last-updated">Última atualização: Julho de 2026</p>
        </div>

        <div class="toc">
            <h2>📑 Índice</h2>
            <ul>
                <li><a href="#areas-atendimento">Áreas de Atendimento</a></li>
                <li><a href="#opcoes-envio">Opções de Envio</a></li>
                <li><a href="#calculo-frete">Cálculo de Frete</a></li>
                <li><a href="#prazos-entrega">Prazos de Entrega</a></li>
                <li><a href="#confirmacao-pedido">Confirmação do Pedido</a></li>
                <li><a href="#embalagem">Embalagem e Proteção</a></li>
                <li><a href="#rastreamento">Rastreamento</a></li>
                <li><a href="#entrega-local">Entrega Local</a></li>
                <li><a href="#problemas-entrega">Problemas na Entrega</a></li>
                <li><a href="#devolvido">Produto Devolvido pela Transportadora</a></li>
                <li><a href="#restricoes">Restrições de Envio</a></li>
                <li><a href="#contato">Contato e Suporte</a></li>
            </ul>
        </div>

        <div class="legal-section" id="areas-atendimento">
            <h2>1. Áreas de Atendimento</h2>
            <p>A ShopVivaliz realiza entregas em todo o território nacional brasileiro.</p>

            <h3>1.1 Cobertura Nacional</h3>
            <ul>
                <li>✓ Todos os estados da federação</li>
                <li>✓ Capitais e cidades do interior</li>
                <li>✓ Áreas metropolitanas</li>
                <li>✓ Regiões rurais (com acréscimo de frete)</li>
            </ul>

            <h3>1.2 Áreas com Restrição</h3>
            <p>Algumas regiões podem ter restrições ou acréscimos de frete:</p>
            <ul>
                <li>Ilhas (exceto Grande São Paulo)</li>
                <li>Municípios remotos na Amazônia</li>
                <li>Áreas de difícil acesso</li>
                <li>Zonas de risco (conforme política de transportadoras)</li>
            </ul>

            <div class="info-box">
                <strong>ℹ️ Verificação:</strong> Ao informar seu CEP no carrinho, o sistema automaticamente verifica se sua região é atendida e apresenta as opções de frete disponíveis.
            </div>
        </div>

        <div class="legal-section" id="opcoes-envio">
            <h2>2. Opções de Envio</h2>
            <p>A ShopVivaliz oferece múltiplas modalidades de envio para sua conveniência.</p>

            <div class="shipping-card">
                <h4>📮 Correios - PAC Contratado</h4>
                <p><strong>Velocidade:</strong> 8 a 18 dias úteis (conforme CEP)</p>
                <p><strong>Características:</strong> Entrega em toda região, rastreamento, seguro básico</p>
                <p><strong>Ideal para:</strong> Produtos leves e prazos flexíveis</p>
            </div>

            <div class="shipping-card">
                <h4>⚡ Correios - SEDEX Contratado</h4>
                <p><strong>Velocidade:</strong> 2 a 12 dias úteis (conforme CEP)</p>
                <p><strong>Características:</strong> Entrega rápida em cidades maiores, rastreamento premium</p>
                <p><strong>Ideal para:</strong> Entregas prioritárias e produtos urgentes</p>
            </div>

            <div class="shipping-card">
                <h4>🚚 Transportadora Terceirizada (JadLog, Loggi, etc.)</h4>
                <p><strong>Velocidade:</strong> 3 a 8 dias úteis (São Paulo e arredores)</p>
                <p><strong>Características:</strong> Rastreamento em tempo real, entrega agendada</p>
                <p><strong>Ideal para:</strong> Produtos pequenos/médios em regiões metropolitanas</p>
            </div>

            <div class="shipping-card">
                <h4>🏪 Retirada na Loja (Pickup)</h4>
                <p><strong>Velocidade:</strong> Disponível para retirada</p>
                <p><strong>Características:</strong> Sem custo de frete, entrega imediata</p>
                <p><strong>Ideal para:</strong> Clientes que preferem retirar pessoalmente</p>
            </div>
        </div>

        <div class="legal-section" id="calculo-frete">
            <h2>3. Cálculo de Frete</h2>
            <p>O valor do frete é calculado automaticamente durante o checkout com base em:</p>

            <h3>3.1 Fatores que Influenciam o Frete</h3>
            <ul>
                <li><strong>CEP de Destino:</strong> Proximidade e região determinam o custo</li>
                <li><strong>Peso Total:</strong> Soma de todos os produtos + embalagem</li>
                <li><strong>Dimensões:</strong> Comprimento, largura e altura influenciam o espaço</li>
                <li><strong>Modalidade Escolhida:</strong> PAC, SEDEX, transportadora, etc.</li>
                <li><strong>Promotões Ativas:</strong> Frete grátis em compras acima de determinado valor</li>
            </ul>

            <h3>3.2 Frete Grátis</h3>
            <p>ShopVivaliz oferece frete grátis nas seguintes situações:</p>
            <ul>
                <li>✓ Compras acima de R$ 200,00 (PAC)</li>
                <li>✓ Compras acima de R$ 300,00 (SEDEX)</li>
                <li>✓ Produtos marcados como "Frete Grátis"</li>
                <li>✓ Promoções especiais (conforme anúncio)</li>
            </ul>

            <div class="success-box">
                <strong>💡 Dica:</strong> Agrupar compras aumenta a chance de ativar o frete grátis!
            </div>
        </div>

        <div class="legal-section" id="prazos-entrega">
            <h2>4. Prazos de Entrega</h2>

            <h3>4.1 Como é Contado o Prazo</h3>
            <p>O prazo é <strong>contado a partir da confirmação do pagamento</strong>, não da data de compra.</p>
            <ul>
                <li>Cartão de Crédito: Confirmação imediata</li>
                <li>Boleto: Confirmação após 1-3 dias úteis</li>
                <li>Transferência: Confirmação imediata</li>
                <li>PIX: Confirmação imediata</li>
            </ul>

            <h3>4.2 Prazo Máximo</h3>
            <div class="table-responsive">
                <table>
                    <tr>
                        <th>Modalidade</th>
                        <th>Prazo Estimado</th>
                        <th>Prazo Máximo (CDC)</th>
                    </tr>
                    <tr>
                        <td>PAC Correios</td>
                        <td>8-18 dias úteis</td>
                        <td>30 dias corridos</td>
                    </tr>
                    <tr>
                        <td>SEDEX Correios</td>
                        <td>2-12 dias úteis</td>
                        <td>30 dias corridos</td>
                    </tr>
                    <tr>
                        <td>Transportadora</td>
                        <td>3-8 dias úteis</td>
                        <td>30 dias corridos</td>
                    </tr>
                    <tr>
                        <td>Retirada na Loja</td>
                        <td>1-3 dias úteis</td>
                        <td>3 dias úteis</td>
                    </tr>
                </table>
            </div>

            <div class="highlight-box">
                <strong>⏰ Importante:</strong> Os prazos são estimativas. Atrasos ocasionais não constituem descumprimento contratual, exceto quando extrapolam 30 dias.
            </div>
        </div>

        <div class="legal-section" id="confirmacao-pedido">
            <h2>5. Confirmação do Pedido e Preparação</h2>

            <h3>5.1 Timeline Pós-Compra</h3>
            <ul>
                <li><strong>0-1h:</strong> Recebimento do pedido e validação de dados</li>
                <li><strong>1-2h:</strong> Confirmação de pagamento (se cartão/PIX)</li>
                <li><strong>2-6h:</strong> Preparação da embalagem e separação de produtos</li>
                <li><strong>6-24h:</strong> Postagem com transportadora ou retirada disponível</li>
                <li><strong>24h+:</strong> Produto em trânsito (prazo conforme modalidade)</li>
            </ul>

            <h3>5.2 Notificações</h3>
            <p>Você receberá notificações por email em:</p>
            <ul>
                <li>✉️ Confirmação do pedido</li>
                <li>✉️ Pagamento aprovado</li>
                <li>✉️ Preparação iniciada</li>
                <li>✉️ Produto despachado (com código de rastreamento)</li>
                <li>✉️ Em rota de entrega</li>
                <li>✉️ Entregue com sucesso</li>
            </ul>
        </div>

        <div class="legal-section" id="embalagem">
            <h2>6. Embalagem e Proteção</h2>

            <h3>6.1 Padrão de Embalagem</h3>
            <p>Todos os produtos são embalados com:</p>
            <ul>
                <li>✓ Caixa ou envelope resistente</li>
                <li>✓ Papel bolha ou espuma protetor</li>
                <li>✓ Lacre de segurança com nosso logo</li>
                <li>✓ Nota fiscal incluída</li>
                <li>✓ Manual de instruções do produto</li>
            </ul>

            <h3>6.2 Produtos Frágeis</h3>
            <p>Produtos frágeis recebem proteção reforçada:</p>
            <ul>
                <li>Caixas duplas ou reforçadas</li>
                <li>Proteção lateral e superior</li>
                <li>Material amortecedor em quantidade dobrada</li>
            </ul>

            <div class="success-box">
                <strong>🛡️ Seguro:</strong> Todos os produtos saem com seguro básico coberto pela ShopVivaliz e transportadora.
            </div>
        </div>

        <div class="legal-section" id="rastreamento">
            <h2>7. Rastreamento de Entrega</h2>

            <h3>7.1 Como Acompanhar</h3>
            <p>Você pode rastrear seu pedido de 3 formas:</p>
            <ol>
                <li><strong>Site ShopVivaliz:</strong> Acesse "Meus Pedidos" e clique em "Rastrear"</li>
                <li><strong>Código de Rastreamento:</strong> Insira o código no site dos Correios ou transportadora</li>
                <li><strong>Email:</strong> Links de rastreamento são enviados automaticamente</li>
            </ol>

            <h3>7.2 Informações Disponíveis</h3>
            <ul>
                <li>Localização atual do pacote</li>
                <li>Horário da última atualização</li>
                <li>Status (em trânsito, entregue, devolvido, etc.)</li>
                <li>Data estimada de entrega</li>
                <li>Nome do entregador (após saída para entrega)</li>
            </ul>

            <div class="info-box">
                <strong>💬 Atualização:</strong> Rastreamento é atualizado 2-3 vezes por dia. Em fins de semana e feriados, atualizações são menos frequentes.
            </div>
        </div>

        <div class="legal-section" id="entrega-local">
            <h2>8. Dia e Hora da Entrega</h2>

            <h3>8.1 Período de Entrega</h3>
            <p>Entregas são realizadas em dias úteis e feriados (conforme transportadora):</p>
            <ul>
                <li>Segunda a Sexta: 8h às 20h (horário local)</li>
                <li>Sábado: 8h às 18h (depende da transportadora)</li>
                <li>Domingo/Feriado: Sem entrega regular</li>
            </ul>

            <h3>8.2 Agendamento</h3>
            <p>Algumas transportadoras oferecem agendamento prévio:</p>
            <ul>
                <li>Entregas acima de R$ 300 podem ser agendadas</li>
                <li>Você recebe SMS/email para confirmar data e horário</li>
                <li>Pode escolher uma janela de 4 horas</li>
            </ul>
        </div>

        <div class="legal-section" id="problemas-entrega">
            <h2>9. Problemas na Entrega</h2>

            <h3>9.1 Produto Não Entregue no Prazo</h3>
            <p>Se o produto não chegar no prazo máximo:</p>
            <ol>
                <li>Verifique o rastreamento (pode estar atrasado)</li>
                <li>Aguarde até o prazo máximo de 30 dias</li>
                <li>Se não chegar até lá, entre em contato conosco</li>
                <li>Ofereceremos substituição ou reembolso</li>
            </ol>

            <h3>9.2 Produto Danificado na Entrega</h3>
            <p>Se o produto chegou danificado:</p>
            <ol>
                <li><strong>Não recuse:</strong> Aceite o pacote e fotografe os danos</li>
                <li><strong>Registre:</strong> Anote a hora, data e nome do entregador</li>
                <li><strong>Comunique:</strong> Entre em contato em até 30 minutos</li>
                <li><strong>Enviaremos:</strong> Substituição ou reembolso conforme política</li>
            </ol>

            <h3>9.3 Endereço Incorreto ou Incompleto</h3>
            <p>Se informou um endereço incorreto:</p>
            <ul>
                <li>Entre em contato imediatamente</li>
                <li>Se já foi despachado, será devolvido para nós</li>
                <li>Reenviolos para o endereço correto ou oferecemos reembolso</li>
                <li>Clientes em regiões com frete especial podem ter custos adicionais</li>
            </ul>

            <div class="highlight-box">
                <strong>⚠️ Verificação:</strong> Sempre revise o endereço antes de confirmar o pedido. Erros no endereço podem resultar em atrasos ou custos adicionais de reenvio.
            </div>
        </div>

        <div class="legal-section" id="devolvido">
            <h2>10. Produto Devolvido pela Transportadora</h2>

            <h3>10.1 Motivos Comuns</h3>
            <p>Um produto pode ser devolvido por:</p>
            <ul>
                <li>Endereço não encontrado ou incompleto</li>
                <li>Endereço abandonado</li>
                <li>Recusa de recebimento</li>
                <li>Limite de tentativas de entrega excedido</li>
            </ul>

            <h3>10.2 O que Fazemos</h3>
            <ul>
                <li>Recebemos o produto de volta</li>
                <li>Verificamos condições (seguro cobre se intacto)</li>
                <li>Contatamos você para definir próximo passo</li>
                <li>Oferecemos: reenvio, correção de endereço ou reembolso</li>
            </ul>

            <h3>10.3 Prazos</h3>
            <ul>
                <li>Comunicação: 3-5 dias úteis após retorno</li>
                <li>Reenvio: Até 7 dias úteis se autorizado</li>
                <li>Reembolso: Até 15 dias úteis após aprovação</li>
            </ul>
        </div>

        <div class="legal-section" id="restricoes">
            <h2>11. Restrições de Envio</h2>

            <h3>11.1 Produtos Perigosos</h3>
            <p>Não transportamos por lei federal:</p>
            <ul>
                <li>❌ Produtos inflamáveis ou explosivos</li>
                <li>❌ Ácidos ou produtos corrosivos</li>
                <li>❌ Pesticidas e inseticidas</li>
                <li>❌ Tinturas e solventes industriais</li>
                <li>❌ Produtos controlados (armas, munição)</li>
            </ul>

            <h3>11.2 Limitações por Tamanho/Peso</h3>
            <ul>
                <li>Peso máximo por pacote: 30kg</li>
                <li>Dimensão máxima: 150cm de comprimento</li>
                <li>Soma de dimensões: Máximo 300cm</li>
            </ul>

            <p>Produtos fora dessas dimensões requerem cotação especial.</p>

            <h3>11.3 Produtos Internacionais</h3>
            <p>ShopVivaliz atualmente oferece apenas entrega nacional. Não realizamos envios internacionais.</p>
        </div>

        <div class="legal-section" id="contato">
            <h2>12. Contato e Suporte com Frete</h2>
            <div class="contact-box">
                <h3>📞 Dúvidas sobre Entrega?</h3>
                <p>Para questões relacionadas a frete, prazo ou rastreamento:</p>
                <ul>
                    <li><strong>Email Geral:</strong> <a href="mailto:suporte@shopvivaliz.com.br">suporte@shopvivaliz.com.br</a></li>
                    <li><strong>Email Entrega:</strong> <a href="mailto:entrega@shopvivaliz.com.br">entrega@shopvivaliz.com.br</a></li>
                    <li><strong>WhatsApp:</strong> (11) 99XXX-XXXX</li>
                    <li><strong>Telefone:</strong> (11) XXXX-XXXX</li>
                    <li><strong>Horário:</strong> Seg-Sex, 9h às 18h | Sábado, 10h às 14h (Brasília)</li>
                </ul>
                <p><strong>Resposta:</strong> Respondemos em até 24 horas úteis</p>
            </div>

            <h3>Informações Úteis ao Contatar</h3>
            <ul>
                <li>Número do pedido (7-10 dígitos)</li>
                <li>Código de rastreamento (13-15 dígitos)</li>
                <li>CEP de destino</li>
                <li>Descrição do problema</li>
            </ul>
        </div>

        <div class="related-policies">
            <h3>📚 Outras Políticas</h3>
            <a href="/termos.php">Termos e Condições de Uso</a>
            <a href="/politica-privacidade.php">Política de Privacidade</a>
            <a href="/politica-devolucoes.php">Política de Trocas e Devoluções</a>
        </div>

        <div class="back-to-top">
            <a href="#areas-atendimento">↑ Voltar ao topo</a>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
