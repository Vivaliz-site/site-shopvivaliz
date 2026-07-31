<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
$address = ($company['address'] ?? 'RUA CAMPINA VERDE') . ', ' . ($company['number'] ?? '841');
$city = $company['city'] ?? 'Divinópolis';
$state = $company['state'] ?? 'MG';
$social = is_array($company['social_media'] ?? null) ? $company['social_media'] : [];
$whatsapp = preg_replace('/\D+/', '', (string)($social['whatsapp'] ?? $phone));
$whatsappLink = $whatsapp !== '' ? 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode('Olá! Vim pelo site da ShopVivaliz e gostaria de atendimento.') : '';
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="description" content="Canais de atendimento da ShopVivaliz para dúvidas sobre produtos, pedidos, entrega, troca e pós-venda."><title>Contato | ShopVivaliz</title><link rel="stylesheet" href="/css/responsive.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1"></head><body>
<?php $svNavCurrent = 'contato'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page"><section class="brand-hero"><div class="container"><div class="brand-hero-card"><span class="brand-eyebrow">Atendimento</span><h1>Como podemos ajudar?</h1><p>Fale com a equipe sobre produtos, pedidos, pagamento, entrega, troca ou devolução.</p></div></div></section>
<div class="container"><section class="brand-section"><div class="brand-grid">
<article class="brand-card"><h2>Canais de atendimento</h2><?php if ($whatsappLink !== ''): ?><p><a class="brand-btn" href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Falar pelo WhatsApp</a></p><?php endif; ?><p><strong>E-mail:</strong><br><a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a></p><p><strong>Telefone/WhatsApp:</strong><br><?= htmlspecialchars($phone) ?></p><p class="policy-note">Informe o número do pedido quando o atendimento estiver relacionado a uma compra.</p></article>
<article class="brand-card"><h2>Endereço empresarial</h2><p><?= htmlspecialchars($address) ?><br><?= htmlspecialchars($city) ?> - <?= htmlspecialchars($state) ?></p><p>O endereço é informado para identificação da empresa. Confirme previamente com o atendimento antes de comparecer ou enviar qualquer produto.</p></article>
</div></section></div></main><?php include __DIR__ . '/../includes/footer.php'; ?></body></html>
