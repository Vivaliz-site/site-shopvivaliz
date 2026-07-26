<?php
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
$website = $company['website'] ?? 'shopvivaliz.com.br';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$address = ($company['address'] ?? 'RUA CAMPINA VERDE') . ', ' . ($company['number'] ?? '841');
$neighborhood = $company['neighborhood'] ?? 'SAO JOSE';
$city = $company['city'] ?? 'Divinopolis';
$state = $company['state'] ?? 'MG';
$zipcode = $company['zipcode'] ?? '35501-236';
$socialMedia = is_array($company['social_media'] ?? null) ? $company['social_media'] : [];
$whatsapp = preg_replace('/\D+/', '', (string)($socialMedia['whatsapp'] ?? ''));
?>
<footer>
    <div class="container">
        <div class="footer-cols">
            <div>
                <strong>Vivaliz</strong>
                <p>Qualidade e entrega rápida para todo o Brasil.</p>
                <div style="margin-top: 15px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <?php if ($whatsapp !== ''): ?>
                        <a href="https://wa.me/<?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="WhatsApp" style="color: #25D366; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg> WhatsApp
                        </a>
                    <?php endif; ?>
                    <a href="https://instagram.com/shopvivaliz" target="_blank" rel="noopener" title="Instagram" aria-label="Instagram" style="color: #E1306C; text-decoration: none; display: inline-flex; align-items: center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                    </a>
                    <a href="https://tiktok.com/@shopvivaliz" target="_blank" rel="noopener" title="TikTok" aria-label="TikTok" style="color: #000; text-decoration: none; display: inline-flex; align-items: center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M16.6 5.82s.51.5 0 0A4.278 4.278 0 0 1 15.54 3h-3.09v12.4a2.592 2.592 0 0 1-2.59 2.5c-1.42 0-2.6-1.16-2.6-2.6 0-1.72 1.66-3.01 3.37-2.48V9.66c-3.45-.46-6.47 2.22-6.47 5.64 0 3.33 2.76 5.7 5.69 5.7 3.14 0 5.69-2.55 5.69-5.7V9.01a7.35 7.35 0 0 0 4.3 1.38V7.3s-1.88.09-3.24-1.48z"/></svg>
                    </a>
                    <a href="https://youtube.com/@shopvivaliz" target="_blank" rel="noopener" title="YouTube" aria-label="YouTube" style="color: #FF0000; text-decoration: none; display: inline-flex; align-items: center;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                    </a>
                </div>
            </div>

            <div>
                <strong>Termos e Condicoes</strong>
                <a href="/termos">Termos e Condicoes</a>
                <a href="/politica-privacidade/">Politica de Privacidade</a>
                <a href="/politica-devolucoes">Politica de Trocas e Devolucoes</a>
                <a href="/politica-entrega">Politica de Frete</a>
            </div>

            <div>
                <strong>Institucional</strong>
                <a href="/sobre">Quem somos</a>
                <a href="/catalogo">Produtos</a>
                <a href="/blog/">Blog</a>
                <a href="/gamificacao.php">Gamificacao</a>
            </div>

            <div>
                <strong>Ajuda</strong>
                <a href="/faq">Duvidas Frequentes</a>
                <a href="/contato">Fale Conosco</a>
            </div>
        </div>

        <div class="footer-legal" style="border-top: 2px solid #eee; margin-top: 30px; padding-top: 20px; background: #f9f9f9; margin-left: -20px; margin-right: -20px; margin-bottom: -20px; padding-left: 20px; padding-right: 20px; padding-bottom: 20px; font-size: 12px; color: #666; line-height: 1.8;">
            <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 18px; margin-bottom: 22px;">
                <div style="background: #fff; border: 1px solid #dbe5ef; border-radius: 8px; padding: 14px 16px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                        <span style="display: inline-flex; width: 34px; height: 34px; border-radius: 999px; align-items: center; justify-content: center; background: #e8f5ee; color: #157347;" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#157347" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
                        </span>
                        <div>
                            <strong style="display: block; color: #22324a;">Site seguro</strong>
                            <span style="color: #667085;">Compra protegida com conexao criptografada e ambiente monitorado.</span>
                        </div>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                        <img src="/images/selo-ssl-seguro.webp" alt="Certificação SSL - Secure SSL Encryption" style="height: 40px; width: auto;">
                        <span style="display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; background: #f3f7fb; color: #284b7a; font-weight: 700;">Checkout protegido</span>
                        <span style="display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; background: #f3f7fb; color: #284b7a; font-weight: 700;">Dados protegidos</span>
                    </div>
                </div>

                <div style="background: #fff; border: 1px solid #dbe5ef; border-radius: 8px; padding: 14px 16px; display: flex; flex-direction: column; justify-content: center;">
                    <strong style="display: block; color: #22324a; margin-bottom: 10px;">Pagamentos aceitos</strong>
                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
                        <img src="/images/mercado-pago-logo.svg" alt="Mercado Pago" style="height: 48px; width: auto;">
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-bottom: 20px;">
                <div>
                    <strong style="display: block; color: #333; margin-bottom: 8px;">IDENTIFICACAO</strong>
                    <div style="line-height: 1.6;">
                        <div><strong>Razao Social:</strong> <?= htmlspecialchars($legalName) ?></div>
                        <div><strong>Nome Fantasia:</strong> <?= htmlspecialchars($fantasyName) ?></div>
                        <div><strong>CNPJ:</strong> <?= htmlspecialchars($cnpj) ?></div>
                    </div>
                </div>

                <div>
                    <strong style="display: block; color: #333; margin-bottom: 8px;">ENDERECO</strong>
                    <div style="line-height: 1.6;">
                        <div><?= htmlspecialchars($address) ?></div>
                        <div><?= htmlspecialchars($neighborhood) ?> - <?= htmlspecialchars($city) ?>, <?= htmlspecialchars($state) ?></div>
                        <div>CEP: <?= htmlspecialchars($zipcode) ?></div>
                        <div style="margin-top: 6px;"><a href="https://maps.app.goo.gl/pziyvVNHGD2i7KQS6" target="_blank" rel="noopener" style="color: #157347; text-decoration: none; font-weight: 700;">Ver no mapa</a></div>
                    </div>
                </div>

                <div>
                    <strong style="display: block; color: #333; margin-bottom: 8px;">CONTATOS</strong>
                    <div style="line-height: 1.6;">
                        <div><strong>Telefone:</strong> <a href="tel:<?= preg_replace('/\D/', '', $phone) ?>" style="color: #666; text-decoration: none;"><?= htmlspecialchars($phone) ?></a></div>
                        <div><strong>E-mail:</strong> <a href="mailto:<?= htmlspecialchars($email) ?>" style="color: #666; text-decoration: none;"><?= htmlspecialchars($email) ?></a></div>
                        <div><strong>Website:</strong> <a href="https://<?= htmlspecialchars($website) ?>" target="_blank" rel="noopener" style="color: #666; text-decoration: none;"><?= htmlspecialchars($website) ?></a></div>
                    </div>
                </div>
            </div>

            <div style="text-align: center; color: #999; font-size: 11px; border-top: 1px solid #ddd; padding-top: 15px;">
                <p style="margin: 0;">
                    &copy; <?= date('Y') ?> <?= htmlspecialchars($fantasyName) ?>. Todos os direitos reservados. |
                    Desenvolvido por <a href="https://shopvivaliz.com.br" style="color: #999; text-decoration: none;">ShopVivaliz</a>
                </p>
            </div>
        </div>
    </div>
</footer>
<script src="/js/ml-events.js?v=1" defer></script>

<!-- Floating WhatsApp Button (Left Side) -->
<a href="https://wa.me/5537999374112?text=Ola!%20Vim%20pelo%20site%20da%20ShopVivaliz%20e%20gostaria%20de%20falar%20com%20a%20equipe." 
   class="sv-whatsapp-float" 
   target="_blank" 
   rel="noopener" 
   aria-label="Atendimento via WhatsApp"
   style="position: fixed; left: 24px; bottom: 24px; z-index: 99990; display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 50%; background: #25D366; color: #fff; box-shadow: 0 10px 30px rgba(37,211,102,0.4); text-decoration: none; transition: transform 0.2s ease;">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="#ffffff"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
</a>

<!-- Floating Liz Virtual Assistant Widget (Right Side) -->
<link rel="stylesheet" href="/public/assets/liz-assistant/liz-assistant.css?v=2.0.0">
<script src="/public/assets/liz-assistant/liz-assistant.js?v=2.2.3" defer></script>

<!-- Mobile App-Like Navigation -->
<nav class="sv-mobile-nav">
  <a href="/" class="sv-mobile-nav-item <?= empty($svNavCurrent) || $svNavCurrent === "home" ? "active" : "" ?>">
    <svg class="sv-mobile-nav-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
    Início
  </a>
  <a href="/catalogo" class="sv-mobile-nav-item <?= $svNavCurrent === "catalogo" ? "active" : "" ?>">
    <svg class="sv-mobile-nav-icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
    Busca
  </a>
  <a href="/carrinho" class="sv-mobile-nav-item <?= $svNavCurrent === "carrinho" ? "active" : "" ?>">
    <svg class="sv-mobile-nav-icon" viewBox="0 0 24 24"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>
    Carrinho
  </a>
</nav>
