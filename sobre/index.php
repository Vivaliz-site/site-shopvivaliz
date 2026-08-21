<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
$svNavCurrent = 'sobre';
$company = @include __DIR__ . '/../config/company-profile.php';
$fantasyName = is_array($company) ? (string)($company['fantasy_name'] ?? 'ShopVivaliz') : 'ShopVivaliz';
$email = is_array($company) ? (string)($company['email'] ?? 'atendimento@shopvivaliz.com.br') : 'atendimento@shopvivaliz.com.br';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Conheça a ShopVivaliz: loja online de utilidades, decoração, ferramentas e soluções para casa, com atendimento direto e compra segura.">
    <link rel="canonical" href="https://shopvivaliz.com.br/sobre/">
    <title>Quem somos | <?= htmlspecialchars($fantasyName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/css/responsive.css">
    <style>
      .sv-about{background:linear-gradient(180deg,#f5fbff 0,#fff 60%);padding:56px 0 0;color:#17324f}.sv-about .container{max-width:1120px}.sv-about-hero{display:grid;grid-template-columns:1.05fr .95fr;gap:34px;align-items:center}.sv-about-card{background:#fff;border:1px solid #dbe8f3;border-radius:24px;padding:34px;box-shadow:0 18px 42px rgba(14,49,83,.10)}.sv-about-eyebrow{display:inline-flex;background:#e7f8ee;color:#087443;border-radius:999px;padding:8px 12px;font-weight:800;margin-bottom:14px}.sv-about h1{font-size:clamp(34px,5vw,56px);line-height:1.02;margin:0 0 18px;color:#0e3153}.sv-about p{font-size:17px;line-height:1.7;color:#52697d}.sv-about-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.sv-about-actions a{display:inline-flex;border-radius:999px;padding:13px 18px;font-weight:800;text-decoration:none}.sv-about-primary{background:#24c95a;color:#fff}.sv-about-secondary{background:#eef5fb;color:#17324f}.sv-about-logo{background:#0e3153;border-radius:30px;min-height:310px;display:flex;align-items:center;justify-content:center;padding:36px;box-shadow:0 20px 46px rgba(14,49,83,.18)}.sv-about-logo img{background:#fff;border-radius:26px;padding:20px 28px;width:330px;max-width:100%;height:auto}.sv-about-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:34px}.sv-about-grid article{background:#fff;border:1px solid #dbe8f3;border-radius:20px;padding:24px}.sv-about-grid h2{font-size:20px;margin:0 0 10px;color:#0e3153}.sv-about-strip{margin-top:34px;background:#0e3153;color:#fff;border-radius:24px;padding:26px;display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}.sv-about-strip p{margin:0;color:#dbeafe}.sv-about-strip a{background:#fff;color:#0e3153;border-radius:999px;padding:12px 18px;font-weight:800;text-decoration:none}@media(max-width:820px){.sv-about-hero,.sv-about-grid,.sv-about-strip{grid-template-columns:1fr}.sv-about{padding-top:34px}.sv-about-card{padding:24px}}
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<main class="sv-about">
  <div class="container">
    <section class="sv-about-hero">
      <div class="sv-about-card">
        <span class="sv-about-eyebrow">Quem somos</span>
        <h1>Uma loja online para comprar com clareza, segurança e atendimento humano.</h1>
        <p>A ShopVivaliz reúne utilidades para casa, decoração, ferramentas, ferragens e soluções práticas para o dia a dia. Nosso foco é facilitar a escolha do produto certo, com catálogo organizado, preço visível, estoque atualizado e canais de atendimento próximos.</p>
        <p>Estamos melhorando continuamente a experiência da loja para que você encontre produtos com informação objetiva, calcule o frete antes de comprar e finalize o pedido em um ambiente protegido.</p>
        <div class="sv-about-actions">
          <a class="sv-about-primary" href="/catalogo/">Ver produtos</a>
          <a class="sv-about-secondary" href="/contato/">Falar com a equipe</a>
        </div>
      </div>
      <div class="sv-about-logo" aria-label="Marca ShopVivaliz">
        <img src="/images/logo-vivaliz.png" alt="ShopVivaliz" onerror="this.src='/images/logo-vivaliz-square.png'">
      </div>
    </section>
    <section class="sv-about-grid" aria-label="Diferenciais da ShopVivaliz">
      <article><h2>Curadoria de catálogo</h2><p>Priorizamos produtos com descrição clara, imagens úteis, preço e disponibilidade para reduzir dúvidas antes da compra.</p></article>
      <article><h2>Compra transparente</h2><p>O cliente pode consultar frete, revisar o carrinho e escolher a forma de pagamento antes de confirmar o pedido.</p></article>
      <article><h2>Atendimento direto</h2><p>Quando houver dúvida sobre produto, entrega ou pedido, a equipe pode ser acionada pelos canais oficiais da loja.</p></article>
    </section>
    <section class="sv-about-strip">
      <div><strong>Precisa de ajuda para escolher?</strong><p>Envie sua dúvida com o nome do produto ou SKU. Nosso atendimento responde com orientação objetiva, sem pressão.</p></div>
      <a href="mailto:<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">Enviar e-mail</a>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
