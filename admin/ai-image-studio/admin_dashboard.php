<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/process_item.php';
require_once __DIR__ . '/src/ImageChannelProfile.php';

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Falha ao conectar ao banco de dados.');
}
svcp_ensure_schema($db);

function ai_studio_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$channelProfiles = ai_studio_channel_profiles();
$batchResults = null;
$batchError = null;
$defaultImageTypes = ['white', 'hero', 'ambient'];
$csrf = sv_csrf_token('ai-image-batch');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'generate_selected') {
    if (!sv_csrf_valid('ai-image-batch', $_POST['csrf_token'] ?? null)) {
        $batchError = 'A sessao expirou. Recarregue a pagina.';
    } else {
        $provider = strtolower(trim((string)($_POST['provider'] ?? '')));
        $model = trim((string)($_POST['model'] ?? ''));
        $targetChannel = strtolower(trim((string)($_POST['target_channel'] ?? 'site')));
        $productIds = array_values(array_unique(array_map('intval', (array)($_POST['product_ids'] ?? []))));

        if (!in_array($provider, ['openai', 'google', 'claude'], true)) {
            $batchError = 'Selecione um provedor valido.';
        } elseif (!isset($channelProfiles[$targetChannel])) {
            $batchError = 'Marketplace de destino invalido.';
        } elseif ($productIds === []) {
            $batchError = 'Nenhum produto selecionado.';
        } else {
            $batchResults = [];
            foreach ($productIds as $productId) {
                $batchResults[] = ai_studio_process_item(
                    $db,
                    $productId,
                    $provider,
                    $defaultImageTypes,
                    $model !== '' ? $model : null,
                    $targetChannel
                );
            }
        }
    }
}

$statusCounts = [];
try {
    $stmt = $db->query('SELECT status, COUNT(*) AS total FROM product_images_staging GROUP BY status');
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $statusCounts[(string)$row['status']] = (int)$row['total'];
    }
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao ler estatisticas: ' . $e->getMessage());
}

$recentItems = [];
try {
    $stmt = $db->query(
        'SELECT id, product_id, image_type, provider_used, status, target_channels_json, created_at '
        . 'FROM product_images_staging ORDER BY created_at DESC LIMIT 20'
    );
    $recentItems = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    error_log('[ai-image-studio] Falha ao ler itens recentes: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>AI Image Studio - ShopVivaliz Admin</title>
<link rel="stylesheet" href="/css/style.css">
<style>
:root{color-scheme:light;--bg:#f4f7fb;--surface:#fff;--surface-soft:#f8fafc;--line:#d8e0ea;--text:#122033;--muted:#607186;--shadow:0 24px 60px rgba(15,23,42,.08);--radius:22px;--violet:#6d28d9;--blue:#1d4ed8;--emerald:#0f9f6e;--amber:#d97706}
*{box-sizing:border-box}
body{margin:0;font-family:"Segoe UI",Arial,sans-serif;background:linear-gradient(180deg,#eef4fb 0%,var(--bg) 36%,#edf2f7 100%);color:var(--text)}
.wrap{max-width:1360px;margin:0 auto;padding:24px 16px 40px}
.hero{background:linear-gradient(135deg,#111827 0%,#312e81 54%,#6d28d9 100%);color:#fff;border-radius:28px;padding:26px;box-shadow:var(--shadow)}
.hero-top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap}
.back-link,.hero-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:999px;padding:10px 14px;font-weight:700}
.back-link{background:#fff;color:#111827}
.hero-link{background:rgba(255,255,255,.10);color:#fff;border:1px solid rgba(255,255,255,.14)}
.eyebrow{margin:0 0 10px;text-transform:uppercase;letter-spacing:.14em;font-size:.74rem;font-weight:800;color:rgba(255,255,255,.72)}
.hero h1{margin:0 0 10px;font-size:clamp(2rem,4vw,3.4rem);line-height:.96}
.hero p{margin:0;max-width:760px;color:rgba(255,255,255,.84);line-height:1.6}
.hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin:18px 0}
.metric{background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:var(--shadow)}
.metric strong{display:block;font-size:1.8rem;margin-bottom:4px}
.metric span{color:var(--muted);font-size:.9rem}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:22px;box-shadow:var(--shadow);margin-bottom:18px}
.panel h2{margin:0 0 10px;font-size:1.28rem}
.panel p{margin:0;color:var(--muted);line-height:1.58}
.toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-top:18px}
.toolbar label{display:grid;gap:6px;font-weight:700;min-width:180px}
.toolbar input,.toolbar select{padding:12px 14px;border:1px solid #c7d2df;border-radius:14px;font:inherit;font-size:15px;background:#fff}
.toolbar .search{min-width:min(360px,100%);flex:1}
.toolbar-actions{display:flex;gap:10px;flex-wrap:wrap}
.toolbar button,.submit-btn,.secondary-btn{border:0;border-radius:999px;padding:12px 16px;font-weight:800;cursor:pointer;font:inherit}
.toolbar button,.secondary-btn{background:#e2e8f0;color:#0f172a}
.submit-btn{background:linear-gradient(135deg,#312e81 0%,#6d28d9 100%);color:#fff}
.alert{padding:14px 16px;border-radius:18px;margin-bottom:14px}
.alert.error{background:#fdecea;color:#611a15}
.alert.info{background:#e8f4fd;color:#0c3b57}
.note{margin-top:14px;padding:14px 16px;border-radius:18px;background:#eef6ff;border-left:4px solid #1769aa;color:#19406c}
.product-shell{margin-top:18px}
.product-header{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.product-header strong{font-size:1rem}
.product-summary,.page-status{color:var(--muted);font-size:.92rem}
.products-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
.product-card{display:flex;gap:12px;align-items:flex-start;padding:16px;border:1px solid var(--line);border-radius:20px;background:var(--surface-soft);transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}
.product-card.is-selected{background:#eef6ff;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(37,99,235,.10)}
.product-card:hover{transform:translateY(-2px);border-color:#a9b8cb;box-shadow:0 16px 32px rgba(15,23,42,.08)}
.product-check{margin-top:6px}
.product-title{font-weight:800;line-height:1.32}
.pager{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}
.pager button{border:0;border-radius:999px;padding:10px 14px;background:#e2e8f0;color:#0f172a;font-weight:800;cursor:pointer}
.empty-state{padding:22px;border:1px dashed #cbd5e1;border-radius:18px;background:#f8fafc;color:#4b5a6e}
.table-wrap{overflow:auto;border-radius:18px;border:1px solid var(--line)}
table{width:100%;border-collapse:collapse;background:#fff;min-width:760px}
th,td{padding:12px 14px;border-bottom:1px solid #edf1f5;text-align:left;font-size:.92rem}
th{background:#f8fafc;color:#425368}
.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:.78rem;font-weight:800}
.badge.ok{background:#dcfce7;color:#166534}
.badge.warn{background:#fef3c7;color:#92400e}
@media(max-width:900px){.wrap{padding:14px 10px 30px}}
</style>
</head>
<body>
<div class="wrap">
    <section class="hero">
        <div class="hero-top">
            <div>
                <a class="back-link" href="/admin/">Voltar ao admin</a>
                <p class="eyebrow" style="margin-top:16px">Geracao guiada por listagem</p>
                <h1>AI Image Studio por marketplace</h1>
                <p>
                    Selecione apenas produtos ativos na listagem, escolha o canal visual e gere em lote somente o que
                    foi marcado. O backend tambem bloqueia item inativo, entao a regra vale mesmo fora da interface.
                </p>
                <div class="hero-actions">
                    <a class="hero-link" href="/admin/ai-image-studio/admin_validate.php">Ir para aprovacao</a>
                    <a class="hero-link" href="/admin/connections.php">Validar credenciais</a>
                </div>
            </div>
        </div>
    </section>

    <section class="metrics">
        <?php foreach (['pending' => 'Pendentes', 'published' => 'Publicadas', 'submitted' => 'Enviadas', 'rejected' => 'Rejeitadas', 'failed' => 'Falhas', 'publication_failed' => 'Falha publicacao'] as $status => $label): ?>
            <div class="metric">
                <strong><?= (int)($statusCounts[$status] ?? 0) ?></strong>
                <span><?= ai_studio_h($label) ?></span>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <h2>Tela 1 - Selecao inicial</h2>
        <p>
            A listagem abaixo usa o mesmo catalogo operacional ativo da loja. Marque os itens, escolha o marketplace
            e envie os IDs selecionados via POST para a geracao em lote.
        </p>

        <?php if ($batchError !== null): ?>
            <div class="alert error" style="margin-top:16px"><?= ai_studio_h($batchError) ?></div>
        <?php endif; ?>

        <?php if ($batchResults !== null): ?>
            <?php
            $productCount = count($batchResults);
            $imageSuccess = 0;
            $imageErrors = 0;
            foreach ($batchResults as $batchResult) {
                foreach ((array)($batchResult['results'] ?? []) as $itemResult) {
                    if (($itemResult['status'] ?? '') === 'pending') {
                        $imageSuccess++;
                    } else {
                        $imageErrors++;
                    }
                }
            }
            ?>
            <div class="alert info" style="margin-top:16px">
                Lote processado: <?= $productCount ?> produto(s), <?= $imageSuccess ?> imagem(ns) em revisao e
                <?= $imageErrors ?> erro(s). O destino foi persistido em cada item.
            </div>
        <?php endif; ?>

        <div class="note">
            O prompt visual passa a ser definido pelo marketplace antes da geracao. Preco e estoque nao entram no fluxo;
            a referencia continua sendo sempre a foto real do mesmo produto.
        </div>

        <form id="image-batch-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= ai_studio_h($csrf) ?>">
            <input type="hidden" name="action" value="generate_selected">

            <div class="toolbar">
                <label>
                    Motor de IA
                    <select name="provider" id="provider">
                        <option value="openai">OpenAI - edicao da foto real</option>
                        <option value="google">Google Gemini - edicao da foto real</option>
                        <option value="claude">Claude otimiza prompt + OpenAI edita</option>
                    </select>
                </label>

                <label>
                    Marketplace de destino
                    <select name="target_channel" id="target_channel">
                        <?php foreach ($channelProfiles as $key => $profile): ?>
                            <option value="<?= ai_studio_h($key) ?>"><?= ai_studio_h((string)$profile['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Modelo de imagem
                    <input type="text" name="model" id="model" value="gpt-image-1">
                </label>

                <label>
                    Categoria
                    <select id="category-filter">
                        <option value="">Todas</option>
                    </select>
                </label>

                <label>
                    Ordenar
                    <select id="sort-by">
                        <option value="relevance">Relevancia</option>
                        <option value="name">Nome</option>
                        <option value="price-asc">Menor preco</option>
                        <option value="price-desc">Maior preco</option>
                    </select>
                </label>

                <label class="search">
                    Busca
                    <input id="product-search" type="search" placeholder="SKU, nome, categoria ou ID">
                </label>

                <label>
                    Itens por pagina
                    <input id="page-size" type="number" min="6" max="48" value="12">
                </label>
            </div>

            <div class="toolbar-actions" style="margin-top:16px">
                <button id="refresh-list" type="button">Atualizar lista</button>
                <button id="clear-selection" type="button">Limpar selecao</button>
                <button class="submit-btn" id="run-selected" type="submit">Gerar selecionados</button>
            </div>

            <div class="product-shell">
                <div class="product-header">
                    <strong>Produtos ativos disponiveis</strong>
                    <span class="product-summary" id="product-summary">Carregando...</span>
                </div>
                <label style="display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;font-weight:800">
                    <input id="select-all-products" type="checkbox">
                    Selecionar todos desta pagina
                </label>

                <div id="products-list" class="products-grid"></div>
                <div id="selected-products-hidden" hidden></div>

                <div class="pager">
                    <button id="prev-page" type="button">Pagina anterior</button>
                    <span class="page-status" id="page-status"></span>
                    <button id="next-page" type="button">Proxima pagina</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Itens recentes</h2>
        <p>Ultimos itens processados para conferencia rapida do staging de imagens.</p>
        <?php if ($recentItems === []): ?>
            <div class="empty-state" style="margin-top:16px">Nenhum item processado ainda.</div>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:16px">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Produto</th>
                            <th>Destino</th>
                            <th>Tipo</th>
                            <th>Provedor</th>
                            <th>Status</th>
                            <th>Criado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentItems as $item): ?>
                            <?php
                            $targets = json_decode((string)($item['target_channels_json'] ?? '[]'), true);
                            $target = is_array($targets) && isset($targets[0]) ? (string)$targets[0] : 'legado';
                            $targetLabel = (string)($channelProfiles[$target]['label'] ?? $target);
                            $statusLabel = (string)$item['status'];
                            $badgeClass = in_array($statusLabel, ['pending', 'published'], true) ? 'ok' : 'warn';
                            ?>
                            <tr>
                                <td>#<?= (int)$item['id'] ?></td>
                                <td>#<?= (int)$item['product_id'] ?></td>
                                <td><?= ai_studio_h($targetLabel) ?></td>
                                <td><?= ai_studio_h((string)$item['image_type']) ?></td>
                                <td><?= ai_studio_h((string)$item['provider_used']) ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= ai_studio_h($statusLabel) ?></span></td>
                                <td><?= ai_studio_h((string)$item['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(() => {
    'use strict';

    const form = document.getElementById('image-batch-form');
    const listEl = document.getElementById('products-list');
    const summaryEl = document.getElementById('product-summary');
    const pageStatusEl = document.getElementById('page-status');
    const categoryFilterEl = document.getElementById('category-filter');
    const sortByEl = document.getElementById('sort-by');
    const providerEl = document.getElementById('provider');
    const modelEl = document.getElementById('model');
    const state = {
        products: [],
        selected: new Set(),
        page: 1,
        totalPages: 1,
        categories: []
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function setModelDefault() {
        const defaults = {
            openai: 'gpt-image-1',
            claude: 'gpt-image-1',
            google: 'gemini-2.5-flash-image'
        };
        if (Object.values(defaults).includes(modelEl.value)) {
            modelEl.value = defaults[providerEl.value] || '';
        }
    }

    function renderCategories(categories) {
        const current = categoryFilterEl.value;
        categoryFilterEl.innerHTML = ['<option value="">Todas</option>']
            .concat(categories.map(cat => `<option value="${escapeHtml(cat)}">${escapeHtml(cat)}</option>`))
            .join('');
        if (categories.includes(current)) {
            categoryFilterEl.value = current;
        }
    }

    function renderSummary() {
        const count = state.selected.size;
        summaryEl.textContent = count === 0
            ? `${state.products.length} ativo(s) carregado(s) nesta pagina`
            : `${count} selecionado(s) · ${state.products.length} ativo(s) na pagina`;
    }

    function syncSelectAllProducts() {
        const master = document.getElementById('select-all-products');
        if (!master) {
            return;
        }
        const visibleIds = state.products.map(product => String(product.id || '')).filter(Boolean);
        master.checked = visibleIds.length > 0 && visibleIds.every(id => state.selected.has(id));
        master.indeterminate = visibleIds.some(id => state.selected.has(id)) && !master.checked;
    }

    function syncSelectedInputs() {
        const hiddenContainer = document.getElementById('selected-products-hidden');
        if (!hiddenContainer) {
            return;
        }
        hiddenContainer.innerHTML = Array.from(state.selected)
            .map(id => `<input type="hidden" name="product_ids[]" value="${escapeHtml(id)}">`)
            .join('');
    }

    function renderList() {
        if (!state.products.length) {
            listEl.innerHTML = '<div class="empty-state">Nenhum produto ativo encontrado nesta busca.</div>';
            pageStatusEl.textContent = '';
            renderSummary();
            syncSelectAllProducts();
            syncSelectedInputs();
            return;
        }

        listEl.innerHTML = state.products.map(product => {
            const id = String(product.id || '');
            const checked = state.selected.has(id) ? 'checked' : '';
            return `
                <label class="product-card ${checked ? 'is-selected' : ''}">
                    <input type="checkbox" class="product-check" value="${escapeHtml(id)}" data-product-id="${escapeHtml(id)}" ${checked}>
                    <div>
                        <div class="product-title">#${escapeHtml(id)} - ${escapeHtml(product.name || 'Produto sem nome')}</div>
                    </div>
                </label>`;
        }).join('');

        listEl.querySelectorAll('.product-check').forEach(input => {
            input.addEventListener('change', event => {
                const id = String(event.currentTarget.dataset.productId || '');
                if (event.currentTarget.checked) {
                    state.selected.add(id);
                } else {
                    state.selected.delete(id);
                }
                event.currentTarget.closest('.product-card')?.classList.toggle('is-selected', event.currentTarget.checked);
                renderSummary();
                syncSelectAllProducts();
            });
        });

        pageStatusEl.textContent = `Pagina ${state.page} de ${state.totalPages}`;
        renderSummary();
        syncSelectAllProducts();
        syncSelectedInputs();
    }

    async function loadProducts(page = 1) {
        summaryEl.textContent = 'Carregando produtos ativos...';
        try {
            const url = new URL('/api/catalog/products.php', window.location.origin);
            const pageSize = Math.max(6, Math.min(48, parseInt(document.getElementById('page-size').value || '12', 10) || 12));
            url.searchParams.set('page', String(page));
            url.searchParams.set('limit', String(pageSize));
            url.searchParams.set('sort', sortByEl.value || 'relevance');
            const query = document.getElementById('product-search').value.trim();
            if (query) {
                url.searchParams.set('q', query);
            }
            const category = categoryFilterEl.value.trim();
            if (category) {
                url.searchParams.set('category', category);
            }

            const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.error || ('HTTP ' + response.status));
            }

            state.products = Array.isArray(payload.products) ? payload.products : [];
            state.page = Number(payload.page || page);
            state.totalPages = Math.max(1, Number(payload.total_pages || 1));
            state.categories = Object.keys(payload.categories || {});
            renderCategories(state.categories);
            renderList();
        } catch (error) {
            listEl.innerHTML = '<div class="empty-state">Erro ao carregar a listagem de produtos ativos.</div>';
            summaryEl.textContent = 'Falha no carregamento';
            pageStatusEl.textContent = '';
        }
    }

    providerEl.addEventListener('change', setModelDefault);
    document.getElementById('refresh-list').addEventListener('click', () => loadProducts(1));
    document.getElementById('product-search').addEventListener('input', () => loadProducts(1));
    document.getElementById('page-size').addEventListener('change', () => loadProducts(1));
    sortByEl.addEventListener('change', () => loadProducts(1));
    categoryFilterEl.addEventListener('change', () => loadProducts(1));
    document.getElementById('prev-page').addEventListener('click', () => {
        if (state.page > 1) {
            loadProducts(state.page - 1);
        }
    });
    document.getElementById('next-page').addEventListener('click', () => {
        if (state.page < state.totalPages) {
            loadProducts(state.page + 1);
        }
    });
    document.getElementById('clear-selection').addEventListener('click', () => {
        state.selected.clear();
        renderList();
    });
    document.getElementById('select-all-products')?.addEventListener('change', event => {
        state.products.forEach(product => {
            const id = String(product.id || '');
            if (!id) {
                return;
            }
            if (event.currentTarget.checked) {
                state.selected.add(id);
            } else {
                state.selected.delete(id);
            }
        });
        renderList();
    });

    form.addEventListener('submit', event => {
        const selectedIds = Array.from(state.selected);
        if (!selectedIds.length) {
            event.preventDefault();
            window.alert('Selecione ao menos um produto ativo para gerar imagens.');
            return;
        }
    });

    setModelDefault();
    loadProducts(1);
})();
</script>
</body>
</html>
