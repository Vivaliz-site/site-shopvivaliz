<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
/**
 * Admin › Mercado Livre
 * Painel de gerenciamento de integração ML — issues #59, #60, #66
 */
header('Content-Type: text/html; charset=UTF-8');

function svml_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

// Detecta se há tokens salvos
$tokensPath = dirname(__DIR__) . '/storage/private/ml-tokens.json';
$tokens     = null;
$connected  = false;
if (is_file($tokensPath)) {
    $t = json_decode((string)file_get_contents($tokensPath), true);
    if (is_array($t) && !empty($t['access_token'])) {
        $tokens    = $t;
        $connected = true;
    }
}

// Carrega catálogo
$catalogPath = dirname(__DIR__) . '/api/catalog/fallback-products.json';
$products    = [];
if (is_file($catalogPath)) {
    $raw      = file_get_contents($catalogPath);
    $products = $raw ? (json_decode($raw, true) ?? []) : [];
}
$total       = count($products);
$readyCount  = 0;
foreach ($products as $p) {
    if ((float)($p['price'] ?? 0) >= 5 && !empty($p['image_url'])) $readyCount++;
}

// Info do token
$userId    = $tokens ? htmlspecialchars((string)($tokens['user_id'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') : '';
$expiresMs = $tokens ? (int)($tokens['expires_at_ms'] ?? 0) : 0;
$nowMs     = (int)(microtime(true) * 1000);
$tokenOk   = $connected && ($expiresMs === 0 || $expiresMs > $nowMs);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mercado Livre - Admin Vivaliz</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 0; }

        /* Nav */
        .topbar { background: #173B63; color: #fff; padding: 12px 24px; display: flex; align-items: center; gap: 16px; }
        .topbar a { color: #93c5fd; text-decoration: none; font-size: 14px; }
        .topbar a:hover { text-decoration: underline; }
        .topbar-title { font-weight: 700; font-size: 16px; margin-right: auto; }

        /* Layout */
        .page { max-width: 1100px; margin: 32px auto; padding: 0 20px; }
        .page-header { margin-bottom: 28px; }
        .page-header h1 { margin: 0 0 4px; font-size: 26px; color: #173B63; display: flex; align-items: center; gap: 10px; }
        .page-header p { margin: 0; color: #64748b; font-size: 14px; }

        /* Cards */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; }
        .card-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .card-head h2 { margin: 0; font-size: 16px; color: #173B63; }
        .eyebrow { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #94a3b8; margin: 0 0 4px; }

        /* Pills */
        .pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .pill-green  { background: #dcfce7; color: #166534; }
        .pill-red    { background: #fee2e2; color: #991b1b; }
        .pill-yellow { background: #fef3c7; color: #92400e; }
        .pill-blue   { background: #dbeafe; color: #1e40af; }

        /* Stat */
        .stat-value { font-size: 32px; font-weight: 800; color: #173B63; line-height: 1; margin: 8px 0 4px; }
        .stat-label { font-size: 13px; color: #64748b; }

        /* Buttons */
        .btn { display: inline-block; padding: 8px 18px; border-radius: 8px; font-size: 14px; font-weight: 600;
               text-decoration: none; border: none; cursor: pointer; transition: opacity .15s; }
        .btn:hover { opacity: .85; }
        .btn-primary   { background: #173B63; color: #fff; }
        .btn-secondary { background: #f1f5f9; color: #1e293b; }
        .btn-yellow    { background: #f59e0b; color: #fff; }
        .btn-green     { background: #16a34a; color: #fff; }
        .btn-sm        { padding: 5px 12px; font-size: 12px; }
        .btn-group     { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }

        /* Tabela */
        .section-title { font-size: 18px; font-weight: 700; color: #173B63; margin: 32px 0 12px; }
        .table-wrap { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th { background: #f8fafc; padding: 10px 14px; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; }
        tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f8fafc; }
        .thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; background: #e2e8f0; }
        .product-name { font-weight: 600; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Search/filter bar */
        .toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; align-items: center; }
        .toolbar input, .toolbar select { padding: 7px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; background: #fff; }
        .toolbar input { flex: 1 1 220px; }

        /* Optimizer modal */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: none; z-index: 100; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: #fff; border-radius: 14px; padding: 28px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal h3 { margin: 0 0 16px; color: #173B63; }
        .modal label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; color: #475569; }
        .modal input, .modal textarea { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; font-family: inherit; }
        .modal textarea { height: 80px; resize: vertical; }
        .modal-actions { display: flex; gap: 10px; margin-top: 18px; }
        .result-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-top: 16px; font-size: 13px; line-height: 1.7; }
        .result-box h4 { margin: 0 0 8px; font-size: 14px; color: #173B63; }
        .result-box .issue  { color: #991b1b; }
        .result-box .sugg   { color: #166534; }
        .result-box .score  { font-size: 24px; font-weight: 800; color: #173B63; }
        .close-btn { float: right; background: none; border: none; font-size: 20px; cursor: pointer; color: #64748b; line-height: 1; }
        .spinner { display: none; font-size: 13px; color: #64748b; margin-top: 8px; }
        .spinner.active { display: block; }
    </style>
</head>
<body>

<div class="topbar">
    <span class="topbar-title">ShopVivaliz Admin</span>
    <a href="/admin/">← Painel principal</a>
    <a href="/api/ml/me" target="_blank">Testar API</a>
    <a href="/api/ml/products" target="_blank">Ver produtos JSON</a>
</div>

<div class="page">
    <div class="page-header">
        <h1>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#FFD700" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12l2.5 2.5L16 9"/></svg>
            Mercado Livre
        </h1>
        <p>Integração OAuth, catálogo e otimização de anúncios — issues #59, #60, #66</p>
    </div>

    <!-- Status Cards -->
    <div class="cards">

        <!-- Conexão OAuth -->
        <div class="card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Autenticação</p>
                    <h2>Status da conexão</h2>
                </div>
                <?php if ($tokenOk): ?>
                    <span class="pill pill-green">Conectado</span>
                <?php elseif ($connected && !$tokenOk): ?>
                    <span class="pill pill-yellow">Token expirado</span>
                <?php else: ?>
                    <span class="pill pill-red">Desconectado</span>
                <?php endif; ?>
            </div>

            <?php if ($tokenOk): ?>
                <p style="font-size:13px;color:#64748b;margin:0 0 4px;">User ID: <strong><?= $userId ?></strong></p>
                <?php if ($expiresMs > 0): ?>
                    <p style="font-size:12px;color:#94a3b8;margin:0;">
                        Token expira em: <?= date('d/m/Y H:i', (int)($expiresMs / 1000)) ?>
                    </p>
                <?php endif; ?>
                <div class="btn-group">
                    <a class="btn btn-secondary btn-sm" href="/api/ml/login">Reconectar</a>
                    <a class="btn btn-secondary btn-sm" href="/api/ml/me" target="_blank">Testar /me</a>
                </div>
            <?php elseif ($connected && !$tokenOk): ?>
                <p style="font-size:13px;color:#92400e;margin:0 0 12px;">O token expirou. Reconecte para continuar anunciando.</p>
                <div class="btn-group">
                    <a class="btn btn-yellow" href="/api/ml/login">Renovar conexão</a>
                </div>
            <?php else: ?>
                <p style="font-size:13px;color:#64748b;margin:0 0 8px;">Conecte sua conta do Mercado Livre para publicar anúncios diretamente do painel.</p>
                <?php
                $clientId = getenv('ML_CLIENT_ID') ?: '';
                if (!$clientId): ?>
                    <p style="font-size:12px;color:#991b1b;margin:0 0 10px;">
                        ⚠ <code>ML_CLIENT_ID</code> não configurado no <code>.env</code> do servidor.
                    </p>
                <?php endif; ?>
                <div class="btn-group">
                    <a class="btn btn-primary" href="/api/ml/login">Conectar com Mercado Livre</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Catálogo stats -->
        <div class="card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Catálogo</p>
                    <h2>Produtos disponíveis</h2>
                </div>
                <span class="pill pill-blue"><?= $total ?> total</span>
            </div>
            <div style="display:flex;gap:24px;margin:8px 0;">
                <div>
                    <div class="stat-value"><?= $readyCount ?></div>
                    <div class="stat-label">prontos para anunciar</div>
                </div>
                <div>
                    <div class="stat-value"><?= $total - $readyCount ?></div>
                    <div class="stat-label">precisam de ajuste</div>
                </div>
            </div>
            <div class="btn-group">
                <a class="btn btn-secondary btn-sm" href="/api/ml/products?limit=50" target="_blank">Ver JSON</a>
                <a class="btn btn-secondary btn-sm" href="/api/ml/products?min_score=80" target="_blank">Score ≥ 80</a>
            </div>
        </div>

        <!-- Otimizador -->
        <div class="card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Issue #66</p>
                    <h2>Otimizador de anúncios</h2>
                </div>
                <span class="pill pill-blue">IA local</span>
            </div>
            <p style="font-size:13px;color:#64748b;margin:0 0 10px;">
                Analisa título e descrição e sugere melhorias para o algoritmo de busca do ML — palavras-chave, formato, categoria.
            </p>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="openOptimizer()">Analisar produto</button>
            </div>
        </div>

    </div><!-- /cards -->

    <!-- Tabela de produtos -->
    <div class="section-title">Catálogo pronto para o Mercado Livre</div>

    <div class="toolbar">
        <input type="search" id="ml-search" placeholder="Buscar por nome ou SKU..." oninput="filterTable()">
        <select id="ml-cat-filter" onchange="filterTable()">
            <option value="">Todas as categorias</option>
            <?php
            $cats = [];
            foreach ($products as $p) {
                $c = trim($p['category'] ?? '');
                if ($c !== '') $cats[$c] = true;
            }
            ksort($cats);
            foreach (array_keys($cats) as $c):
            ?>
                <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"><?= htmlspecialchars($c, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="ml-ready-filter" onchange="filterTable()">
            <option value="">Todos</option>
            <option value="yes">Prontos para anunciar</option>
            <option value="no">Precisam de ajuste</option>
        </select>
    </div>

    <div class="table-wrap">
        <table id="ml-table">
            <thead>
                <tr>
                    <th></th>
                    <th>Produto</th>
                    <th>SKU</th>
                    <th>Categoria</th>
                    <th>Preço</th>
                    <th>Score</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p):
                $name    = htmlspecialchars($p['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $sku     = htmlspecialchars($p['sku']  ?? '', ENT_QUOTES, 'UTF-8');
                $cat     = htmlspecialchars($p['category'] ?? '', ENT_QUOTES, 'UTF-8');
                $price   = (float)($p['price'] ?? 0);
                $score   = (int)($p['quality_score'] ?? 0);
                $imgUrl  = htmlspecialchars($p['image_url'] ?? '', ENT_QUOTES, 'UTF-8');
                $ready   = $price >= 5 && $imgUrl !== '';
                $pillCls = $ready ? 'pill-green' : 'pill-yellow';
                $pillTxt = $ready ? 'Pronto' : 'Pendente';
                $scoreColor = $score >= 80 ? '#16a34a' : ($score >= 55 ? '#92400e' : '#991b1b');
                $nameRaw = $p['name'] ?? '';
                $descRaw = $p['description'] ?? '';
                $catRaw  = $p['category'] ?? '';
            ?>
                <tr data-name="<?= mb_strtolower($nameRaw) ?>"
                    data-sku="<?= mb_strtolower($sku) ?>"
                    data-cat="<?= mb_strtolower($catRaw) ?>"
                    data-ready="<?= $ready ? 'yes' : 'no' ?>">
                    <td>
                        <?php if ($imgUrl): ?>
                            <img class="thumb" src="<?= $imgUrl ?>" alt="" loading="lazy" onerror="this.style.display='none'">
                        <?php else: ?>
                            <div class="thumb" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:18px;">📦</div>
                        <?php endif; ?>
                    </td>
                    <td><div class="product-name" title="<?= $name ?>"><?= $name ?></div></td>
                    <td style="color:#64748b;font-size:12px;"><?= $sku ?></td>
                    <td><?= $cat ?></td>
                    <td><?= $price > 0 ? 'R$ ' . number_format($price, 2, ',', '.') : '<span style="color:#991b1b">—</span>' ?></td>
                    <td><strong style="color:<?= $scoreColor ?>"><?= $score ?></strong></td>
                    <td><span class="pill <?= $pillCls ?>"><?= $pillTxt ?></span></td>
                    <td>
                        <button class="btn btn-secondary btn-sm"
                            onclick="openOptimizerWith(<?= htmlspecialchars(json_encode($nameRaw), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($descRaw), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($catRaw), ENT_QUOTES) ?>, <?= $price ?>)">
                            Otimizar
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p id="table-count" style="font-size:12px;color:#94a3b8;margin:8px 0 48px;"></p>
</div>

<!-- Modal Otimizador -->
<div class="modal-overlay" id="optimizer-modal">
    <div class="modal">
        <button class="close-btn" onclick="closeOptimizer()">✕</button>
        <h3>Otimizador de Anúncios ML</h3>
        <label>Título do produto</label>
        <input type="text" id="opt-title" placeholder="Ex: 10x Rodízio 35mm Giratório com Freio Gel Anti-Risco">
        <label>Descrição <span style="font-weight:400;color:#94a3b8">(opcional)</span></label>
        <textarea id="opt-desc" placeholder="Descrição do produto..."></textarea>
        <label>Categoria interna</label>
        <input type="text" id="opt-cat" placeholder="Ex: Rodízios">
        <label>Preço (R$)</label>
        <input type="number" id="opt-price" min="0" step="0.01" placeholder="0.00">
        <div class="modal-actions">
            <button class="btn btn-primary" onclick="runOptimizer()">Analisar</button>
            <button class="btn btn-secondary" onclick="closeOptimizer()">Fechar</button>
        </div>
        <div class="spinner" id="opt-spinner">Analisando...</div>
        <div id="opt-result"></div>
    </div>
</div>

<script>
/* ── Filtro de tabela ── */
function filterTable() {
    const q    = document.getElementById('ml-search').value.toLowerCase();
    const cat  = document.getElementById('ml-cat-filter').value.toLowerCase();
    const rdy  = document.getElementById('ml-ready-filter').value;
    const rows = document.querySelectorAll('#ml-table tbody tr');
    let visible = 0;
    rows.forEach(row => {
        const matchQ   = !q   || row.dataset.name.includes(q) || row.dataset.sku.includes(q);
        const matchCat = !cat || row.dataset.cat.includes(cat);
        const matchRdy = !rdy || row.dataset.ready === rdy;
        const show = matchQ && matchCat && matchRdy;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('table-count').textContent = `Mostrando ${visible} de ${rows.length} produtos.`;
}

/* ── Modal Otimizador ── */
function openOptimizer() {
    document.getElementById('opt-title').value  = '';
    document.getElementById('opt-desc').value   = '';
    document.getElementById('opt-cat').value    = '';
    document.getElementById('opt-price').value  = '';
    document.getElementById('opt-result').innerHTML = '';
    document.getElementById('optimizer-modal').classList.add('open');
}

function openOptimizerWith(title, desc, cat, price) {
    document.getElementById('opt-title').value  = title;
    document.getElementById('opt-desc').value   = desc;
    document.getElementById('opt-cat').value    = cat;
    document.getElementById('opt-price').value  = price > 0 ? price : '';
    document.getElementById('opt-result').innerHTML = '';
    document.getElementById('optimizer-modal').classList.add('open');
}

function closeOptimizer() {
    document.getElementById('optimizer-modal').classList.remove('open');
}

async function runOptimizer() {
    const title = document.getElementById('opt-title').value.trim();
    if (!title) { alert('Informe o título do produto.'); return; }

    const spinner = document.getElementById('opt-spinner');
    const result  = document.getElementById('opt-result');
    spinner.classList.add('active');
    result.innerHTML = '';

    try {
        const res = await fetch('/api/ml/optimizer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title,
                description: document.getElementById('opt-desc').value.trim(),
                category: document.getElementById('opt-cat').value.trim(),
                price: parseFloat(document.getElementById('opt-price').value) || 0,
            }),
        });
        const data = await res.json();
        if (!data.ok) { result.innerHTML = `<div class="result-box" style="color:#991b1b">Erro: ${escHtml(data.error)}</div>`; return; }

        const q = data.quality;
        const kw = data.keywords;
        const scoreColor = q.score >= 80 ? '#16a34a' : q.score >= 55 ? '#92400e' : '#991b1b';

        let html = `<div class="result-box">
            <h4>Resultado da análise</h4>
            <p><strong>Título otimizado:</strong><br>
               <span style="color:#173B63;font-weight:600">${escHtml(data.optimized_title)}</span>
               <small style="color:#94a3b8"> (${data.optimized_title.length} chars)</small>
            </p>
            <p><strong>Categoria ML sugerida:</strong> ${escHtml(data.ml_category.name)}
               <code style="font-size:11px;color:#64748b"> ${escHtml(data.ml_category.id)}</code>
            </p>
            <p><strong>Score do anúncio:</strong>
               <span class="score" style="color:${scoreColor}">${q.score}</span>/100 — ${escHtml(q.label)}
            </p>`;

        if (kw.missing_from_title.length) {
            html += `<p><strong>Palavras-chave para adicionar:</strong> <em>${escHtml(kw.missing_from_title.join(', '))}</em></p>`;
        }
        if (q.issues.length) {
            html += `<p><strong>Pontos de melhoria:</strong></p><ul>`;
            q.issues.forEach(i => { html += `<li class="issue">⚠ ${escHtml(i)}</li>`; });
            html += '</ul>';
        }
        if (q.suggestions.length) {
            html += `<ul>`;
            q.suggestions.forEach(s => { html += `<li class="sugg">✓ ${escHtml(s)}</li>`; });
            html += '</ul>';
        }
        html += `<p style="margin:10px 0 0"><strong>Pronto para publicar:</strong>
            <span style="color:${data.ready_to_publish ? '#16a34a' : '#991b1b'};font-weight:600">
                ${data.ready_to_publish ? 'Sim' : 'Não'}
            </span></p>
        </div>`;

        result.innerHTML = html;
    } catch (e) {
        result.innerHTML = `<div class="result-box" style="color:#991b1b">Erro de rede: ${escHtml(e.message)}</div>`;
    } finally {
        spinner.classList.remove('active');
    }
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Fechar modal ao clicar fora
document.getElementById('optimizer-modal').addEventListener('click', function(e) {
    if (e.target === this) closeOptimizer();
});

// Contagem inicial
filterTable();
</script>
</body>
</html>
