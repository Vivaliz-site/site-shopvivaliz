<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/runtime.php';

$host = (string)(getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost'));
$user = (string)(getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root'));
$pass = (string)(getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : ''));
$dbName = (string)(getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'shopvivaliz'));

$started = microtime(true);
$ok = false;
$error = '';
$total = null;
$db = null;

try {
    $db = new mysqli($host, $user, $pass, $dbName, 3306);
    if ($db->connect_error) {
        throw new RuntimeException($db->connect_error);
    }
    $result = $db->query('SELECT COUNT(*) AS total FROM products');
    if (!$result) {
        throw new RuntimeException($db->error ?: 'Falha ao contar produtos.');
    }
    $row = $result->fetch_assoc();
    $total = (int)($row['total'] ?? 0);
    $result->free();
    $ok = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}

$elapsedMs = (int)round((microtime(true) - $started) * 1000);
$payload = [
    'ok' => $ok,
    'conexao' => $ok ? 'sucesso' : 'falha',
    'produtos' => $total,
    'tempo_ms' => $elapsedMs,
    'host' => $host,
    'db' => $dbName,
    'erro' => $ok ? null : $error,
];

if (strtolower((string)($_GET['format'] ?? '')) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($ok ? 200 : 503);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code($ok ? 200 : 503);
$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Teste do banco — ShopVivaliz Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f4f6fb;color:#111827}.wrap{width:min(860px,calc(100% - 28px));margin:34px auto}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.top a{text-decoration:none;color:#173b63;font-weight:800}.card{margin-top:18px;background:#fff;border:1px solid #e5e9f0;border-radius:16px;padding:24px;box-shadow:0 8px 28px rgba(17,24,39,.07)}.status{display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border-radius:999px;font-weight:800}.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:20px}.metric{padding:16px;border:1px solid #e5e9f0;border-radius:12px;background:#fbfcfe}.metric small{display:block;color:#6b7280;margin-bottom:5px}.metric strong{overflow-wrap:anywhere}.error{margin-top:18px;padding:14px;border-radius:10px;background:#fff1f2;color:#9f1239;overflow-wrap:anywhere}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{padding:10px 14px;border-radius:9px;text-decoration:none;font-weight:800;background:#173b63;color:#fff}.btn.secondary{background:#eef2f7;color:#173b63}h1{margin:0;font-size:clamp(28px,7vw,40px)}@media(max-width:520px){.wrap{margin:20px auto}.card{padding:18px}}
</style>
</head>
<body>
<main class="wrap">
<div class="top"><div><h1>Teste do banco</h1><p>Verificação de conectividade do banco usada pelo Admin.</p></div><a href="/admin/">← Voltar ao Admin</a></div>
<section class="card">
<span class="status <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '● Conexão operacional' : '● Falha de conexão' ?></span>
<div class="grid">
<div class="metric"><small>Banco</small><strong><?= $esc($dbName) ?></strong></div>
<div class="metric"><small>Host</small><strong><?= $esc($host) ?></strong></div>
<div class="metric"><small>Produtos encontrados</small><strong><?= $total === null ? '—' : number_format($total, 0, ',', '.') ?></strong></div>
<div class="metric"><small>Tempo da verificação</small><strong><?= $elapsedMs ?> ms</strong></div>
</div>
<?php if (!$ok): ?><div class="error"><strong>Erro:</strong> <?= $esc($error !== '' ? $error : 'Falha não identificada.') ?></div><?php endif; ?>
<div class="actions"><a class="btn" href="/admin/teste-banco.php">Testar novamente</a><a class="btn secondary" href="/admin/teste-banco.php?format=json">Ver JSON</a><a class="btn secondary" href="/admin/diagnostico-banco.php">Diagnóstico completo</a></div>
</section>
</main>
</body>
</html>
