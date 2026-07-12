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
            <p class="footer-copy">&copy; <?= date('Y') ?> Vivaliz. Todos os direitos reservados.</p>
        </div>
    </footer>
