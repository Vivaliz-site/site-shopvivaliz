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
?>
<footer>
    <div class="container">
        <div class="footer-cols">
            <div>
                <strong>Vivaliz</strong>
                <p>Qualidade e entrega rapida para todo o Brasil.</p>
                <div style="margin-top: 15px; display: flex; gap: 15px;">
                    <a href="https://instagram.com/shopvivaliz" target="_blank" rel="noopener" title="Instagram" style="color: #e4405f; text-decoration: none; font-size: 20px;">IG</a>
                    <a href="https://facebook.com/shopvivaliz" target="_blank" rel="noopener" title="Facebook" style="color: #1877f2; text-decoration: none; font-size: 20px;">FB</a>
                    <a href="https://tiktok.com/@shop_vivaliz" target="_blank" rel="noopener" title="TikTok" style="color: #111; text-decoration: none; font-size: 20px;">TT</a>
                </div>
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
