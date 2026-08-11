<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function svdb_env(string $key, string $fallback = ''): string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : $fallback;
}

function svdb_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function svdb_scalar(mysqli $db, string $sql): int|string|null
{
    $result = $db->query($sql);
    if (!$result) return null;
    $row = $result->fetch_row();
    $result->free();
    return $row[0] ?? null;
}

function svdb_table_summary(mysqli $db, string $table): array
{
    if (!svdb_table_exists($db, $table)) return ['exists' => false];

    $safeTable = str_replace('`', '', $table);
    $count = svdb_scalar($db, 'SELECT COUNT(*) FROM `' . $safeTable . '`');
    $distinctSku = null;
    $columns = [];
    $colResult = $db->query('SHOW COLUMNS FROM `' . $safeTable . '`');
    if ($colResult) {
        while ($row = $colResult->fetch_assoc()) $columns[] = (string)($row['Field'] ?? '');
        $colResult->free();
    }
    if (in_array('sku', $columns, true)) {
        $distinctSku = svdb_scalar($db, 'SELECT COUNT(DISTINCT sku) FROM `' . $safeTable . '` WHERE sku IS NOT NULL AND sku <> ""');
    }

    $indexes = [];
    $idxResult = $db->query('SHOW INDEX FROM `' . $safeTable . '`');
    if ($idxResult) {
        while ($row = $idxResult->fetch_assoc()) {
            $indexes[] = [
                'name' => (string)($row['Key_name'] ?? ''),
                'column' => (string)($row['Column_name'] ?? ''),
                'unique' => ((int)($row['Non_unique'] ?? 1)) === 0,
            ];
        }
        $idxResult->free();
    }

    return [
        'exists' => true,
        'rows' => is_numeric($count) ? (int)$count : $count,
        'distinct_skus' => is_numeric($distinctSku) ? (int)$distinctSku : $distinctSku,
        'columns' => array_values(array_filter($columns)),
        'indexes' => $indexes,
    ];
}

function svdb_recommended_indexes(mysqli $db): array
{
    $recommendations = [
        'products' => [
            'idx_products_sku' => 'ALTER TABLE products ADD INDEX idx_products_sku (sku)',
            'idx_products_status' => 'ALTER TABLE products ADD INDEX idx_products_status (status)',
        ],
        'olist_products' => [
            'idx_olist_products_sku' => 'ALTER TABLE olist_products ADD INDEX idx_olist_products_sku (sku)',
            'idx_olist_products_id' => 'ALTER TABLE olist_products ADD INDEX idx_olist_products_id (id)',
        ],
        'olist_product_images' => [
            'idx_olist_product_images_sku' => 'ALTER TABLE olist_product_images ADD INDEX idx_olist_product_images_sku (sku)',
        ],
        'catalog_optimizations_staging' => [
            'idx_catalog_staging_status_channel' => 'ALTER TABLE catalog_optimizations_staging ADD INDEX idx_catalog_staging_status_channel (status, channel)',
            'idx_catalog_staging_product_channel' => 'ALTER TABLE catalog_optimizations_staging ADD INDEX idx_catalog_staging_product_channel (product_id, channel)',
        ],
    ];

    $missing = [];
    foreach ($recommendations as $table => $indexes) {
        if (!svdb_table_exists($db, $table)) continue;
        $existing = [];
        $idxResult = $db->query('SHOW INDEX FROM `' . str_replace('`', '', $table) . '`');
        if ($idxResult) {
            while ($row = $idxResult->fetch_assoc()) $existing[(string)($row['Key_name'] ?? '')] = true;
            $idxResult->free();
        }
        foreach ($indexes as $name => $sql) {
            if (!isset($existing[$name])) $missing[] = ['table' => $table, 'index' => $name, 'sql' => $sql];
        }
    }
    return $missing;
}

function svdb_output_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function svdb_render_error(string $message, int $status = 503): never
{
    http_response_code($status);
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Diagnóstico do banco — ShopVivaliz Admin</title><style>*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f4f6fb;color:#111827}.wrap{width:min(720px,calc(100% - 28px));margin:40px auto}.card{background:#fff;border:1px solid #fecaca;border-radius:16px;padding:24px;box-shadow:0 8px 28px rgba(17,24,39,.07)}h1{margin-top:0}.bad{color:#991b1b}.btn{display:inline-flex;margin-top:16px;padding:10px 14px;border-radius:9px;background:#173b63;color:#fff;text-decoration:none;font-weight:800}</style></head><body><main class="wrap"><section class="card"><h1>Diagnóstico do banco</h1><p class="bad"><strong>Falha:</strong> ' . $safe . '</p><a class="btn" href="/admin/">Voltar ao Admin</a></section></main></body></html>';
    exit;
}

$wantsJson = strtolower((string)($_GET['format'] ?? '')) === 'json';
$host = svdb_env('DB_HOST', 'localhost');
$user = svdb_env('DB_USER', 'root');
$pass = svdb_env('DB_PASS', '');
$dbName = svdb_env('DB_NAME', 'shopvivaliz');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = @new mysqli($host, $user, $pass, $dbName, 3306);
} catch (Throwable) {
    if ($wantsJson) svdb_output_json(503, ['ok' => false, 'error' => 'database_unavailable']);
    svdb_render_error('Banco de dados indisponível.');
}

if ($db->connect_errno) {
    if ($wantsJson) svdb_output_json(503, ['ok' => false, 'error' => 'database_unavailable']);
    svdb_render_error('Banco de dados indisponível.');
}
$db->set_charset('utf8mb4');

$tables = [];
$tableResult = $db->query('SHOW TABLES');
if ($tableResult) {
    while ($row = $tableResult->fetch_row()) $tables[] = (string)$row[0];
    $tableResult->free();
}

$targetTables = ['products', 'olist_products', 'olist_product_images', 'catalog_optimizations_staging', 'product_channel_content', 'orders'];
$summary = [];
foreach ($targetTables as $table) $summary[$table] = svdb_table_summary($db, $table);
$recommended = svdb_recommended_indexes($db);

$payload = [
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'database' => [
        'host' => $host,
        'name' => $dbName,
        'server_info' => $db->server_info,
        'tables_total' => count($tables),
    ],
    'tables' => $summary,
    'recommended_indexes' => $recommended,
    'notes' => [
        'recommended_indexes_are_dry_run' => true,
        'no_schema_changes_were_applied' => true,
    ],
];
$db->close();

if ($wantsJson) svdb_output_json(200, $payload);

$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Diagnóstico do banco — ShopVivaliz Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f4f6fb;color:#111827}.wrap{width:min(1180px,calc(100% - 28px));margin:28px auto 48px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}.top h1{margin:0;font-size:clamp(28px,6vw,42px)}.top p{margin:7px 0;color:#64748b}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;padding:10px 14px;border-radius:9px;text-decoration:none;font-weight:800;background:#173b63;color:#fff}.btn.secondary{background:#e8eef5;color:#173b63}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:20px 0}.metric,.card{background:#fff;border:1px solid #e5e9f0;border-radius:14px;box-shadow:0 6px 22px rgba(17,24,39,.05)}.metric{padding:16px}.metric small{display:block;color:#64748b;margin-bottom:5px}.metric strong{font-size:20px;overflow-wrap:anywhere}.section-title{margin:28px 0 12px}.table-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.card{padding:18px;min-width:0}.card h3{margin:0 0 12px;color:#173b63;overflow-wrap:anywhere}.rows{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.kv{background:#f8fafc;border-radius:9px;padding:10px}.kv small{display:block;color:#64748b}.kv strong{overflow-wrap:anywhere}.tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.tag{font-size:12px;padding:5px 8px;border-radius:999px;background:#eef2f7;color:#475569}.ok{color:#166534}.missing{color:#991b1b}.recommendation{margin-top:10px;padding:12px;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;overflow:auto}.recommendation code{white-space:pre-wrap;overflow-wrap:anywhere}.empty{padding:18px;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:12px;color:#166534}.note{margin-top:20px;color:#64748b;font-size:13px}@media(max-width:560px){.wrap{width:min(100% - 16px,1180px);margin-top:14px}.card{padding:14px}.rows{grid-template-columns:1fr}.actions .btn{flex:1 1 140px;justify-content:center}}
</style>
</head>
<body>
<main class="wrap">
<div class="top">
  <div><h1>Diagnóstico do banco</h1><p>Leitura segura da estrutura e dos índices. Nenhuma alteração é aplicada nesta tela.</p></div>
  <div class="actions"><a class="btn secondary" href="/admin/">← Admin</a><a class="btn secondary" href="?format=json">Ver JSON</a><a class="btn" href="/admin/diagnostico-banco.php">Atualizar</a></div>
</div>

<section class="summary">
  <div class="metric"><small>Status</small><strong class="ok">● Operacional</strong></div>
  <div class="metric"><small>Banco</small><strong><?= $esc((string)$payload['database']['name']) ?></strong></div>
  <div class="metric"><small>Host</small><strong><?= $esc((string)$payload['database']['host']) ?></strong></div>
  <div class="metric"><small>Tabelas</small><strong><?= (int)$payload['database']['tables_total'] ?></strong></div>
  <div class="metric"><small>Servidor</small><strong><?= $esc((string)$payload['database']['server_info']) ?></strong></div>
</section>

<h2 class="section-title">Tabelas críticas</h2>
<section class="table-grid">
<?php foreach ($summary as $table => $info): ?>
  <article class="card">
    <h3><?= $esc($table) ?></h3>
    <?php if (empty($info['exists'])): ?>
      <p class="missing"><strong>Não encontrada</strong></p>
    <?php else: ?>
      <div class="rows">
        <div class="kv"><small>Linhas</small><strong><?= is_numeric($info['rows'] ?? null) ? number_format((int)$info['rows'], 0, ',', '.') : '—' ?></strong></div>
        <div class="kv"><small>SKUs distintos</small><strong><?= is_numeric($info['distinct_skus'] ?? null) ? number_format((int)$info['distinct_skus'], 0, ',', '.') : '—' ?></strong></div>
        <div class="kv"><small>Colunas</small><strong><?= count((array)($info['columns'] ?? [])) ?></strong></div>
        <div class="kv"><small>Índices</small><strong><?= count((array)($info['indexes'] ?? [])) ?></strong></div>
      </div>
      <div class="tags"><?php foreach (array_slice((array)($info['columns'] ?? []), 0, 12) as $column): ?><span class="tag"><?= $esc((string)$column) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</section>

<h2 class="section-title">Índices recomendados</h2>
<?php if ($recommended === []): ?>
  <div class="empty"><strong>Nenhuma recomendação pendente.</strong> Os índices monitorados já estão presentes.</div>
<?php else: ?>
  <section class="card">
    <p>As sugestões abaixo são somente leitura nesta tela.</p>
    <?php foreach ($recommended as $item): ?><div class="recommendation"><strong><?= $esc((string)$item['table']) ?> · <?= $esc((string)$item['index']) ?></strong><br><code><?= $esc((string)$item['sql']) ?></code></div><?php endforeach; ?>
  </section>
<?php endif; ?>
<p class="note">Gerado em <?= $esc((string)$payload['generated_at']) ?> · Diagnóstico somente leitura.</p>
</main>
</body>
</html>
