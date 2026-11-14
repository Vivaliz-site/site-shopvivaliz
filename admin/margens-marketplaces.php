<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/admin-guard.php';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Margens dos Marketplaces — ShopVivaliz</title>
<link rel="stylesheet" href="/css/style.css">
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#152d49;font:15px system-ui,Arial,sans-serif}
header{background:#163454;color:white;padding:20px 24px}header a{color:white}
main{max-width:1180px;margin:24px auto;padding:0 16px}
h1{font-size:1.55rem;margin:0 0 7px}h2{font-size:1.17rem;margin:0}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:15px;margin:22px 0}
.card{background:white;border:1px solid #dce5ee;padding:19px;border-radius:13px;box-shadow:0 1px 3px #1133660c}
.card p{color:#46586e;line-height:1.5;min-height:50px}
.link{display:inline-block;background:#134e79;color:#fff;text-decoration:none;border-radius:8px;padding:10px 14px;font-weight:750}
.status{font-size:12px;background:#edf2f9;padding:4px 8px;border-radius:7px;color:#40556d}
.sub{font-size:13px;color:#62748a}
.note{line-height:1.65;color:#4a5f78}
footer{padding-bottom:30px}
</style>
</head>
<body>
<header><h1>Auditoria de Margens — Marketplaces</h1><div>Amazon · Mercado Livre · Shopee · TikTok Shop</div><a href="/admin/">Voltar ao Admin</a></header>
<main>
<p class="note">Uma central para os quatro marketplaces, com auditorias independentes por anúncio. Imposto considerado: <strong>14%</strong>; referência de margem líquida: <strong>9%</strong>. Taxas e frete devem ser comprovados por canal. Os carrinhos de ferramentas continuam auditados, mas sem proposta de alteração de preço.</p>
<div class="cards">
<section class="card"><h2>Amazon</h2><span class="status">Auditor existente</span><p>SP-API e ERP Olist. FBA Onsite e DBA com tarifas próprias, histórico e relatórios.</p><a class="link" href="/admin/amazon-margin.php">Abrir Amazon</a></section>
<section class="card"><h2>Mercado Livre</h2><span class="status" id="status-mercadolivre">Consultando...</span><p>Auditor ML já integrado ao serviço Pricing. Nova API independente complementar sem duplicar o cálculo certificado.</p><a class="link" href="/admin/marketplace-margin.php?channel=mercadolivre">Abrir API independente</a> <a class="link" href="/admin/mercadolivre-margin.php" style="background:#60748a">Auditor ML existente</a></section>
<section class="card"><h2>Shopee</h2><span class="status" id="status-shopee">Consultando...</span><p>API independente, produtos e modelos, histórico financeiro e relatórios PDF/Excel.</p><a class="link" href="/admin/marketplace-margin.php?channel=shopee">Abrir Shopee</a></section>
<section class="card"><h2>TikTok Shop</h2><span class="status" id="status-tiktok">Consultando...</span><p>API independente por SKU TikTok, liquidação financeira e relatórios separados.</p><a class="link" href="/admin/marketplace-margin.php?channel=tiktok">Abrir TikTok Shop</a></section>
</div>
<section class="card">
<h2>Como as margens são tratadas</h2>
<p class="note">Cálculo: receita bruta − 14% de impostos − custo comercial do produto/kit − comissões e tarifas do canal − frete do vendedor − embalagem − demais despesas variáveis. O lucro é dividido pela receita bruta. Estimativas de anúncios atuais e resultados de vendas liquidadas são exibidos separadamente.</p>
<p class="note">Custo, taxa ou postagem sem confirmação aparecem como <strong>INCOMPLETO</strong> — nunca como lucro artificial. A simulação do preço mínimo para 9% depende de uma nova cotação das taxas naquele preço; sem essa cotação, o painel sinaliza a pendência. Nenhuma simulação publica preços.</p>
</section>
<footer class="sub">Serviços independentes: falhas de um marketplace não apagam snapshots dos demais.</footer>
</main>
<script>
for(const channel of ['mercadolivre','shopee','tiktok']){
 fetch('/api/admin/marketplace-margin.php?channel='+channel+'&action=health',{credentials:'same-origin',cache:'no-store'})
 .then(async r=>{if(!r.ok)throw Error(String(r.status));return r.json()})
 .then(data=>{
  document.getElementById('status-'+channel).textContent=data.ok?'API disponível':'API indisponível';
 }).catch(()=>{document.getElementById('status-'+channel).textContent='API ainda não disponível';});
}
</script>
</body></html>
