<?php
/**
 * Footer Premium - Incluir no fim de todas as páginas
 * <?php include __DIR__ . '/premium-footer.php'; ?>
 */
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
$socialMedia = is_array($company['social_media'] ?? null) ? $company['social_media'] : [];
$whatsappDigits = preg_replace('/\D+/', '', (string)($socialMedia['whatsapp'] ?? '5537999374112'));
$whatsappMsg = rawurlencode('Olá! Vim pelo site da ShopVivaliz e gostaria de tirar uma dúvida.');
$whatsappUrl = $whatsappDigits !== '' ? "https://wa.me/{$whatsappDigits}?text={$whatsappMsg}" : '/contato';
$instagramUrl = !empty($socialMedia['instagram']) ? $socialMedia['instagram'] : $whatsappUrl;
$facebookUrl = !empty($socialMedia['facebook']) ? $socialMedia['facebook'] : $whatsappUrl;
?>
    </main>

    <!-- FOOTER PREMIUM -->
    <footer class="footer-premium">
        <div class="footer-container">
            <!-- Coluna 1: Brand -->
            <div class="footer-col">
                <h3 class="footer-title">🛍️ <?= htmlspecialchars($fantasyName, ENT_QUOTES, 'UTF-8') ?></h3>
                <p class="footer-text">Loja oficial de produtos de qualidade com entrega rápida para todo Brasil.</p>
                <div class="footer-social">
                    <a href="<?= htmlspecialchars($facebookUrl, ENT_QUOTES, 'UTF-8') ?>" <?= !empty($socialMedia['facebook']) ? 'target="_blank" rel="noopener"' : '' ?> title="Facebook">f</a>
                    <a href="<?= htmlspecialchars($instagramUrl, ENT_QUOTES, 'UTF-8') ?>" <?= !empty($socialMedia['instagram']) ? 'target="_blank" rel="noopener"' : '' ?> title="Instagram">📷</a>
                    <a href="<?= htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="WhatsApp">📱</a>
                    <a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" title="E-mail">✉️</a>
                </div>
            </div>

            <!-- Coluna 2: Sobre -->
            <div class="footer-col">
                <h4 class="footer-subtitle">Sobre</h4>
                <ul class="footer-links">
                    <li><a href="/sobre/">Sobre nós</a></li>
                    <li><a href="/faq/">FAQ</a></li>
                    <li><a href="/contato/">Fale Conosco</a></li>
                </ul>
            </div>

            <!-- Coluna 3: Compra -->
            <div class="footer-col">
                <h4 class="footer-subtitle">Compra</h4>
                <ul class="footer-links">
                    <li><a href="/catalogo/">Catálogo</a></li>
                    <li><a href="/politica-entrega/">Política de Entrega</a></li>
                    <li><a href="/politica-devolucoes/">Política de Devoluções</a></li>
                    <li><a href="/termos/">Termos de Uso</a></li>
                </ul>
            </div>

            <!-- Coluna 4: Contato -->
            <div class="footer-col">
                <h4 class="footer-subtitle">Contato</h4>
                <p class="footer-contact">
                    📧 <a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></a><br>
                    📱 <a href="<?= htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?></a><br>
                    📍 Rua Campina Verde, 841<br>
                    São José, Divinópolis, MG
                </p>
            </div>
        </div>

        <!-- Payments Strip -->
        <div class="footer-payments-strip" style="max-width: 1600px; margin: 1.5rem auto 0 auto; padding: 1.5rem 2rem 0 2rem; border-top: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span style="display:block; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Pagamentos 100% Seguros via Mercado Pago</span>
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <img src="https://img.shields.io/badge/Mercado_Pago-Pix_%7C_Cart%C3%A3o_12x_%7C_Boleto-009ee3?style=for-the-badge&logo=mercadopago&logoColor=white" alt="Pagamentos Seguros com Mercado Pago" height="32" style="border-radius: 4px;" loading="lazy">
                    <span style="font-size: 13px; color: #10b981; font-weight: 700;">⚡ 5% de Desconto no Pix</span>
                </div>
            </div>
        </div>

        <!-- Copyright -->
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($fantasyName, ENT_QUOTES, 'UTF-8') ?>. Todos os direitos reservados.</p>
        </div>
    </footer>

    <style>
        .footer-premium {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.95) 0%, rgba(15, 23, 42, 0.95) 100%);
            border-top: 2px solid #3b82f6;
            color: #f1f5f9;
            margin-top: 4rem;
            padding: 3rem 0;
            backdrop-filter: blur(10px);
        }

        .footer-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            margin-bottom: 2rem;
        }

        .footer-col {
            animation: fadeIn 0.6s ease;
        }

        .footer-title {
            font-size: 1.5em;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #3b82f6, #10b981);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .footer-subtitle {
            font-size: 1.1em;
            color: #93c5fd;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .footer-text {
            color: #cbd5e1;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin: 0.5rem 0;
        }

        .footer-links a {
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
        }

        .footer-links a::before {
            content: '→ ';
            opacity: 0;
            transform: translateX(-10px);
            transition: all 0.3s ease;
            margin-right: 0.5rem;
        }

        .footer-links a:hover {
            color: #93c5fd;
        }

        .footer-links a:hover::before {
            opacity: 1;
            transform: translateX(0);
        }

        .footer-social {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .footer-social a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(59, 130, 246, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            border: 1px solid #3b82f6;
            transition: all 0.3s ease;
            font-size: 1.2em;
        }

        .footer-social a:hover {
            background: #3b82f6;
            transform: translateY(-3px);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.4);
        }

        .footer-contact {
            color: #cbd5e1;
            line-height: 1.8;
        }

        .footer-contact a {
            color: #93c5fd;
        }

        .footer-divider {
            max-width: 1600px;
            margin: 0 auto;
            height: 1px;
            background: linear-gradient(90deg, transparent, #3b82f6, transparent);
        }

        .footer-bottom {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
            text-align: center;
            color: #cbd5e1;
            border-top: 1px solid #334155;
            margin-top: 2rem;
        }

        .footer-bottom p {
            margin: 0.5rem 0;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .footer-container {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .footer-bottom {
                padding: 1rem;
            }
        }
    </style>
</body>
</html>
