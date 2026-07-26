<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos - Admin</title>
    <link rel="icon" type="image/png" href="/images/logo-vivaliz-square.png">
    <link rel="apple-touch-icon" href="/images/logo-vivaliz-square.png">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body{background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto}.navbar{background:#1a1a2e;padding:1rem;color:#fff}.container{max-width:1200px;margin:0 auto;padding:2rem}.page-title{font-size:2rem;margin-bottom:2rem;color:#333}.btn{padding:.75rem 1.5rem;background:#667eea;color:#fff;border:0;border-radius:6px;cursor:pointer;text-decoration:none;display:inline-block}.btn:hover{background:#5568d3}.products-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}.products-table th{background:#f8f9fa;padding:1rem;text-align:left;font-weight:600;border-bottom:2px solid #dee2e6}.products-table td{padding:1rem;border-bottom:1px solid #dee2e6}.products-table tr:hover{background:#f8f9fa}.actions{display:flex;gap:.5rem}.btn-small{padding:.5rem 1rem;font-size:.9rem}.btn-edit{background:#667eea}.empty-state{text-align:center;padding:3rem;color:#666}.admin-searchbar{display:flex;gap:.75rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap}.admin-searchbar input{flex:1 1 320px;padding:.85rem 1rem;border:1px solid #d1d5db;border-radius:8px;font-size:1rem;background:#fff;color:#333}.admin-searchbar input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.12)}.admin-search-meta{color:#6b7280;font-size:.95rem;white-space:nowrap}
    </style>
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
</head>
<body>
<div class="navbar"><div class="container"><div style="display:flex;justify-content:space-between;align-items:center"><div>🛍️ ShopVivaliz Admin / Produtos</div><a href="/admin/" style="color:#fff;text-decoration:none">← Voltar</a></div></div></div>
<div class="container">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem"><h1 class="page-title">Gestão de Produtos</h1></div>
    <div style="background:#fff8e6;border:1px solid #f0d78c;padding:1rem 1.5rem;border-radius:8px;margin-bottom:2rem;color:#5c4a09">ℹ️ O catálogo é sincronizado automaticamente do ERP (Tiny). Para cadastrar um produto novo, alterar preço/estoque na origem ou trocar categoria, faça isso diretamente no Tiny — o próximo sync replica para o site.</div>
    <div class="admin-searchbar"><input type="search" id="product-search" placeholder="Buscar por SKU, nome ou categoria" autocomplete="off" aria-label="Buscar produto no admin"><div class="admin-search-meta" id="product-search-meta">Carregando...</div></div>
    <div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><table class="products-table"><thead><tr><th>SKU</th><th>Nome</th><th>Preço</th><th>Estoque</th><th>Ações</th></tr></thead><tbody id="products-body"><tr><td colspan="5" class="empty-state">Carregando produtos...</td></tr></tbody></table><div id="products-pager" style="padding:1rem;text-align:center;border-top:1px solid #dee2e6"></div></div>
</div>
<script>
(async()=>{const tbody=document.getElementById('products-body'),searchInput=document.getElementById('product-search'),searchMeta=document.getElementById('product-search-meta');const esc=value=>{const div=document.createElement('div');div.textContent=String(value??'');return div.innerHTML};try{const r=await fetch('/api/catalog/products.php?limit=200',{cache:'no-store'});if(!r.ok)throw new Error('HTTP '+r.status);const data=await r.json();const allProducts=Array.isArray(data.products)?data.products:[];if(!allProducts.length){tbody.innerHTML='<tr><td colspan="5" class="empty-state">Nenhum produto ativo encontrado no catálogo</td></tr>';searchMeta.textContent='0 produtos';return}const PAGE_SIZE=20;let currentPage=1,filtered=allProducts.slice();function render(){const start=(currentPage-1)*PAGE_SIZE,pageItems=filtered.slice(start,start+PAGE_SIZE),totalPages=Math.max(1,Math.ceil(filtered.length/PAGE_SIZE));searchMeta.textContent=searchInput.value.trim()?`${filtered.length} resultado(s) para "${searchInput.value.trim()}"`:`${filtered.length} produtos`;tbody.innerHTML=pageItems.length?pageItems.map(p=>`<tr><td><strong>${esc(p.sku)}</strong></td><td>${esc(p.name)}</td><td>R$ ${Number(p.price||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>${Number(p.stock||0)}</td><td><div class="actions"><a href="/admin/editar-produto.php?id=${encodeURIComponent(p.olist_product_id||p.id||'')}" class="btn btn-small btn-edit">✏️ Editar</a></div></td></tr>`).join(''):'<tr><td colspan="5" class="empty-state">Nenhum produto encontrado para esta busca</td></tr>';const pager=document.getElementById('products-pager');pager.innerHTML=`<button class="btn btn-small" ${currentPage<=1?'disabled':''} id="pager-prev">← Anterior</button><span style="margin:0 1rem">Página ${currentPage} de ${totalPages} (${filtered.length} produtos)</span><button class="btn btn-small" ${currentPage>=totalPages?'disabled':''} id="pager-next">Próxima →</button>`;document.getElementById('pager-prev')?.addEventListener('click',()=>{if(currentPage>1){currentPage--;render()}});document.getElementById('pager-next')?.addEventListener('click',()=>{if(currentPage<totalPages){currentPage++;render()}})}searchInput.addEventListener('input',()=>{const q=searchInput.value.toLowerCase().trim();filtered=q?allProducts.filter(p=>[p.sku,p.name,p.category,p.olist_product_id,p.id].join(' ').toLowerCase().includes(q)):allProducts.slice();currentPage=1;render()});render()}catch(e){console.error('[admin/produtos]',e);tbody.innerHTML='<tr><td colspan="5" class="empty-state">Erro ao carregar produtos. Verifique o endpoint do catálogo.</td></tr>';searchMeta.textContent='Erro de carregamento'}})();
</script>
</body>
</html>
