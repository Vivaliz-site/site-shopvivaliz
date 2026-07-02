<?php
/**
 * ShopVivaliz - Homepage V18
 * Busca em tempo real (client-side) + filtro por categoria + lazy load
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/constants.php';

$produtos   = [];
$categorias = [];
$cat_counts = [];

$arquivo_produtos = __DIR__ . '/../olist/produtos-olist-array.php';
if (file_exists($arquivo_produtos)) {
    include $arquivo_produtos;
    if (!empty($GLOBALS['produtos_olist'])) {
        $todos = $GLOBALS['produtos_olist'];

        // Ordena por score: tem imagem (2pts) + tem preço (1pt)
        usort($todos, function($a, $b) {
            $sa = (!empty($a['url_imagem']) ? 2 : 0) + ($a['preco'] > 0 ? 1 : 0);
            $sb = (!empty($b['url_imagem']) ? 2 : 0) + ($b['preco'] > 0 ? 1 : 0);
            return $sb - $sa;
        });

        // Top 96 para busca client-side
        $produtos = array_slice($todos, 0, 96);

        // Contagem por categoria (top 8)
        foreach ($todos as $p) {
            $cat = trim($p['categoria'] ?? '');
            if ($cat !== '') $cat_counts[$cat] = ($cat_counts[$cat] ?? 0) + 1;
        }
        arsort($cat_counts);
        $categorias = array_slice($cat_counts, 0, 8, true);
    }
}

$total_geral = !empty($GLOBALS['produtos_olist']) ? count($GLOBALS['produtos_olist']) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ShopVivaliz — Loja online com <?= $total_geral ?: 198 ?> produtos de qualidade. Entrega rápida e segura.">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ShopVivaliz">
    <meta property="og:title" content="ShopVivaliz — Sua Loja Online de Confiança">
    <meta property="og:description" content="<?= $total_geral ?: 198 ?> produtos de qualidade com entrega rápida para todo o Brasil. Encontre o que você precisa com ótimos preços.">
    <meta property="og:url" content="https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br') ?>/">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="ShopVivaliz — Sua Loja Online de Confiança">
    <meta name="twitter:description" content="<?= $total_geral ?: 198 ?> produtos com entrega rápida para todo o Brasil.">
    <title>ShopVivaliz — Sua Loja Online de Confiança</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="/css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',sans-serif;background:#f8f9fa;color:#1f2937}
        a{text-decoration:none;color:inherit}

        /* NAVBAR */
        .sv-nav{background:#1e3a5f;padding:0 20px;display:flex;align-items:center;justify-content:space-between;height:56px;position:sticky;top:0;z-index:100}
        .sv-nav-brand{color:white;font-size:1.25rem;font-weight:700}
        .sv-nav-links{display:flex;gap:20px;align-items:center}
        .sv-nav-links a{color:rgba(255,255,255,.85);font-size:.9rem;transition:color .2s}
        .sv-nav-links a:hover{color:white}
        .sv-cart-btn{background:#22c55e;color:white!important;padding:6px 14px;border-radius:20px;font-weight:600;font-size:.85rem;display:flex;align-items:center;gap:6px}
        .sv-cart-count{background:white;color:#1e3a5f;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;display:none}
        @media(max-width:640px){.sv-nav-links a:not(.sv-cart-btn){display:none}}

        /* HERO */
        .hero{background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 60%,#22c55e 140%);color:white;padding:60px 20px 48px;text-align:center}
        .hero h1{font-size:clamp(1.8rem,5vw,3rem);font-weight:800;margin-bottom:12px}
        .hero p{font-size:1.1rem;opacity:.9;margin-bottom:32px}
        .hero-search{max-width:520px;margin:0 auto;position:relative}
        .hero-search input{width:100%;padding:14px 120px 14px 18px;border-radius:8px;border:none;font-size:1rem;outline:none}
        .hero-search button{position:absolute;right:6px;top:50%;transform:translateY(-50%);padding:9px 16px;background:#22c55e;color:white;border:none;border-radius:6px;font-weight:700;font-size:.9rem;cursor:pointer;white-space:nowrap}
        .hero-search button:hover{background:#16a34a}
        .hero-stats{margin-top:20px;font-size:.9rem;opacity:.75}
        .hero-results{margin-top:10px;font-size:.85rem;color:rgba(255,255,255,.8);min-height:20px}

        /* CATEGORIAS */
        .sv-cats{background:white;padding:20px;border-bottom:1px solid #e5e7eb}
        .sv-cats-inner{max-width:1200px;margin:0 auto;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .sv-cats-label{font-size:.8rem;font-weight:600;color:#6b7280;white-space:nowrap}
        .sv-cat-chip{padding:6px 14px;border:1.5px solid #e5e7eb;border-radius:20px;font-size:.82rem;font-weight:500;cursor:pointer;transition:all .2s;white-space:nowrap;background:none;font-family:inherit;color:inherit}
        .sv-cat-chip:hover,.sv-cat-chip.active{border-color:#2563eb;color:#2563eb;background:#eff6ff}
        .sv-cat-count{font-size:.7rem;color:#9ca3af;margin-left:3px}
        .sv-cat-chip.active .sv-cat-count{color:#93c5fd}

        /* SEÇÃO PRODUTOS */
        .sv-section{max-width:1200px;margin:0 auto;padding:40px 20px}
        .sv-section-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;flex-wrap:wrap;gap:10px}
        .sv-section-head h2{font-size:1.5rem;font-weight:700}
        .sv-result-count{font-size:.85rem;color:#6b7280;margin-top:4px}
        .sv-see-all{color:#2563eb;font-size:.9rem;font-weight:600;border:1.5px solid #2563eb;padding:6px 16px;border-radius:6px;transition:all .2s;white-space:nowrap}
        .sv-see-all:hover{background:#2563eb;color:white}

        /* GRID */
        .sv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:20px}
        .sv-card{background:white;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:transform .25s,box-shadow .25s;display:flex;flex-direction:column}
        .sv-card:hover{transform:translateY(-4px);box-shadow:0 6px 20px rgba(0,0,0,.12)}
        .sv-card.sv-hidden{display:none!important}
        .sv-card-img{height:180px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative}
        .sv-card-img img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
        .sv-card:hover .sv-card-img img{transform:scale(1.04)}
        .sv-card-img-placeholder{font-size:3rem;color:#9ca3af}
        .sv-card-body{padding:16px;flex:1;display:flex;flex-direction:column;gap:6px}
        .sv-card-cat{font-size:.68rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
        .sv-card-name{font-size:.9rem;font-weight:600;color:#1f2937;line-height:1.4;flex:1}
        .sv-card-price{font-size:1.3rem;font-weight:800;color:#1e3a5f}
        .sv-card-price-empty{font-size:.85rem;color:#9ca3af}
        .sv-card-actions{display:flex;gap:8px;margin-top:4px}
        .sv-btn-detail{flex:0 0 auto;padding:9px 12px;border:1.5px solid #1e3a5f;color:#1e3a5f;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;background:white;transition:all .2s}
        .sv-btn-detail:hover{background:#1e3a5f;color:white}
        .sv-btn-cart{flex:1;padding:9px 0;background:#22c55e;color:white;border:none;border-radius:6px;font-size:.85rem;font-weight:700;cursor:pointer;transition:background .2s}
        .sv-btn-cart:hover{background:#16a34a}
        .sv-btn-cart.added{background:#16a34a}

        /* SEM RESULTADOS / LOAD MORE */
        .sv-no-results{grid-column:1/-1;text-align:center;padding:48px 20px;color:#6b7280}
        .sv-no-results a{color:#2563eb;font-weight:600}
        .sv-load-more{text-align:center;padding:28px 0 8px}
        .sv-load-more-btn{padding:11px 32px;background:#1e3a5f;color:white;border:none;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer;transition:background .2s;font-family:inherit}
        .sv-load-more-btn:hover{background:#2563eb}

        /* TOAST */
        .sv-toast{position:fixed;bottom:24px;right:24px;background:#1f2937;color:white;padding:14px 20px;border-radius:10px;font-size:.9rem;font-weight:600;opacity:0;transform:translateY(10px);transition:all .3s;z-index:999;pointer-events:none;max-width:280px}
        .sv-toast.show{opacity:1;transform:translateY(0)}

        /* RODAPÉ */
        .sv-footer{background:#1e3a5f;color:rgba(255,255,255,.7);text-align:center;padding:24px 20px;font-size:.85rem;margin-top:60px}
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="sv-nav">
    <a href="/" class="sv-nav-brand">🛍️ ShopVivaliz</a>
    <div class="sv-nav-links">
        <a href="/claude/catalogo/">Catálogo</a>
        <a href="/claude/carrinho/" class="sv-cart-btn">
            🛒 Carrinho
            <span class="sv-cart-count" id="cartCount">0</span>
        </a>
    </div>
</nav>

<!-- HERO -->
<section class="hero">
    <h1>ShopVivaliz</h1>
    <p>Produtos de qualidade com entrega rápida e segura</p>
    <div class="hero-search">
        <input type="text" id="searchInput" placeholder="O que você está procurando?" autocomplete="off" aria-label="Buscar produtos">
        <button type="button" onclick="submitSearch()">🔍 Buscar</button>
    </div>
    <div class="hero-stats"><?= $total_geral ?: 198 ?> produtos disponíveis • Frete para todo o Brasil</div>
    <div class="hero-results" id="heroResults"></div>
</section>

<!-- ATALHOS DE CATEGORIA -->
<?php if (!empty($categorias)): ?>
<div class="sv-cats">
    <div class="sv-cats-inner">
        <span class="sv-cats-label">Categorias:</span>
        <button class="sv-cat-chip active" data-cat="" onclick="filterByCategory(this,'')">Todos</button>
        <?php foreach ($categorias as $cat => $count): ?>
            <button class="sv-cat-chip" data-cat="<?= htmlspecialchars($cat) ?>"
                onclick="filterByCategory(this,<?= json_encode($cat) ?>)">
                <?= htmlspecialchars($cat) ?><span class="sv-cat-count">(<?= $count ?>)</span>
            </button>
        <?php endforeach; ?>
        <a href="/claude/catalogo/" class="sv-cat-chip" style="border-color:#2563eb;color:#2563eb">Ver todas →</a>
    </div>
</div>
<?php endif; ?>

<!-- PRODUTOS EM DESTAQUE -->
<section class="sv-section">
    <div class="sv-section-head">
        <div>
            <h2 id="sectionTitle">Produtos em Destaque</h2>
            <div class="sv-result-count" id="resultCount"><?= count($produtos) ?> produtos</div>
        </div>
        <a href="/claude/catalogo/" class="sv-see-all" id="seeAllLink">Ver todos →</a>
    </div>

    <?php if (empty($produtos)): ?>
        <p style="text-align:center;color:#6b7280;padding:40px">Carregando produtos...</p>
    <?php else: ?>
    <div class="sv-grid" id="prodGrid">
        <?php foreach ($produtos as $i => $p):
            $id       = htmlspecialchars($p['id'] ?? '');
            $nome     = htmlspecialchars($p['nome'] ?? 'Produto');
            $nome_curto = htmlspecialchars(mb_strimwidth($p['nome'] ?? 'Produto', 0, 55, '…'));
            $preco    = $p['preco'] > 0 ? 'R$ ' . number_format($p['preco'], 2, ',', '.') : '';
            $img      = htmlspecialchars($p['url_imagem'] ?? '');
            $cat      = htmlspecialchars($p['categoria'] ?? '');
            $sku      = htmlspecialchars($p['id'] ?? '');
            $preco_js = (float)($p['preco'] ?? 0);
            $hidden   = $i >= 24 ? ' sv-hidden' : '';
            $nome_lc  = htmlspecialchars(mb_strtolower($p['nome'] ?? ''));
        ?>
        <div class="sv-card<?= $hidden ?>"
             data-idx="<?= $i ?>"
             data-cat="<?= $cat ?>"
             data-name="<?= $nome_lc ?>">
            <a href="/claude/catalogo/produto.php?id=<?= $id ?>">
                <div class="sv-card-img">
                    <?php if ($img): ?>
                        <img src="<?= $img ?>" alt="<?= $nome ?>" loading="lazy"
                             onerror="this.parentNode.innerHTML='<span class=sv-card-img-placeholder>📦</span>'">
                    <?php else: ?>
                        <span class="sv-card-img-placeholder">📦</span>
                    <?php endif; ?>
                </div>
            </a>
            <div class="sv-card-body">
                <?php if ($cat): ?><div class="sv-card-cat"><?= $cat ?></div><?php endif; ?>
                <div class="sv-card-name"><?= $nome_curto ?></div>
                <?php if ($preco): ?>
                    <div class="sv-card-price"><?= $preco ?></div>
                <?php else: ?>
                    <div class="sv-card-price-empty">Preço sob consulta</div>
                <?php endif; ?>
                <div class="sv-card-actions">
                    <a href="/claude/catalogo/produto.php?id=<?= $id ?>">
                        <button class="sv-btn-detail" type="button">Ver</button>
                    </a>
                    <button class="sv-btn-cart" type="button"
                        data-sku="<?= $sku ?>"
                        data-name="<?= $nome ?>"
                        data-price="<?= $preco_js ?>"
                        data-img="<?= $img ?>"
                        onclick="addToCart(this)">
                        🛒 Adicionar
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div id="noResults" class="sv-no-results sv-hidden">
            <p style="font-size:1.1rem;margin-bottom:8px">Nenhum produto encontrado</p>
            <p><a id="noResultsLink" href="/claude/catalogo/">Ver todos os produtos no catálogo →</a></p>
        </div>
    </div>

    <div class="sv-load-more" id="loadMoreWrap" style="display:<?= count($produtos) > 24 ? 'block' : 'none' ?>">
        <button class="sv-load-more-btn" onclick="loadMore()">Ver mais produtos ▾</button>
    </div>
    <?php endif; ?>
</section>

<!-- RODAPÉ -->
<footer class="sv-footer">
    &copy; <?= date('Y') ?> ShopVivaliz. Todos os direitos reservados.
</footer>

<!-- TOAST -->
<div class="sv-toast" id="svToast"></div>

<script>
(function(){
    var CART_KEY  = 'shopvivaliz_cart';
    var PAGE_SIZE = 24;
    var cards     = Array.from(document.querySelectorAll('#prodGrid .sv-card'));
    var filtered  = cards.slice();
    var shown     = 0;
    var activeCat = '';
    var activeTerm= '';

    /* ─── Cart ─── */
    function getCart(){return JSON.parse(localStorage.getItem(CART_KEY)||'[]');}
    function saveCart(c){localStorage.setItem(CART_KEY,JSON.stringify(c));}

    function updateBadge(){
        var n=getCart().reduce(function(s,i){return s+(i.quantity||1);},0);
        var el=document.getElementById('cartCount');
        if(el){el.textContent=n;el.style.display=n>0?'inline-flex':'none';}
    }

    function showToast(msg){
        var t=document.getElementById('svToast');
        t.textContent=msg;t.classList.add('show');
        setTimeout(function(){t.classList.remove('show');},2800);
    }

    window.addToCart=function(btn){
        var sku=btn.dataset.sku,name=btn.dataset.name,
            price=parseFloat(btn.dataset.price||0),img=btn.dataset.img;
        var cart=getCart();
        var ex=cart.find(function(i){return i.sku===sku;});
        if(ex){ex.quantity=(ex.quantity||1)+1;}
        else{cart.push({sku:sku,name:name,price:price,image_url:img,olist_product_id:sku,quantity:1});}
        saveCart(cart);updateBadge();
        showToast('✅ Adicionado: '+name.substring(0,30)+'…');
        btn.textContent='✓ Adicionado';btn.classList.add('added');
        setTimeout(function(){btn.textContent='🛒 Adicionar';btn.classList.remove('added');},2000);
    };

    /* ─── Filter ─── */
    function applyFilters(){
        cards.forEach(function(c){c.classList.add('sv-hidden');});

        filtered = cards.filter(function(c){
            var catOk  = activeCat  === '' || c.dataset.cat  === activeCat;
            var termOk = activeTerm === '' || c.dataset.name.indexOf(activeTerm) !== -1;
            return catOk && termOk;
        });

        var noRes = document.getElementById('noResults');
        if(filtered.length === 0){
            noRes.classList.remove('sv-hidden');
            var q = activeTerm || activeCat;
            document.getElementById('noResultsLink').href =
                '/claude/catalogo/?' + (activeTerm ? 'busca' : 'categoria') + '=' + encodeURIComponent(q);
        } else {
            noRes.classList.add('sv-hidden');
        }

        shown = Math.min(PAGE_SIZE, filtered.length);
        for(var i=0;i<shown;i++) filtered[i].classList.remove('sv-hidden');

        var lm=document.getElementById('loadMoreWrap');
        if(lm) lm.style.display = filtered.length > PAGE_SIZE ? 'block' : 'none';

        var countEl=document.getElementById('resultCount');
        if(countEl){
            var label = filtered.length + ' produto' + (filtered.length===1?'':'s');
            if(activeTerm || activeCat) label += ' encontrado' + (filtered.length===1?'':'s');
            countEl.textContent = label;
        }

        var heroRes=document.getElementById('heroResults');
        if(heroRes){
            heroRes.textContent = activeTerm && filtered.length > 0
                ? filtered.length+' resultado'+(filtered.length===1?'':'s')+' encontrado'+(filtered.length===1?'':'s')
                : '';
        }

        var seeAll=document.getElementById('seeAllLink');
        if(seeAll){
            if(activeTerm){
                seeAll.href='/claude/catalogo/?busca='+encodeURIComponent(activeTerm);
                seeAll.textContent='Ver todos no catálogo →';
            } else if(activeCat){
                seeAll.href='/claude/catalogo/?categoria='+encodeURIComponent(activeCat);
                seeAll.textContent='Ver '+activeCat+' →';
            } else {
                seeAll.href='/claude/catalogo/';
                seeAll.textContent='Ver todos →';
            }
        }
    }

    window.loadMore=function(){
        var next=Math.min(shown+PAGE_SIZE, filtered.length);
        for(var i=shown;i<next;i++) filtered[i].classList.remove('sv-hidden');
        shown=next;
        var lm=document.getElementById('loadMoreWrap');
        if(lm) lm.style.display = shown < filtered.length ? 'block' : 'none';
    };

    window.filterByCategory=function(btn, cat){
        activeCat=cat;
        document.querySelectorAll('.sv-cat-chip').forEach(function(c){c.classList.remove('active');});
        btn.classList.add('active');
        var title=document.getElementById('sectionTitle');
        if(title) title.textContent = cat || 'Produtos em Destaque';
        applyFilters();
    };

    /* ─── Search ─── */
    var debounce;
    var inp=document.getElementById('searchInput');
    if(inp){
        inp.addEventListener('input',function(){
            clearTimeout(debounce);
            debounce=setTimeout(function(){
                activeTerm=inp.value.toLowerCase().trim();
                var title=document.getElementById('sectionTitle');
                if(title) title.textContent = activeTerm
                    ? 'Resultados para "'+inp.value.trim()+'"'
                    : (activeCat || 'Produtos em Destaque');
                applyFilters();
            },220);
        });
        inp.addEventListener('keydown',function(e){
            if(e.key==='Enter'){
                clearTimeout(debounce);
                var q=inp.value.trim();
                if(q) location.href='/claude/catalogo/?busca='+encodeURIComponent(q);
            }
        });
    }

    window.submitSearch=function(){
        var q=(document.getElementById('searchInput')||{}).value||'';
        q=q.trim();
        if(q) location.href='/claude/catalogo/?busca='+encodeURIComponent(q);
    };

    /* ─── Init ─── */
    updateBadge();
    shown = Math.min(PAGE_SIZE, cards.length);
    var countEl=document.getElementById('resultCount');
    if(countEl) countEl.textContent = cards.length+' produtos em destaque';
})();
</script>
</body>
</html>
