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
    <meta name="description" content="Política de Privacidade da ShopVivaliz - Conheça como protegemos seus dados pessoais">
    <title>Política de Privacidade - ShopVivaliz</title>
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
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 1rem;
            margin: 1.5rem 0;
            border-radius: 4px;
        }

        .highlight-box strong {
            color: #0d47a1;
        }

        .contact-box {
            background: #e8f5e9;
            border-left: 4px solid #388e3c;
            padding: 1.5rem;
            margin: 2rem 0;
            border-radius: 4px;
        }

        .contact-box h3 {
            margin-top: 0;
            color: #1b5e20;
        }

        .contact-box a {
            color: #388e3c;
            text-decoration: none;
        }

        .contact-box a:hover {
            text-decoration: underline;
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
            <h1>🔒 Política de Privacidade</h1>
            <p class="last-updated">Última atualização: Julho de 2026</p>
        </div>

        <div class="toc">
            <h2>📑 Índice</h2>
            <ul>
                <li><a href="#introducao">Introdução</a></li>
                <li><a href="#responsavel">Responsável pelos Dados</a></li>
                <li><a href="#coleta">Coleta de Dados Pessoais</a></li>
                <li><a href="#uso">Uso dos Dados</a></li>
                <li><a href="#compartilhamento">Compartilhamento de Dados</a></li>
                <li><a href="#lgpd">Direitos Conforme LGPD</a></li>
                <li><a href="#seguranca">Segurança dos Dados</a></li>
                <li><a href="#retencao">Retenção de Dados</a></li>
                <li><a href="#cookies">Cookies e Tecnologias Similares</a></li>
                <li><a href="#menores">Proteção de Menores</a></li>
                <li><a href="#alteracoes">Alterações nesta Política</a></li>
                <li><a href="#contato">Contato e Dúvidas</a></li>
            </ul>
        </div>

        <div class="legal-section" id="introducao">
            <h2>1. Introdução</h2>
            <p>A ShopVivaliz respeita e protege sua privacidade. Esta Política de Privacidade explica como coletamos, usamos, divulgamos e salvaguardamos suas informações quando você utiliza nossa plataforma de comércio eletrônico.</p>
            <p>Por favor, leia atentamente esta política. Ao acessar e usar o site da ShopVivaliz, você reconhece ter compreendido e concordado com as práticas de privacidade descritas neste documento.</p>
        </div>

        <div class="legal-section" id="responsavel">
            <h2>2. Responsável pelos Dados Pessoais</h2>
            <p>A entidade responsável pela coleta, processamento e proteção de seus dados pessoais é:</p>
            <div class="highlight-box">
                <p><strong>ShopVivaliz Comércio Eletrônico Ltda.</strong></p>
                <p>Email: <a href="mailto:privacidade@shopvivaliz.com.br">privacidade@shopvivaliz.com.br</a></p>
                <p>Encarregado de Dados: dpo@shopvivaliz.com.br</p>
            </div>
        </div>

        <div class="legal-section" id="coleta">
            <h2>3. Coleta de Dados Pessoais</h2>
            <p>Coletamos dados pessoais de maneira lícita, necessária e transparente. Os principais dados coletados são:</p>

            <h3>3.1 Dados Fornecidos Diretamente</h3>
            <ul>
                <li><strong>Cadastro:</strong> Nome, email, telefone, CPF/CNPJ, endereço de entrega e cobrança</li>
                <li><strong>Compras:</strong> Histórico de pedidos, produtos adquiridos, preferências de entrega</li>
                <li><strong>Comunicação:</strong> Mensagens enviadas através do formulário de contato</li>
                <li><strong>Pagamento:</strong> Informações de pagamento processadas via intermediadores</li>
            </ul>

            <h3>3.2 Dados Coletados Automaticamente</h3>
            <ul>
                <li><strong>Acesso ao Site:</strong> Endereço IP, tipo de navegador, dispositivo utilizado, sistema operacional</li>
                <li><strong>Navegação:</strong> Páginas visitadas, links clicados, tempo de permanência, caminho de acesso</li>
                <li><strong>Localização:</strong> Geolocalização aproximada (baseada em IP)</li>
                <li><strong>Cookies:</strong> Informações armazenadas no seu dispositivo para funcionalidades específicas</li>
            </ul>
        </div>

        <div class="legal-section" id="uso">
            <h2>4. Uso dos Dados Pessoais</h2>
            <p>Utilizamos seus dados pessoais para as seguintes finalidades:</p>

            <h3>4.1 Finalidades Essenciais</h3>
            <ul>
                <li>Processar suas compras e pagamentos</li>
                <li>Enviar confirmações de pedidos e informações de entrega</li>
                <li>Contato para atendimento ao cliente e suporte pós-venda</li>
                <li>Cumprir obrigações legais e fiscais</li>
                <li>Prevenir fraudes e atividades ilícitas</li>
            </ul>

            <h3>4.2 Finalidades com Consentimento</h3>
            <ul>
                <li>Envio de newsletter e comunicações sobre promoções</li>
                <li>Análise de comportamento de compra para personalização</li>
                <li>Pesquisas de satisfação e feedback</li>
                <li>Remarketing e publicidade direcionada</li>
            </ul>

            <div class="highlight-box">
                <strong>✓ Controle Total:</strong> Você pode revogar seu consentimento a qualquer momento clicando no link "desinscrever" nos emails ou acessando suas preferências de notificação.
            </div>
        </div>

        <div class="legal-section" id="compartilhamento">
            <h2>5. Compartilhamento de Dados</h2>
            <p>Seus dados pessoais podem ser compartilhados com terceiros nos seguintes casos:</p>

            <h3>5.1 Parceiros Essenciais</h3>
            <table>
                <tr>
                    <th>Tipo de Parceiro</th>
                    <th>Finalidade</th>
                    <th>Dados Compartilhados</th>
                </tr>
                <tr>
                    <td>Provedores de Pagamento</td>
                    <td>Processar transações</td>
                    <td>Nome, email, endereço, dados de cartão*</td>
                </tr>
                <tr>
                    <td>Operadores Logísticos</td>
                    <td>Entrega de produtos</td>
                    <td>Nome, telefone, endereço</td>
                </tr>
                <tr>
                    <td>Empresa de Análise</td>
                    <td>Análise de tráfego</td>
                    <td>IP anônimo, tipo de navegador</td>
                </tr>
            </table>
            <p><em>* Dados de cartão são processados por intermediários certificados e não ficam armazenados em nossos servidores.</em></p>

            <h3>5.2 Quando Exigido por Lei</h3>
            <p>Podemos divulgar dados pessoais quando obrigados por:</p>
            <ul>
                <li>Determinação de autoridades judiciais ou administrativas</li>
                <li>Requisições de órgãos governamentais competentes</li>
                <li>Investigações de conformidade e segurança</li>
            </ul>
        </div>

        <div class="legal-section" id="lgpd">
            <h2>6. Seus Direitos Conforme a LGPD</h2>
            <p>Você possui os seguintes direitos sob a Lei Geral de Proteção de Dados (Lei nº 13.709/2018):</p>

            <h3>6.1 Direito de Acesso</h3>
            <p>Você pode solicitar acesso a todos os dados pessoais que mantemos sobre você.</p>

            <h3>6.2 Direito de Retificação</h3>
            <p>Você pode corrigir dados incompletos, inexatos ou desatualizados.</p>

            <h3>6.3 Direito à Exclusão (Direito ao Esquecimento)</h3>
            <p>Você pode solicitar a exclusão de seus dados, exceto quando haja obrigação legal de mantê-los.</p>

            <h3>6.4 Direito à Portabilidade</h3>
            <p>Você pode solicitar seus dados em formato estruturado, comumente utilizado e legível.</p>

            <h3>6.5 Direito de Oposição</h3>
            <p>Você pode se opor ao tratamento de dados para finalidades específicas.</p>

            <h3>6.6 Direito de Revogar Consentimento</h3>
            <p>Você pode revogar o consentimento de tratamento de dados a qualquer momento, sem prejuízo de ações pretéritas.</p>

            <div class="contact-box">
                <h3>📧 Como Exercer seus Direitos</h3>
                <p>Para exercer qualquer um destes direitos, envie solicitação para:</p>
                <p><strong>Email:</strong> <a href="mailto:dpo@shopvivaliz.com.br">dpo@shopvivaliz.com.br</a></p>
                <p><strong>Assunto:</strong> [LGPD - Direito de Acesso/Retificação/Exclusão]</p>
                <p>Forneceremos resposta em até 15 dias úteis.</p>
            </div>
        </div>

        <div class="legal-section" id="seguranca">
            <h2>7. Segurança dos Dados</h2>
            <p>Implementamos medidas técnicas e organizacionais para proteger seus dados contra acesso não autorizado, alteração, divulgação ou destruição:</p>

            <h3>Medidas de Segurança</h3>
            <ul>
                <li>✓ Criptografia de dados em trânsito (HTTPS/TLS)</li>
                <li>✓ Criptografia de dados sensíveis em repouso</li>
                <li>✓ Firewalls e sistemas de detecção de intrusão</li>
                <li>✓ Backups regulares com teste de recuperação</li>
                <li>✓ Acesso restrito a dados por controle de permissões</li>
                <li>✓ Monitoramento contínuo de segurança</li>
                <li>✓ Política de senhas fortes e autenticação multifator</li>
            </ul>

            <div class="highlight-box">
                <strong>⚠️ Responsabilidade Compartilhada:</strong> Embora adotemos medidas robustas, nenhum método de transmissão via internet ou armazenamento é 100% seguro. Recomendamos que você também cuide da segurança de sua senha e acesso à conta.
            </div>
        </div>

        <div class="legal-section" id="retencao">
            <h2>8. Retenção de Dados</h2>
            <p>Retemos seus dados pessoais pelo tempo necessário para:</p>
            <ul>
                <li><strong>Cumprir obrigações legais:</strong> 5 a 7 anos conforme legislação fiscal e consumerista</li>
                <li><strong>Propósitos de contrato:</strong> Durante a vigência e até 2 anos após encerramento</li>
                <li><strong>Fins estatísticos:</strong> Dados anonimizados podem ser retidos indefinidamente</li>
                <li><strong>Reclamações ou litígios:</strong> Pelo prazo de prescrição legal (até 5 anos)</li>
            </ul>
            <p>Após a retenção necessária, seus dados serão eliminados ou anonimizados.</p>
        </div>

        <div class="legal-section" id="cookies">
            <h2>9. Cookies e Tecnologias Similares</h2>
            <p>O site utiliza cookies para melhorar sua experiência de navegação. Cookies são pequenos arquivos de texto armazenados no seu dispositivo.</p>

            <h3>9.1 Tipos de Cookies</h3>
            <ul>
                <li><strong>Cookies Essenciais:</strong> Necessários para funcionamento do site (autenticação, segurança)</li>
                <li><strong>Cookies de Funcionalidade:</strong> Lembram suas preferências e personalizações</li>
                <li><strong>Cookies de Análise:</strong> Coletam informações sobre o uso do site</li>
                <li><strong>Cookies de Marketing:</strong> Usados para publicidade direcionada</li>
            </ul>

            <h3>9.2 Controle de Cookies</h3>
            <p>Você pode desabilitar cookies nas configurações do seu navegador. Entretanto, isso pode afetar a funcionalidade do site.</p>
        </div>

        <div class="legal-section" id="menores">
            <h2>10. Proteção de Menores de Idade</h2>
            <p>O site não é destinado a menores de 18 anos. Não coletamos intencionalmente dados de menores sem consentimento de seus responsáveis legais.</p>
            <p>Se você é responsável por um menor e identificar que este forneceu dados pessoais, entre em contato imediatamente conosco.</p>
        </div>

        <div class="legal-section" id="alteracoes">
            <h2>11. Alterações nesta Política</h2>
            <p>Esta Política de Privacidade poderá ser alterada a qualquer momento para adequação legal ou implementação de novas práticas de proteção de dados.</p>
            <p>A versão atual permanecerá sempre disponível nesta página, com data de atualização claramente indicada.</p>
            <p>Alterações significativas serão comunicadas via email aos usuários cadastrados.</p>
        </div>

        <div class="legal-section" id="contato">
            <h2>12. Contato e Dúvidas</h2>
            <div class="contact-box">
                <h3>📞 Fale Conosco</h3>
                <p>Para dúvidas, reclamações ou solicitações relacionadas à privacidade de dados:</p>
                <ul>
                    <li><strong>Email Geral:</strong> <a href="mailto:suporte@shopvivaliz.com.br">suporte@shopvivaliz.com.br</a></li>
                    <li><strong>Encarregado de Dados (DPO):</strong> <a href="mailto:dpo@shopvivaliz.com.br">dpo@shopvivaliz.com.br</a></li>
                    <li><strong>Endereço:</strong> ShopVivaliz Comércio Eletrônico Ltda. - São Paulo, SP</li>
                </ul>
                <p><strong>Prazo de Resposta:</strong> Responderemos em até 15 dias úteis.</p>
            </div>
        </div>

        <div class="related-policies">
            <h3>📚 Documentos Relacionados</h3>
            <a href="/termos.php">Termos e Condições de Uso</a>
            <a href="/politica-devolucoes.php">Política de Trocas e Devoluções</a>
            <a href="/politica-entrega.php">Política de Entrega e Frete</a>
        </div>

        <div class="back-to-top">
            <a href="#introducao">↑ Voltar ao topo</a>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
