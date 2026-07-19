<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

/* proteção mínima por IP ou query-string secret */
$secret = getenv('OLIST_ADMIN_SECRET') ?: '';
if ($secret !== '' && ($_GET['s'] ?? '') !== $secret) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$root = dirname(__DIR__);

/* ── carregar estado atual ── */
function oa_env(string ...$keys): string {
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        foreach ([
            $GLOBALS['root'] . '/.env',
        ] as $f) {
            if (!is_file($f)) continue;
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
            }
        }
        $tf = $GLOBALS['root'] . '/storage/private/tokens.json';
        if (is_file($tf)) {
            $t = json_decode((string)file_get_contents($tf), true) ?: [];
            foreach ($t as $k => $v) {
                if (is_string($k) && is_string($v) && getenv($k) === false) {
                    putenv("$k=$v"); $_ENV[$k] = $v;
                }
            }
        }
    }
    foreach ($keys as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') return $v;
        if (isset($_ENV[$k]) && is_string($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
    }
    return '';
}

$GLOBALS['root'] = $root;

$hasClientId     = oa_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID') !== '';
$hasClientSecret = oa_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET') !== '';
$hasRefresh      = oa_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN') !== '';
$hasV2Token      = oa_env('TOKEN_API_OLIST', 'TINY_API_TOKEN') !== '';

$tokensJson  = $root . '/storage/private/tokens.json';
$tokensData  = is_file($tokensJson) ? json_decode((string)file_get_contents($tokensJson), true) : null;
$tokensAge   = $tokensData ? (string)($tokensData['updated_at'] ?? '?') : null;

/* ── ler histórico de syncs ── */
$historyFile = $root . '/logs/olist-sync-history.jsonl';
$history = [];
if (is_file($historyFile)) {
    $lines = array_filter(array_map('trim', file($historyFile)));
    foreach (array_slice(array_reverse(array_values($lines)), 0, 10) as $l) {
        $d = json_decode($l, true);
        if (is_array($d)) $history[] = $d;
    }
}

/* ── contagem do catálogo ── */
$catalogPath = $root . '/api/catalog/fallback-products.json';
$catalog = is_file($catalogPath) ? json_decode((string)file_get_contents($catalogPath), true) : [];
$catalogCount = is_array($catalog) ? count($catalog) : 0;
$withPrice = is_array($catalog) ? count(array_filter($catalog, fn($p) => (float)($p['price'] ?? 0) > 0)) : 0;

/* ── URL de autorização OAuth ── */
$clientId    = oa_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID');
$redirectUri = oa_env('OLIST_REDIRECT_URI', 'TINY_REDIRECT_URI')
    ?: 'https://shopvivaliz.com.br/olist/callback.php';
$oauthUrl = $clientId !== ''
    ? 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid offline_access',
        'prompt'        => 'consent',
    ])
    : '';

function oa_badge(bool $ok): string {
    return $ok
        ? '<span style="color:#4caf50;font-weight:bold">✅ OK</span>'
        : '<span style="color:#f44336;font-weight:bold">❌ Faltando</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Olist Admin – Vivaliz</title>
<style>
*{box-sizing:border-box}
body{font-family:monospace;background:#0f1117;color:#e0e0e0;margin:0;padding:2rem}
h1{color:#6c63ff;margin-bottom:0.3rem}
h2{color:#90caf9;margin-top:2rem}
table{width:100%;border-collapse:collapse;margin-top:.8rem}
th,td{text-align:left;padding:.5rem .8rem;border-bottom:1px solid #1e2030}
th{color:#888}
.card{background:#1a1d24;border-radius:8px;padding:1.2rem;margin-top:1rem}
.row{display:flex;gap:1rem;flex-wrap:wrap}
.kpi{background:#1a1d24;border-radius:8px;padding:1rem 1.5rem;flex:1;min-width:160px}
.kpi-value{font-size:2rem;color:#90caf9;font-weight:bold}
.kpi-label{color:#888;font-size:.85rem;margin-top:.2rem}
a.btn{display:inline-block;background:#6c63ff;color:#fff;padding:.5rem 1.2rem;border-radius:4px;text-decoration:none;margin:.3rem .3rem 0 0}
a.btn.green{background:#388e3c}
a.btn.orange{background:#e65100}
pre{background:#0a0c12;padding:.8rem;border-radius:4px;overflow-x:auto;font-size:.82rem;color:#aef}
.warn{color:#ffb74d}
</style>
</head>
<body>
<h1>🔗 Olist / Tiny ERP — Admin</h1>
<p style="color:#888">Vivaliz Integration Panel</p>

<div class="row">
  <div class="kpi">
    <div class="kpi-value"><?= $catalogCount ?></div>
    <div class="kpi-label">Produtos no catálogo</div>
  </div>
  <div class="kpi">
    <div class="kpi-value"><?= $withPrice ?></div>
    <div class="kpi-label">Com preço real</div>
  </div>
  <div class="kpi">
    <div class="kpi-value"><?= $catalogCount - $withPrice ?></div>
    <div class="kpi-label">Preço sob consulta</div>
  </div>
</div>

<h2>🔑 Credenciais</h2>
<div class="card">
  <table>
    <tr><th>Secret</th><th>Status</th></tr>
    <tr><td>OLIST_CLIENT_ID</td><td><?= oa_badge($hasClientId) ?></td></tr>
    <tr><td>OLIST_CLIENT_SECRET</td><td><?= oa_badge($hasClientSecret) ?></td></tr>
    <tr><td>OLIST_REFRESH_TOKEN</td><td><?= oa_badge($hasRefresh) ?></td></tr>
    <tr><td>TOKEN_API_OLIST (v2 fallback)</td><td><?= oa_badge($hasV2Token) ?></td></tr>
  </table>
  <?php if ($tokensAge): ?>
  <p style="margin-top:.8rem;color:#888">Tokens renovados em: <strong style="color:#e0e0e0"><?= htmlspecialchars($tokensAge) ?></strong></p>
  <?php endif; ?>
</div>

<h2>🔐 Autorização OAuth</h2>
<div class="card">
<?php if ($oauthUrl): ?>
  <p>Clique abaixo para autorizar o Vivaliz no Tiny ERP e obter novos tokens:</p>
  <a class="btn green" href="<?= htmlspecialchars($oauthUrl) ?>" target="_blank">🚀 Autorizar no Tiny ERP</a>
  <p style="margin-top:.8rem;color:#888">Após autorizar, o callback <code><?= htmlspecialchars($redirectUri) ?></code>
  vai exibir o novo <code>refresh_token</code>. Copie e atualize o secret <code>OLIST_REFRESH_TOKEN</code> no GitHub.</p>
<?php else: ?>
  <p class="warn">⚠️ OLIST_CLIENT_ID não configurado — não é possível iniciar OAuth.</p>
  <p>Configure o secret <code>OLIST_CLIENT_ID</code> no GitHub Actions e no <code>.env</code> do servidor.</p>
<?php endif; ?>
</div>

<h2>🔄 Sincronização</h2>
<div class="card">
  <a class="btn" href="/olist/sync-products.php">▶ Sync completo (v3)</a>
  <a class="btn orange" href="/olist/sync-products.php?v2=1">▶ Sync v2 fallback</a>
  <a class="btn" href="/olist/sync-products.php?dry_run=1" style="background:#37474f">👁 Dry run</a>

  <?php if ($history): ?>
  <h3 style="margin-top:1.2rem;color:#90caf9">Últimas sincronizações</h3>
  <table>
    <tr><th>Data</th><th>Fonte</th><th>Buscados</th><th>Antes</th><th>Depois</th><th>Erros</th></tr>
    <?php foreach ($history as $h): ?>
    <tr>
      <td><?= htmlspecialchars($h['ts'] ?? '') ?></td>
      <td><?= htmlspecialchars($h['source'] ?? '') ?></td>
      <td><?= (int)($h['fetched'] ?? 0) ?></td>
      <td><?= (int)($h['before'] ?? 0) ?></td>
      <td><?= (int)($h['after'] ?? 0) ?></td>
      <td><?= htmlspecialchars(implode(', ', $h['errors'] ?? [])) ?: '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p style="margin-top:.8rem;color:#888">Nenhuma sincronização registrada ainda.</p>
  <?php endif; ?>
</div>

<h2>📋 Status da Integração</h2>
<div class="card">
  <table>
    <tr><th>Componente</th><th>Arquivo</th><th>Status</th></tr>
    <tr>
      <td>OAuth callback</td>
      <td><code>/olist/callback.php</code></td>
      <td><?= oa_badge(is_file($root . '/olist/callback.php')) ?></td>
    </tr>
    <tr>
      <td>Sync produtos</td>
      <td><code>/olist/sync-products.php</code></td>
      <td><?= oa_badge(is_file($root . '/olist/sync-products.php')) ?></td>
    </tr>
    <tr>
      <td>API pedidos → Tiny</td>
      <td><code>/api/orders/create.php</code></td>
      <td><?= oa_badge(is_file($root . '/api/orders/create.php')) ?></td>
    </tr>
    <tr>
      <td>Catálogo JSON</td>
      <td><code>/api/catalog/fallback-products.json</code></td>
      <td><?= oa_badge($catalogCount > 0) ?></td>
    </tr>
    <tr>
      <td>Tokens persistidos</td>
      <td><code>/storage/private/tokens.json</code></td>
      <td><?= oa_badge($tokensData !== null) ?></td>
    </tr>
  </table>
</div>

</body>
</html>
