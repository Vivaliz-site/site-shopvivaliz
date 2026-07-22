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
                <p>Qualidade e entrega rapida para todo o Brasil.</p>
                <?php if ($whatsapp !== ''): ?>
                    <div style="margin-top: 15px; display: flex; gap: 15px;">
                        <a href="https://wa.me/<?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="WhatsApp" style="color: #157347; text-decoration: none; font-weight: 700;">WhatsApp</a>
                    </div>
                <?php endif; ?>
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
                <a href="/gamificacao.php">Gamificacao</a>
            </div>

            <div>
                <strong>Ajuda</strong>
                <a href="/faq">Duvidas Frequentes</a>
                <a href="/contato">Fale Conosco</a>
            </div>
        </div>

        <div class="footer-legal" style="border-top: 2px solid #eee; margin-top: 30px; padding-top: 20px; background: #f9f9f9; margin-left: -20px; margin-right: -20px; margin-bottom: -20px; padding-left: 20px; padding-right: 20px; padding-bottom: 20px; font-size: 12px; color: #666; line-height: 1.8;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 18px; margin-bottom: 22px;">
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

                <div style="background: #fff; border: 1px solid #dbe5ef; border-radius: 8px; padding: 14px 16px;">
                    <strong style="display: block; color: #22324a; margin-bottom: 10px;">Pagamentos aceitos</strong>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        <span style="display: inline-flex; min-width: 74px; justify-content: center; align-items: center; padding: 8px 12px; border-radius: 8px; border: 1px solid #d7e0ea; background: #fff; color: #17324f; font-weight: 700;">PIX</span>
                        <span style="display: inline-flex; min-width: 74px; justify-content: center; align-items: center; padding: 8px 12px; border-radius: 8px; border: 1px solid #d7e0ea; background: #fff; color: #17324f; font-weight: 700;">Visa</span>
                        <span style="display: inline-flex; min-width: 74px; justify-content: center; align-items: center; padding: 8px 12px; border-radius: 8px; border: 1px solid #d7e0ea; background: #fff; color: #17324f; font-weight: 700;">Mastercard</span>
                        <span style="display: inline-flex; min-width: 74px; justify-content: center; align-items: center; padding: 8px 12px; border-radius: 8px; border: 1px solid #d7e0ea; background: #fff; color: #17324f; font-weight: 700;">Boleto</span>
                    </div>
                    <div style="margin-top: 10px; color: #667085;">
                        Os meios exibidos seguem o ecossistema oficial mapeado da ShopVivaliz.
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap: 30px; margin-bottom: 20px;">
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
                        <div><strong>E-mail:</strong> <a href="mailto:<?= htmlspecialchars($email) ?>" style="color: #666; text-decoration: none; overflow-wrap: anywhere;"><?= htmlspecialchars($email) ?></a></div>
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
    InÃ­cio
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


