<?php
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
$website = $company['website'] ?? 'www.shopvivaliz.com.br';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$address = ($company['address'] ?? 'RUA CAMPINA VERDE') . ', ' . ($company['number'] ?? '841');
$neighborhood = $company['neighborhood'] ?? 'SAO JOSE';
$city = $company['city'] ?? 'Divinopolis';
$state = $company['state'] ?? 'MG';
$zipcode = $company['zipcode'] ?? '35501-236';
$socialMedia = is_array($company['social_media'] ?? null) ? $company['social_media'] : [];
$whatsapp = preg_replace('/\D+/', '', (string)($socialMedia['whatsapp'] ?? ''));
?>
<style>
.payment-methods-card{background:linear-gradient(145deg,#fff 0%,#f8fbff 100%);border:1px solid #dbe5ef;border-radius:14px;padding:18px;box-shadow:0 8px 24px rgba(15,50,80,.06)}
.payment-methods-title{display:flex;align-items:center;gap:9px;color:#22324a;margin-bottom:13px;font-size:14px}
.payment-methods-grid{display:grid;grid-template-columns:repeat(4,minmax(76px,1fr));gap:10px}
.payment-method{min-height:58px;display:flex;align-items:center;justify-content:center;padding:9px 10px;border-radius:11px;border:1px solid #d7e0ea;background:#fff;box-shadow:0 3px 10px rgba(23,50,79,.05);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.payment-method:hover{transform:translateY(-2px);box-shadow:0 7px 18px rgba(23,50,79,.11);border-color:#b9c9d9}
.payment-method svg{display:block;width:100%;max-width:82px;height:32px}
.payment-method--mastercard svg{max-width:72px}
.payment-method--boleto svg{max-width:88px}
.payment-methods-note{margin-top:11px;color:#667085;font-size:11px;line-height:1.45}
@media(max-width:700px){.payment-methods-grid{grid-template-columns:repeat(2,minmax(100px,1fr))}.payment-method{min-height:64px}}
@media(max-width:420px){.payment-methods-card{padding:14px}.payment-methods-grid{gap:8px}.payment-method{min-height:58px;padding:8px}}
</style>
<footer>
    <div class="container">
        <div class="footer-cols">
            <div>
                <strong>Vivaliz</strong>
                <p>Qualidade e entrega rapida para todo o Brasil.</p>
                <?php if ($whatsapp !== ''): ?>
                    <div style="margin-top: 15px; display: flex; gap: 15px;">
                        <a href="https://wa.me/<?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="WhatsApp" style="color: #157347; text-decoration: none; font-weight: 700;">WhatsApp</a>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <strong>Termos e Condicoes</strong>
                <a href="/termos.php">Termos e Condicoes</a>
                <a href="/politica-privacidade.php">Politica de Privacidade</a>
                <a href="/politica-devolucoes.php">Politica de Trocas e Devolucoes</a>
                <a href="/politica-entrega.php">Politica de Frete</a>
            </div>

            <div>
                <strong>Institucional</strong>
                <a href="/sobre">Quem somos</a>
                <a href="/catalogo">Catalogo</a>
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
                        <span style="display: inline-flex; width: 34px; height: 34px; border-radius: 999px; align-items: center; justify-content: center; background: #e8f5ee; color: #157347; font-size: 16px; font-weight: 700;">SSL</span>
                        <div>
                            <strong style="display: block; color: #22324a;">Site seguro</strong>
                            <span style="color: #667085;">Compra protegida com conexao criptografada e ambiente monitorado.</span>
                        </div>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        <span style="display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; background: #f3f7fb; color: #284b7a; font-weight: 700;">Certificacao SSL</span>
                        <span style="display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; background: #f3f7fb; color: #284b7a; font-weight: 700;">Checkout protegido</span>
                        <span style="display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; background: #f3f7fb; color: #284b7a; font-weight: 700;">Dados protegidos</span>
                    </div>
                </div>

                <div class="payment-methods-card" aria-label="Formas de pagamento aceitas">
                    <strong class="payment-methods-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#157347" d="M21 4H3a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 14H3v-6h18v6Zm0-10H3V6h18v2Z"/></svg>
                        Pagamentos aceitos
                    </strong>
                    <div class="payment-methods-grid">
                        <span class="payment-method" title="Pix" aria-label="Pix">
                            <svg viewBox="0 0 120 42" role="img" aria-hidden="true"><path fill="#32BCAD" d="M28.2 4.7a9.2 9.2 0 0 1 13 0l8.1 8.1a5.9 5.9 0 0 0 8.3 0l8.1-8.1a9.2 9.2 0 0 1 13 0l8.1 8.1a9.2 9.2 0 0 1 0 13l-8.1 8.1a9.2 9.2 0 0 1-13 0l-8.1-8.1a5.9 5.9 0 0 0-8.3 0l-8.1 8.1a9.2 9.2 0 0 1-13 0l-8.1-8.1a9.2 9.2 0 0 1 0-13l8.1-8.1Zm3.1 3.1-8.1 8.1a4.8 4.8 0 0 0 0 6.8l8.1 8.1a4.8 4.8 0 0 0 6.8 0l8.1-8.1a10.3 10.3 0 0 1 14.5 0l8.1 8.1a4.8 4.8 0 0 0 6.8 0l8.1-8.1a4.8 4.8 0 0 0 0-6.8l-8.1-8.1a4.8 4.8 0 0 0-6.8 0l-8.1 8.1a10.3 10.3 0 0 1-14.5 0l-8.1-8.1a4.8 4.8 0 0 0-6.8 0Z"/><text x="90" y="28" font-family="Arial,sans-serif" font-size="19" font-weight="700" fill="#17324f">Pix</text></svg>
                        </span>
                        <span class="payment-method" title="Visa" aria-label="Visa">
                            <svg viewBox="0 0 120 42" role="img" aria-hidden="true"><path fill="#1434CB" d="M47 31.7h-8.8L43.7 10h8.8L47 31.7Zm32.2-21.2A22 22 0 0 0 71.3 9c-8.7 0-14.8 4.4-14.8 10.6 0 4.6 4.4 7.2 7.7 8.7 3.4 1.6 4.6 2.6 4.6 4 0 2.1-2.7 3.1-5.2 3.1-3.5 0-5.4-.5-8.3-1.7l-1.1-.5-1.2 7.2c2.1.9 5.9 1.7 9.9 1.7 9.2 0 15.2-4.3 15.3-11 0-3.6-2.3-6.4-7.4-8.7-3.1-1.5-5-2.5-5-4 0-1.3 1.6-2.7 5.1-2.7 2.9 0 5 .6 6.7 1.3l.8.4 1.2-6.9h-.4ZM102 10h-6.8c-2.1 0-3.7.6-4.6 2.8L77.5 41.6h9.2l1.8-4.8h11.3l1.1 4.8h8.1L102 10Zm-11 20.2 3.5-8.9 2 8.9H91ZM31 10 22.3 31.5l-.9-4.4c-1.6-5.1-6.7-10.6-12.4-13.3l8 27.8h9.3L40.1 10H31Z"/><path fill="#F9A533" d="M14.4 10H.3L.2 10.7c11 2.7 18.3 9.1 21.2 16.4L18.3 13c-.5-2-2.1-2.9-3.9-3Z"/></svg>
                        </span>
                        <span class="payment-method payment-method--mastercard" title="Mastercard" aria-label="Mastercard">
                            <svg viewBox="0 0 96 42" role="img" aria-hidden="true"><circle cx="37" cy="21" r="17" fill="#EB001B"/><circle cx="59" cy="21" r="17" fill="#F79E1B"/><path fill="#FF5F00" d="M48 8.1a17 17 0 0 1 0 25.8 17 17 0 0 1 0-25.8Z"/></svg>
                        </span>
                        <span class="payment-method payment-method--boleto" title="Boleto bancario" aria-label="Boleto bancario">
                            <svg viewBox="0 0 120 42" role="img" aria-hidden="true"><g fill="#17324f"><path d="M7 6h3v30H7zm6 0h1v30h-1zm4 0h4v30h-4zm7 0h2v30h-2zm6 0h5v30h-5zm8 0h2v30h-2zm5 0h1v30h-1zm5 0h4v30h-4zm7 0h2v30h-2zm6 0h5v30h-5zm9 0h1v30h-1zm4 0h3v30h-3z"/><text x="82" y="27" font-family="Arial,sans-serif" font-size="14" font-weight="700">Boleto</text></g></svg>
                        </span>
                    </div>
                    <div class="payment-methods-note">Pix, boleto e principais bandeiras de cartao exibidos com identificacao visual clara e responsiva.</div>
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

<!-- Mobile App-Like Navigation -->
<nav class="sv-mobile-nav">
  <a href="/" class="sv-mobile-nav-item <?= empty($svNavCurrent) || $svNavCurrent === "home" ? "active" : "" ?>">
    <svg class="sv-mobile-nav-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
    Inicio
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
