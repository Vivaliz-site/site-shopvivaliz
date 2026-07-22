<?php
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
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
      <div><strong>Vivaliz</strong><p>Rodízios, ferragens, ferramentas e utilidades para sua casa.</p><?php if ($whatsapp !== ''): ?><a href="https://wa.me/<?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></div>
      <div><strong>Termos e Condições</strong><a href="/termos">Termos e Condições</a><a href="/politica-privacidade/">Política de Privacidade</a><a href="/politica-devolucoes">Trocas e Devoluções</a><a href="/politica-entrega">Política de Frete</a></div>
      <div><strong>Institucional</strong><a href="/sobre">Quem somos</a><a href="/catalogo">Produtos</a><a href="/gamificacao.php">Gamificação</a></div>
      <div><strong>Ajuda</strong><a href="/faq">Dúvidas Frequentes</a><a href="/contato">Fale Conosco</a></div>
    </div>
    <div class="footer-legal" style="margin-top:30px;padding-top:20px;border-top:1px solid #dfe5ec;font-size:12px;color:#667085">
      <p><strong>Razão Social:</strong> <?= htmlspecialchars($legalName, ENT_QUOTES, 'UTF-8') ?> · <strong>CNPJ:</strong> <?= htmlspecialchars($cnpj, ENT_QUOTES, 'UTF-8') ?></p>
      <p><?= htmlspecialchars($address, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($neighborhood, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?> · CEP <?= htmlspecialchars($zipcode, ENT_QUOTES, 'UTF-8') ?></p>
      <p><a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></a> · <?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?></p>
      <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($fantasyName, ENT_QUOTES, 'UTF-8') ?>. Todos os direitos reservados.</p>
    </div>
  </div>
</footer>
<nav class="sv-mobile-nav" aria-label="Navegação principal móvel">
  <a href="/" class="sv-mobile-nav-item <?= empty($svNavCurrent) || $svNavCurrent === 'home' ? 'active' : '' ?>"><svg class="sv-mobile-nav-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>Início</a>
  <a href="/catalogo" class="sv-mobile-nav-item <?= ($svNavCurrent ?? '') === 'catalogo' ? 'active' : '' ?>"><svg class="sv-mobile-nav-icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zM9.5 14A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>Busca</a>
  <a href="/carrinho" class="sv-mobile-nav-item <?= ($svNavCurrent ?? '') === 'carrinho' ? 'active' : '' ?>"><svg class="sv-mobile-nav-icon" viewBox="0 0 24 24"><path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45A2 2 0 0 0 7 17h12v-2H7.42l.68-1.23h7.45a2 2 0 0 0 1.75-1.03L20.88 6H5.21l-.94-2H1zm16 16c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>Carrinho</a>
</nav>
