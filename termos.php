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
    <meta name="description" content="Termos e Condições de Uso da ShopVivaliz - Leia nossos termos completos">
    <title>Termos e Condições de Uso - ShopVivaliz</title>
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
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/header.php'; ?>

    <div class="legal-container">
        <div class="legal-header">
            <h1>📋 Termos e Condições de Uso</h1>
            <p class="last-updated">Última atualização: Julho de 2026</p>
        </div>

        <div class="toc">
            <h2>📑 Índice</h2>
            <ul>
                <li><a href="#bem-vindo">Bem-vindo à Vivaliz</a></li>
                <li><a href="#identificacao">Identificação</a></li>
                <li><a href="#aceitar-termos">Aceitação dos Termos</a></li>
                <li><a href="#cadastro">Cadastro do Usuário</a></li>
                <li><a href="#produtos">Produtos e Informações</a></li>
                <li><a href="#precos">Preços e Condições Comerciais</a></li>
                <li><a href="#pagamentos">Pagamentos</a></li>
                <li><a href="#entrega">Entrega dos Produtos</a></li>
                <li><a href="#trocas">Trocas, Devoluções e Cancelamentos</a></li>
                <li><a href="#propriedade">Propriedade Intelectual</a></li>
                <li><a href="#responsabilidade">Limitação de Responsabilidade</a></li>
                <li><a href="#disponibilidade">Disponibilidade do Site</a></li>
                <li><a href="#privacidade">Privacidade e Proteção de Dados</a></li>
                <li><a href="#alteracoes">Alterações dos Termos</a></li>
                <li><a href="#legislacao">Legislação Aplicável</a></li>
                <li><a href="#contato">Contato</a></li>
            </ul>
        </div>

        <div class="legal-section" id="bem-vindo">
            <h2>1. Bem-vindo à Vivaliz</h2>
            <p>Seja bem-vindo ao ShopVivaliz. Os presentes Termos e Condições de Uso regulam o acesso e a utilização do site e dos serviços oferecidos por meio da plataforma.</p>
            <p>Ao acessar, navegar ou realizar compras em nosso site, o usuário declara ter lido, compreendido e concordado com estes Termos e Condições.</p>
            <div class="highlight-box">
                <strong>⚠️ Importante:</strong> Caso o usuário não concorde com qualquer condição aqui estabelecida, recomendamos que não utilize a plataforma.
            </div>
        </div>

        <div class="legal-section" id="identificacao">
            <h2>2. Identificação</h2>
            <p>A Vivaliz atua no comércio eletrônico de produtos para casa, jardinagem, decoração, organização e utilidades, disponibilizando sua plataforma para:</p>
            <ul>
                <li>Consulta de produtos</li>
                <li>Aquisição de produtos</li>
                <li>Atendimento aos consumidores</li>
            </ul>
        </div>

        <div class="legal-section" id="aceitar-termos">
            <h2>3. Aceitação dos Termos</h2>
            <p>O uso do site implica na aceitação integral das disposições previstas neste documento. Ao continuar utilizando a plataforma, você confirma sua concordância com todas as condições aqui descritas.</p>
        </div>

        <div class="legal-section" id="cadastro">
            <h2>4. Cadastro do Usuário</h2>
            <p>Para realização de compras, poderá ser necessário fornecer informações cadastrais verdadeiras, completas e atualizadas.</p>

            <h3>Responsabilidades do Usuário</h3>
            <ul>
                <li>Manter seus dados atualizados</li>
                <li>Preservar a confidencialidade de senhas e credenciais de acesso</li>
                <li>Utilizar apenas dados próprios ou devidamente autorizados</li>
                <li>Informar imediatamente qualquer uso não autorizado de sua conta</li>
            </ul>

            <p>A Vivaliz poderá suspender ou cancelar cadastros que apresentem informações incorretas, fraudulentas ou incompatíveis com a legislação vigente.</p>
        </div>

        <div class="legal-section" id="produtos">
            <h2>5. Produtos e Informações</h2>
            <p>A Vivaliz busca manter informações precisas sobre:</p>
            <ul>
                <li>Características dos produtos</li>
                <li>Especificações técnicas</li>
                <li>Disponibilidade de estoque</li>
                <li>Preços e condições comerciais</li>
            </ul>

            <p>Entretanto, poderão ocorrer erros de digitação, atualização ou inconsistências involuntárias, que poderão ser corrigidos a qualquer momento sem aviso prévio.</p>

            <div class="highlight-box">
                <strong>📸 Imagens:</strong> As imagens dos produtos possuem caráter ilustrativo e podem apresentar pequenas variações de cor, tonalidade ou acabamento em função das configurações de cada dispositivo.
            </div>
        </div>

        <div class="legal-section" id="precos">
            <h2>6. Preços e Condições Comerciais</h2>
            <p>Os preços exibidos no site estão sujeitos a alteração sem aviso prévio, respeitando-se os pedidos já concluídos e devidamente confirmados.</p>
            <p>Promoções e condições especiais poderão possuir:</p>
            <ul>
                <li>Regras específicas</li>
                <li>Prazos determinados</li>
                <li>Limitação de estoque</li>
            </ul>
        </div>

        <div class="legal-section" id="pagamentos">
            <h2>7. Pagamentos</h2>
            <p>Os pagamentos poderão ser realizados por meio das formas disponibilizadas no momento da compra.</p>
            <p>A aprovação da transação está sujeita às políticas e validações dos respectivos intermediadores financeiros e instituições responsáveis pelo processamento dos pagamentos.</p>
            <p>A Vivaliz reserva-se o direito de cancelar pedidos em caso de:</p>
            <ul>
                <li>Suspeita de fraude</li>
                <li>Inconsistência cadastral</li>
                <li>Não confirmação do pagamento</li>
            </ul>
        </div>

        <div class="legal-section" id="entrega">
            <h2>8. Entrega dos Produtos</h2>
            <p>Os prazos e valores de frete são informados durante o processo de compra e podem variar conforme:</p>
            <ul>
                <li>CEP de destino</li>
                <li>Dimensões e peso dos produtos</li>
                <li>Modalidade de entrega selecionada</li>
                <li>Disponibilidade em estoque</li>
            </ul>
            <p>A contagem do prazo inicia-se após a confirmação do pagamento e processamento do pedido.</p>
            <p>Eventuais atrasos decorrentes de fatores externos, força maior ou responsabilidade de operadores logísticos não caracterizam descumprimento contratual por parte da Vivaliz.</p>
            <p>Para mais informações sobre entrega, consulte nossa <a href="/politica-entrega.php">Política de Entrega e Frete</a>.</p>
        </div>

        <div class="legal-section" id="trocas">
            <h2>9. Trocas, Devoluções e Cancelamentos</h2>
            <p>As solicitações de troca, devolução, arrependimento ou reembolso observarão a legislação aplicável e as condições descritas em nossa Política de Trocas e Devoluções.</p>
            <p><a href="/politica-devolucoes.php">Consulte a Política de Trocas e Devoluções completa →</a></p>
        </div>

        <div class="legal-section" id="propriedade">
            <h2>10. Propriedade Intelectual</h2>
            <p>Todo o conteúdo disponibilizado pela Vivaliz, incluindo:</p>
            <ul>
                <li>Textos e logotipos</li>
                <li>Marcas registradas</li>
                <li>Layouts e design</li>
                <li>Fotografias e elementos gráficos</li>
                <li>Materiais institucionais</li>
            </ul>
            <p>é protegido pela legislação de propriedade intelectual e não poderá ser reproduzido, distribuído, copiado ou utilizado sem autorização prévia e expressa da Vivaliz.</p>
        </div>

        <div class="legal-section" id="responsabilidade">
            <h2>11. Limitação de Responsabilidade</h2>
            <p>A Vivaliz não será responsável por:</p>
            <ul>
                <li>Danos decorrentes do uso inadequado dos produtos</li>
                <li>Informações incorretas fornecidas pelo usuário</li>
                <li>Interrupções temporárias causadas por manutenção ou falhas de terceiros</li>
                <li>Problemas decorrentes de vírus, falhas de conexão ou incompatibilidades tecnológicas fora de seu controle razoável</li>
            </ul>
        </div>

        <div class="legal-section" id="disponibilidade">
            <h2>12. Disponibilidade do Site</h2>
            <p>Embora sejam empregados esforços para manter a plataforma disponível continuamente, poderão ocorrer interrupções temporárias para:</p>
            <ul>
                <li>Atualizações técnicas</li>
                <li>Correções de segurança</li>
                <li>Melhorias operacionais</li>
                <li>Eventos externos imprevisíveis</li>
            </ul>
            <p>A Vivaliz não garante disponibilidade ininterrupta do serviço.</p>
        </div>

        <div class="legal-section" id="privacidade">
            <h2>13. Privacidade e Proteção de Dados</h2>
            <p>O tratamento de dados pessoais é realizado conforme a legislação vigente e de acordo com nossa Política de Privacidade.</p>
            <p>Ao utilizar a plataforma, o usuário declara estar ciente das práticas de tratamento de dados descritas em nossa Política de Privacidade.</p>
            <p><a href="/politica-privacidade.php">Consulte a Política de Privacidade completa →</a></p>
        </div>

        <div class="legal-section" id="alteracoes">
            <h2>14. Alterações dos Termos</h2>
            <p>A Vivaliz poderá alterar estes Termos e Condições a qualquer momento para adequação legal, operacional ou tecnológica.</p>
            <p>A versão atualizada permanecerá disponível no site e produzirá efeitos a partir de sua publicação.</p>
        </div>

        <div class="legal-section" id="legislacao">
            <h2>15. Legislação Aplicável</h2>
            <p>Estes Termos serão interpretados e regidos pelas leis da República Federativa do Brasil, especialmente:</p>
            <ul>
                <li><strong>Código de Defesa do Consumidor</strong> (Lei nº 8.078/1990)</li>
                <li><strong>Marco Civil da Internet</strong> (Lei nº 12.965/2014)</li>
                <li><strong>Lei Geral de Proteção de Dados Pessoais – LGPD</strong> (Lei nº 13.709/2018)</li>
                <li>Demais normas aplicáveis ao comércio eletrônico</li>
            </ul>
            <p>Fica eleito o foro do domicílio do consumidor para dirimir eventuais controvérsias decorrentes da utilização da plataforma.</p>
        </div>

        <div class="legal-section" id="contato">
            <h2>16. Contato e Suporte</h2>
            <div class="contact-box">
                <h3>📞 Entre em contato conosco</h3>
                <p>Para dúvidas, sugestões ou solicitações relacionadas ao uso da plataforma, entre em contato pelos canais oficiais de atendimento da Vivaliz:</p>
                <ul>
                    <li><strong>Email:</strong> <a href="mailto:suporte@shopvivaliz.com.br">suporte@shopvivaliz.com.br</a></li>
                    <li><strong>Telefone:</strong> (11) XXXX-XXXX</li>
                    <li><strong>WhatsApp:</strong> (11) 99XXX-XXXX</li>
                    <li><strong>Website:</strong> <a href="https://www.shopvivaliz.com.br">www.shopvivaliz.com.br</a></li>
                </ul>
            </div>
        </div>

        <div class="related-policies">
            <h3>📚 Outras Políticas e Documentos</h3>
            <a href="/politica-privacidade.php">Política de Privacidade</a>
            <a href="/politica-devolucoes.php">Política de Trocas e Devoluções</a>
            <a href="/politica-entrega.php">Política de Entrega e Frete</a>
        </div>

        <div class="back-to-top">
            <a href="#bem-vindo">↑ Voltar ao topo</a>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
