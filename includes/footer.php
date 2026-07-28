<?php
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = trim((string)($company['legal_name'] ?? 'SHOPVIVALIZ LTDA'));
$fantasyName = trim((string)($company['fantasy_name'] ?? 'ShopVivaliz'));
$email = trim((string)($company['email'] ?? 'atendimento@shopvivaliz.com.br'));
$phone = trim((string)($company['phone'] ?? '(37) 99937-4112'));
$website = trim((string)($company['website'] ?? 'shopvivaliz.com.br'));
$cnpj = trim((string)($company['cnpj'] ?? ''));
$address = trim((string)($company['address'] ?? 'RUA CAMPINA VERDE')) . ', ' . trim((string)($company['number'] ?? '841'));
$neighborhood = trim((string)($company['neighborhood'] ?? 'SAO JOSE'));
$city = trim((string)($company['city'] ?? 'Divinopolis'));
$state = trim((string)($company['state'] ?? 'MG'));
$zipcode = trim((string)($company['zipcode'] ?? '35501-236'));
$socialMedia = is_array($company['social_media'] ?? null) ? $company['social_media'] : [];
$whatsapp = preg_replace('/\D+/', '', (string)($socialMedia['whatsapp'] ?? ''));

function sv_footer_valid_social_url(string $url, string $network): bool
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
    $allowedHosts = [
        'facebook' => ['facebook.com', 'www.facebook.com'],
        'instagram' => ['instagram.com', 'www.instagram.com'],
        'tiktok' => ['tiktok.com', 'www.tiktok.com'],
        'youtube' => ['youtube.com', 'www.youtube.com', 'youtu.be'],
        'linkedin' => ['linkedin.com', 'www.linkedin.com'],
        'twitter' => ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'],
        'pinterest' => ['pinterest.com', 'www.pinterest.com', 'br.pinterest.com'],
        'telegram' => ['t.me', 'telegram.me'],
    ];

    return in_array($host, $allowedHosts[$network] ?? [], true) && $path !== '';
}

$socialLinks = [
    'facebook' => ['label' => 'Facebook', 'url' => trim((string)($socialMedia['facebook'] ?? '')), 'color' => '#1877F2', 'svg' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.413c0-3.025 1.792-4.697 4.533-4.697 1.313 0 2.686.236 2.686.236v2.972h-1.513c-1.49 0-1.956.931-1.956 1.887v2.262h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>'],
    'instagram' => ['label' => 'Instagram', 'url' => trim((string)($socialMedia['instagram'] ?? '')), 'color' => '#E1306C', 'svg' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069z"/></svg>'],
    'tiktok' => ['label' => 'TikTok', 'url' => trim((string)($socialMedia['tiktok'] ?? '')), 'color' => '#000000', 'svg' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M16.6 5.82A4.278 4.278 0 0 1 15.54 3h-3.09v12.4a2.592 2.592 0 0 1-2.59 2.5c-1.42 0-2.6-1.16-2.6-2.6 0-1.72 1.66-3.01 3.37-2.48V9.66c-3.45-.46-6.47 2.22-6.47 5.64 0 3.33 2.76 5.7 5.69 5.7 3.14 0 5.69-2.55 5.69-5.7V9.01a7.35 7.35 0 0 0 4.3 1.38V7.3s-1.88.09-3.24-1.48z"/></svg>'],
    'youtube' => ['label' => 'YouTube', 'url' => trim((string)($socialMedia['youtube'] ?? '')), 'color' => '#FF0000', 'svg' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'],
    'linkedin' => ['label' => 'LinkedIn', 'url' => trim((string)($socialMedia['linkedin'] ?? '')), 'color' => '#0A66C2', 'svg' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.447-2.136 2.941v5.665H9.351V9h3.414v1.561h.049c.476-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286z"/></svg>'],
    'twitter' => ['label' => 'X', 'url' => trim((string)($socialMedia['twitter'] ?? '')), 'color' => '#000000', 'svg' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.657l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.45-6.231z"/></svg>'],
    'pinterest' => ['label' => 'Pinterest', 'url' => trim((string)($socialMedia['pinterest'] ?? '')), 'color' => '#BD081C', 'svg' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.612 11.174-.105-.949-.2-2.403.041-3.439.219-.937 1.406-5.965 1.406-5.965s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.024 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.195.599 2.169 1.777 2.169 2.132 0 3.771-2.249 3.771-5.495z"/></svg>'],
    'telegram' => ['label' => 'Telegram', 'url' => trim((string)($socialMedia['telegram'] ?? '')), 'color' => '#229ED9', 'svg' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M9.993 15.674 9.6 21.2c.562 0 .805-.241 1.097-.531l2.634-2.517 5.458 3.996c1.001.558 1.706.264 1.976-.921l3.582-16.79c.318-1.481-.536-2.06-1.511-1.697L1.79 10.796c-1.436.558-1.414 1.36-.244 1.723l5.383 1.676L19.43 6.37c.588-.389 1.123-.174.683.215z"/></svg>'],
];
?>
<footer>
    <div class="container">
        <div class="footer-cols">
            <div>
                <strong>ShopVivaliz</strong>
                <p>Qualidade e entrega rápida para todo o Brasil.</p>
                <div style="margin-top: 15px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <?php if (strlen($whatsapp) >= 10): ?>
                        <a href="https://wa.me/<?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" title="WhatsApp" aria-label="WhatsApp" style="color: #25D366; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">WhatsApp</a>
                    <?php endif; ?>
                    <?php foreach ($socialLinks as $network => $social): ?>
                        <?php if (sv_footer_valid_social_url($social['url'], $network)): ?>
                            <a href="<?= htmlspecialchars($social['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($social['label'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($social['label'], ENT_QUOTES, 'UTF-8') ?>" style="color: <?= htmlspecialchars($social['color'], ENT_QUOTES, 'UTF-8') ?>; text-decoration: none; display: inline-flex; align-items: center;"><?= $social['svg'] ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div><strong>Termos e Condições</strong><a href="/termos">Termos e Condições</a><a href="/politica-privacidade/">Política de Privacidade</a><a href="/politica-devolucoes">Política de Trocas e Devoluções</a><a href="/politica-entrega">Política de Frete</a></div>
            <div><strong>Institucional</strong><a href="/sobre">Quem somos</a><a href="/catalogo">Produtos</a><a href="/blog/">Blog</a><a href="/gamificacao.php">Gamificação</a></div>
            <div><strong>Ajuda</strong><a href="/faq">Dúvidas Frequentes</a><a href="/contato">Fale Conosco</a></div>
        </div>
        <div class="footer-legal" style="border-top: 2px solid #d7e0ea; margin-top: 30px; padding-top: 20px; background: #f8fbff; margin-left: -20px; margin-right: -20px; margin-bottom: -20px; padding: 20px; font-size: 12px; color: #44556c; line-height: 1.8;">
            <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 18px; margin-bottom: 22px;">
                <div style="background: #fff; border: 1px solid #dbe5ef; border-radius: 8px; padding: 14px 16px;"><strong style="display: block; color: #22324a;">Site seguro</strong><span style="color: #667085;">Compra protegida com conexão criptografada e ambiente monitorado.</span></div>
                <div style="background: #fff; border: 1px solid #dbe5ef; border-radius: 8px; padding: 14px 16px;"><strong style="display: block; color: #22324a; margin-bottom: 8px;">Pagamentos aceitos</strong><img src="/images/mercado-pago-logo.jpg" alt="Mercado Pago" style="width: 100%; max-width: 340px; height: auto; max-height: 90px; object-fit: contain; border-radius: 4px;"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-bottom: 20px;">
                <div><strong style="display: block; color: #333; margin-bottom: 8px;">IDENTIFICAÇÃO</strong><div><div><strong>Razão Social:</strong> <?= htmlspecialchars($legalName, ENT_QUOTES, 'UTF-8') ?></div><div><strong>Nome Fantasia:</strong> <?= htmlspecialchars($fantasyName, ENT_QUOTES, 'UTF-8') ?></div><?php if ($cnpj !== ''): ?><div><strong>CNPJ:</strong> <?= htmlspecialchars($cnpj, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?></div></div>
                <div><strong style="display: block; color: #333; margin-bottom: 8px;">ENDEREÇO</strong><div><div><?= htmlspecialchars($address, ENT_QUOTES, 'UTF-8') ?></div><div><?= htmlspecialchars($neighborhood, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?></div><div>CEP: <?= htmlspecialchars($zipcode, ENT_QUOTES, 'UTF-8') ?></div></div></div>
                <div><strong style="display: block; color: #333; margin-bottom: 8px;">CONTATOS</strong><div><div><strong>E-mail:</strong> <a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></a></div><div><strong>Telefone:</strong> <?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?></div><div><strong>Site:</strong> <?= htmlspecialchars($website, ENT_QUOTES, 'UTF-8') ?></div></div></div>
            </div>
        </div>
    </div>
</footer>
