    <?php
    $companyProfile = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
    $companyName = $companyProfile['fantasy_name'] ?? 'Vivaliz';
    $companyEmail = $companyProfile['email'] ?? 'atendimento@shopvivaliz.com.br';
    $companyPhone = $companyProfile['phone'] ?? '(37) 99937-4112';
    $companyCNPJ = $companyProfile['cnpj'] ?? '49.903.300/0001-70';
    $companyAddress = ($companyProfile['address'] ?? 'RUA CAMPINA VERDE') . ', ' .
                      ($companyProfile['number'] ?? '841') . ' - ' .
                      ($companyProfile['city'] ?? 'Divinópolis') . ', ' .
                      ($companyProfile['state'] ?? 'MG');
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
            <div class="footer-legal" style="border-top: 1px solid #ddd; margin-top: 20px; padding-top: 15px; font-size: 12px; color: #999; line-height: 1.6;">
                <p style="margin: 0 0 8px 0;">
                    <strong><?= htmlspecialchars($companyName) ?></strong> |
                    CNPJ: <?= htmlspecialchars($companyCNPJ) ?> |
                    Telefone: <?= htmlspecialchars($companyPhone) ?> |
                    E-mail: <a href="mailto:<?= htmlspecialchars($companyEmail) ?>" style="color: #999; text-decoration: none;"><?= htmlspecialchars($companyEmail) ?></a>
                </p>
                <p style="margin: 0;">
                    Endereço: <?= htmlspecialchars($companyAddress) ?>
                </p>
            </div>

            <p class="footer-copy" style="margin-top: 15px;">&copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>. Todos os direitos reservados.</p>
        </div>
    </footer>
