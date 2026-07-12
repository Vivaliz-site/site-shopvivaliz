    <?php
    $company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
    $legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
    $fantasyName = $company['fantasy_name'] ?? 'Shopvivaliz';
    $email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
    $phone = $company['phone'] ?? '(37) 99937-4112';
    $mobile = $company['mobile'] ?? '(37) 99937-4112';
    $website = $company['website'] ?? 'www.shopvivaliz.com.br';
    $cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
    $ie = $company['state_registration'] ?? '004567865 0076';
    $im = $company['municipal_registration'] ?? '319830';
    $cnae = $company['cnae'] ?? '4744001';
    $address = ($company['address'] ?? 'RUA CAMPINA VERDE') . ', ' . ($company['number'] ?? '841');
    $neighborhood = $company['neighborhood'] ?? 'SAO JOSE';
    $city = $company['city'] ?? 'Divinópolis';
    $state = $company['state'] ?? 'MG';
    $zipcode = $company['zipcode'] ?? '35.501-236';
    ?>
    <footer>
        <div class="container">
            <div class="footer-cols">
                <div>
                    <strong>Vivaliz</strong>
                    <p>Qualidade e entrega rápida para todo o Brasil.</p>
                </div>

                <div>
                    <strong>Termos e Condições</strong>
                    <a href="/termos.php">Termos e Condições</a>
                    <a href="/politica-privacidade.php">Política de Privacidade</a>
                    <a href="/politica-devolucoes.php">Política de Trocas e Devoluções</a>
                    <a href="/politica-entrega.php">Política de Frete</a>
                </div>

                <div>
                    <strong>Institucional</strong>
                    <a href="/sobre">Quem somos</a>
                    <a href="/catalogo">Catálogo</a>
                    <a href="/gamificacao.php">Gamificação</a>
                </div>

                <div>
                    <strong>Ajuda</strong>
                    <a href="/faq">Dúvidas Frequentes</a>
                    <a href="/contato">Fale Conosco</a>
                </div>
            </div>

            <!-- Dados Obrigatórios da Empresa (Lei nº 12.842/2013) -->
            <div class="footer-legal" style="border-top: 2px solid #eee; margin-top: 30px; padding-top: 20px; background: #f9f9f9; margin-left: -20px; margin-right: -20px; margin-bottom: -20px; padding-left: 20px; padding-right: 20px; padding-bottom: 20px; font-size: 12px; color: #666; line-height: 1.8;">

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-bottom: 20px;">
                    <!-- Coluna 1: Identificação -->
                    <div>
                        <strong style="display: block; color: #333; margin-bottom: 8px;">IDENTIFICAÇÃO</strong>
                        <div style="line-height: 1.6;">
                            <div><strong>Razão Social:</strong> <?= htmlspecialchars($legalName) ?></div>
                            <div><strong>Nome Fantasia:</strong> <?= htmlspecialchars($fantasyName) ?></div>
                            <div><strong>CNPJ:</strong> <?= htmlspecialchars($cnpj) ?></div>
                            <div><strong>CNAE:</strong> <?= htmlspecialchars($cnae) ?></div>
                        </div>
                    </div>

                    <!-- Coluna 2: Inscrições e Endereço -->
                    <div>
                        <strong style="display: block; color: #333; margin-bottom: 8px;">DADOS FISCAIS</strong>
                        <div style="line-height: 1.6;">
                            <div><strong>Inscrição Estadual:</strong> <?= htmlspecialchars($ie) ?></div>
                            <div><strong>Inscrição Municipal:</strong> <?= htmlspecialchars($im) ?></div>
                        </div>
                        <strong style="display: block; color: #333; margin-top: 12px; margin-bottom: 8px;">ENDEREÇO</strong>
                        <div style="line-height: 1.6;">
                            <div><?= htmlspecialchars($address) ?></div>
                            <div><?= htmlspecialchars($neighborhood) ?> - <?= htmlspecialchars($city) ?>, <?= htmlspecialchars($state) ?></div>
                            <div>CEP: <?= htmlspecialchars($zipcode) ?></div>
                        </div>
                    </div>

                    <!-- Coluna 3: Contatos -->
                    <div>
                        <strong style="display: block; color: #333; margin-bottom: 8px;">CONTATOS</strong>
                        <div style="line-height: 1.6;">
                            <div><strong>Telefone:</strong> <a href="tel:<?= preg_replace('/\D/', '', $phone) ?>" style="color: #666; text-decoration: none;"><?= htmlspecialchars($phone) ?></a></div>
                            <div><strong>Celular:</strong> <a href="tel:<?= preg_replace('/\D/', '', $mobile) ?>" style="color: #666; text-decoration: none;"><?= htmlspecialchars($mobile) ?></a></div>
                            <div><strong>E-mail:</strong> <a href="mailto:<?= htmlspecialchars($email) ?>" style="color: #666; text-decoration: none;"><?= htmlspecialchars($email) ?></a></div>
                            <div><strong>Website:</strong> <a href="https://<?= htmlspecialchars($website) ?>" target="_blank" style="color: #666; text-decoration: none;"><?= htmlspecialchars($website) ?></a></div>
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
