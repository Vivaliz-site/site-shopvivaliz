<?php
declare(strict_types=1);
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$email = $company['email'] ?? 'atendimento@shopvivaliz.com.br';
$phone = $company['phone'] ?? '(37) 99937-4112';
$address = ($company['address'] ?? 'RUA CAMPINA VERDE') . ', ' . ($company['number'] ?? '841');
$city = $company['city'] ?? 'Divinópolis';
$state = $company['state'] ?? 'MG';
$social = is_array($company['social_media'] ?? null) ? $company['social_media'] : [];
$whatsapp = preg_replace('/\D+/', '', (string)($social['whatsapp'] ?? $phone));
$whatsappLink = $whatsapp !== '' ? 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode('Olá! Vim pelo site da ShopVivaliz e gostaria de atendimento.') : '';
$pageTitle = 'Contato e atendimento | ShopVivaliz';
$pageDescription = 'Fale com a ShopVivaliz sobre produtos, pedidos, pagamento, entrega, troca, devolução e pós-venda.';
$pageUrl = 'https://shopvivaliz.com.br/contato/';
$socialImage = 'https://shopvivaliz.com.br/images/logo-vivaliz.png';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="canonical" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ShopVivaliz">
<meta property="og:locale" content="pt_BR">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($socialImage, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($socialImage, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="/css/responsive.css">
<link rel="stylesheet" href="/css/footer-pages.css?v=20260817-1">
<style>.contact-form{display:grid;gap:12px}.contact-form label{display:grid;gap:6px;font-weight:700;color:#173b63}.contact-form input,.contact-form select,.contact-form textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:12px;font:inherit}.contact-form textarea{min-height:150px;resize:vertical}.contact-form button{border:0;cursor:pointer}.contact-form button[disabled]{opacity:.65;cursor:wait}.contact-status{min-height:24px;margin:4px 0 0}.contact-hp{position:absolute;left:-9999px}</style>
<?php include dirname(__DIR__) . '/includes/head-analytics.php'; ?>
</head>
<body>
<?php $svNavCurrent = 'contato'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page"><section class="brand-hero"><div class="container"><div class="brand-hero-card"><span class="brand-eyebrow">Atendimento humano</span><h1>Fale com a equipe certa.</h1><p>Dúvida de medida ou compatibilidade? Já fez um pedido? Escolha o assunto e envie os dados necessários para evitar idas e vindas.</p></div></div></section>
<div class="container"><section class="brand-section"><div class="brand-grid">
<article class="brand-card"><h2>Enviar uma mensagem</h2><form class="contact-form" id="contact-form"><label>Assunto<select name="topic" required><option value="Dúvida sobre produto">Dúvida sobre produto</option><option value="Pedido e pagamento">Pedido e pagamento</option><option value="Entrega e rastreamento">Entrega e rastreamento</option><option value="Troca ou devolução">Troca ou devolução</option><option value="Outro atendimento">Outro atendimento</option></select></label><label>Nome<input name="name" maxlength="100" autocomplete="name" required></label><label>E-mail para resposta<input type="email" name="email" maxlength="160" autocomplete="email" required></label><label>Telefone ou WhatsApp (opcional)<input name="phone" maxlength="40" autocomplete="tel"></label><label>Número do pedido (se houver)<input name="order_reference" maxlength="80"></label><label>Mensagem<textarea name="message" minlength="10" maxlength="3000" required placeholder="Conte o que precisa e, em dúvidas de produto, informe medidas ou aplicação."></textarea></label><label class="contact-hp">Site<input name="website" tabindex="-1" autocomplete="off"></label><button class="brand-btn" type="submit">Enviar mensagem</button><p id="contact-status" class="contact-status" aria-live="polite"></p></form></article>
<article class="brand-card"><h2>Canais oficiais</h2><?php if ($whatsappLink !== ''): ?><p><a class="brand-btn" href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Falar pelo WhatsApp</a></p><?php endif; ?><p><strong>E-mail:</strong><br><!--email_off--><a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a><!--/email_off--></p><p><strong>Telefone/WhatsApp:</strong><br><?= htmlspecialchars($phone) ?></p><p class="policy-note">Para pedidos, informe o número da compra e use o mesmo e-mail cadastrado. Nunca envie senha, código de confirmação ou dados bancários por mensagem.</p><h2>Endereço empresarial</h2><p><?= htmlspecialchars($address) ?><br><?= htmlspecialchars($city) ?> - <?= htmlspecialchars($state) ?></p><p>Confirme previamente com o atendimento antes de comparecer ou enviar qualquer produto.</p></article>
</div></section></div></main><?php include __DIR__ . '/../includes/footer.php'; ?><script>(function(){var f=document.getElementById('contact-form'),s=document.getElementById('contact-status');if(!f)return;f.addEventListener('submit',async function(e){e.preventDefault();var b=f.querySelector('button');b.disabled=true;s.textContent='Enviando…';try{var r=await fetch('/api/contact.php',{method:'POST',body:new FormData(f),headers:{Accept:'application/json'}});var d=await r.json();s.textContent=d.message||d.error||'Resposta recebida.';s.style.color=d.ok?'#0f7a55':'#b91c1c';if(d.ok)f.reset()}catch(err){s.textContent='Não foi possível enviar agora. Use o WhatsApp ou tente novamente.';s.style.color='#b91c1c'}finally{b.disabled=false}})})();</script></body></html>
