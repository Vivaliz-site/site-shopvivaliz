<?php
declare(strict_types=1);

$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br');
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        footer {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #e0e0e0;
            margin-top: 4rem;
            border-top: 1px solid #404040;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 3rem 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section h3 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section li {
            margin-bottom: 0.75rem;
        }

        .footer-section a {
            color: #b0b0b0;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .footer-section a:hover {
            color: #f57c00;
            transform: translateX(4px);
        }

        .footer-section p {
            color: #b0b0b0;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .footer-contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .footer-contact-item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .footer-contact-item span {
            color: #f57c00;
            min-width: 20px;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .social-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: #3a3a3a;
            border-radius: 50%;
            color: #fff;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 1.2rem;
        }

        .social-icon:hover {
            background: #f57c00;
            transform: translateY(-3px);
        }

        .footer-divider {
            grid-column: 1 / -1;
            height: 1px;
            background: #404040;
            margin: 1rem 0;
        }

        .footer-bottom {
            grid-column: 1 / -1;
            padding-top: 2rem;
            border-top: 1px solid #404040;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            align-items: center;
        }

        .footer-bottom-content {
            font-size: 0.9rem;
            color: #b0b0b0;
            text-align: center;
        }

        .footer-bottom-content a {
            color: #b0b0b0;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-bottom-content a:hover {
            color: #f57c00;
        }

        .footer-payment-methods {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .payment-badge {
            background: #3a3a3a;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            font-size: 0.85rem;
            color: #b0b0b0;
            border: 1px solid #505050;
        }

        .footer-logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #fff;
            margin-bottom: 0.5rem;
        }

        .footer-logo-icon {
            color: #f57c00;
        }

        .legal-links {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
            padding: 1.5rem 0;
            border-top: 1px solid #404040;
        }

        .legal-links a {
            color: #b0b0b0;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .legal-links a:hover {
            color: #f57c00;
        }

        .copyright {
            text-align: center;
            padding: 1rem 0;
            color: #808080;
            font-size: 0.85rem;
            border-top: 1px solid #404040;
        }

        .secure-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #2d5016;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            color: #7cb342;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
            .footer-content {
                grid-template-columns: 1fr;
                padding: 2rem 1rem;
            }

            .footer-section h3 {
                font-size: 1rem;
            }

            .footer-bottom {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .legal-links {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .footer-bottom-content {
                text-align: center;
            }

            .social-links {
                justify-content: center;
            }

            .footer-payment-methods {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <footer>
        <div class="footer-content">
            <!-- Logo e Descrição -->
            <div class="footer-section">
                <div class="footer-logo">
                    <span class="footer-logo-icon">🏪</span> ShopVivaliz
                </div>
                <p>Sua loja online de confiança para produtos de qualidade, organização e decoração. Entrega rápida em todo Brasil.</p>
                <div class="secure-badge">
                    🔒 Compras 100% Seguras
                </div>
                <div class="social-links">
                    <a href="https://facebook.com/shopvivaliz" class="social-icon" title="Facebook" target="_blank" rel="noopener">f</a>
                    <a href="https://instagram.com/shopvivaliz" class="social-icon" title="Instagram" target="_blank" rel="noopener">📷</a>
                    <a href="https://tiktok.com/@shopvivaliz" class="social-icon" title="TikTok" target="_blank" rel="noopener">♪</a>
                    <a href="https://youtube.com/shopvivaliz" class="social-icon" title="YouTube" target="_blank" rel="noopener">▶</a>
                </div>
            </div>

            <!-- Navegação Rápida -->
            <div class="footer-section">
                <h3>🔗 Navegação Rápida</h3>
                <ul>
                    <li><a href="/">Página Inicial</a></li>
                    <li><a href="/?cat=ferramentas">Ferramentas</a></li>
                    <li><a href="/?cat=jardim">Jardinagem</a></li>
                    <li><a href="/?cat=banheiro">Banheiro</a></li>
                    <li><a href="/?cat=cozinha">Cozinha</a></li>
                    <li><a href="/admin/visual-editor.php">Editor Visual</a></li>
                </ul>
            </div>

            <!-- Atendimento ao Cliente -->
            <div class="footer-section">
                <h3>💬 Atendimento</h3>
                <div class="footer-contact-info">
                    <div class="footer-contact-item">
                        <span>📧</span>
                        <div>
                            <div style="font-size: 0.85rem; color: #b0b0b0;">Email</div>
                            <a href="mailto:suporte@shopvivaliz.com.br" style="color: #f57c00; font-weight: 500;">suporte@shopvivaliz.com.br</a>
                        </div>
                    </div>
                    <div class="footer-contact-item">
                        <span>📱</span>
                        <div>
                            <div style="font-size: 0.85rem; color: #b0b0b0;">WhatsApp</div>
                            <a href="https://wa.me/5511999999999" style="color: #f57c00; font-weight: 500;" target="_blank">(11) 99999-9999</a>
                        </div>
                    </div>
                    <div class="footer-contact-item">
                        <span>📞</span>
                        <div>
                            <div style="font-size: 0.85rem; color: #b0b0b0;">Telefone</div>
                            <a href="tel:1133333333" style="color: #f57c00; font-weight: 500;">(11) 3333-3333</a>
                        </div>
                    </div>
                </div>
                <p style="margin-top: 1rem; font-size: 0.85rem;">
                    <strong>Seg-Sex:</strong> 9h às 18h<br>
                    <strong>Sábado:</strong> 10h às 14h<br>
                    <strong>Domingo:</strong> Fechado
                </p>
            </div>

            <!-- Informações Legais -->
            <div class="footer-section">
                <h3>⚖️ Políticas & Termos</h3>
                <ul>
                    <li><a href="/termos.php">Termos e Condições</a></li>
                    <li><a href="/politica-privacidade.php">Política de Privacidade</a></li>
                    <li><a href="/politica-devolucoes.php">Trocas e Devoluções</a></li>
                    <li><a href="/politica-entrega.php">Política de Entrega</a></li>
                    <li><a href="#contato">Denúncia de Fraude</a></li>
                </ul>
            </div>

            <!-- Métodos de Pagamento -->
            <div class="footer-section">
                <h3>💳 Formas de Pagamento</h3>
                <div class="footer-payment-methods">
                    <div class="payment-badge">💳 Cartão de Crédito</div>
                    <div class="payment-badge">🏦 Transferência</div>
                    <div class="payment-badge">📱 PIX</div>
                    <div class="payment-badge">📄 Boleto</div>
                </div>
                <p style="margin-top: 1rem; font-size: 0.9rem;">Parcelamos em até 12x sem juros em produtos selecionados.</p>
            </div>

            <!-- Seção de Links Legais -->
            <div class="footer-divider"></div>

            <div class="legal-links">
                <a href="/termos.php">Termos de Uso</a>
                <span style="color: #404040;">•</span>
                <a href="/politica-privacidade.php">Privacidade</a>
                <span style="color: #404040;">•</span>
                <a href="/politica-devolucoes.php">Devoluções</a>
                <span style="color: #404040;">•</span>
                <a href="/politica-entrega.php">Entrega</a>
                <span style="color: #404040;">•</span>
                <a href="#contact">Contato</a>
            </div>

            <!-- Rodapé -->
            <div class="copyright">
                <p>&copy; 2026 <strong>ShopVivaliz</strong> - Todos os direitos reservados.</p>
                <p style="margin-top: 0.5rem; color: #606060;">
                    Desenvolvido por <strong>Claude Code Autonomous</strong> |
                    Hospedado em <strong>Oracle VM Cloud</strong> |
                    Deploy Automático via GitHub Actions
                </p>
            </div>
        </div>
    </footer>
</body>
</html>
