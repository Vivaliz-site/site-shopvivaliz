<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
/**
 * Auditoria de imagens do catálogo por SKU.
 *
 * Esta tela nao deve exibir credenciais. Ela apenas lista produtos sem imagem real
 * (foto do produto vinda do ERP/Tiny) no catálogo espelhado do site.
 */

$catalogPath = dirname(__DIR__) . '/api/catalog/fallback-products.json';
$catalog = [];
$error = null;

try {
    if (!is_file($catalogPath)) {
        throw new RuntimeException('Catálogo não encontrado em api/catalog/fallback-products.json.');
    }
    $decoded = json_decode((string)file_get_contents($catalogPath), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Catálogo inválido ou vazio.');
    }
    $catalog = $decoded;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$semImagem = array_values(array_filter($catalog, function ($p) {
    if (!is_array($p)) return false;
    $img = trim((string)($p['image_url'] ?? ''));
    return $img === '';
}));

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Auditoria imagens do catálogo</title>
  <style>body{font-family:Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px}th{background:#f6f6f6;text-align:left}.err{color:#b00020}</style>
</head>
<body>
  <h1>Auditoria de imagens do catálogo por SKU</h1>
  <p>Origem oficial: cadastro ERP (Tiny), sincronizado em <code>api/catalog/fallback-products.json</code>. Chave principal: SKU.</p>
  <?php if ($error): ?>
    <p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
  <?php else: ?>
    <p>Total no catálogo: <?= count($catalog) ?> · Produtos sem imagem real: <strong><?= count($semImagem) ?></strong></p>
    <table>
      <thead><tr><th>SKU</th><th>Nome</th><th>Categoria</th><th>Estoque</th></tr></thead>
      <tbody>
      <?php foreach ($semImagem as $row): ?>
        <tr>
          <td><?= htmlspecialchars((string)($row['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($row['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)($row['stock'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$semImagem): ?>
        <tr><td colspan="4">Todos os produtos do catálogo têm imagem real. 🎉</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <p style="margin-top:16px;color:#666;font-size:13px;">Para corrigir: cadastre a foto do produto no Tiny ERP. O próximo sync automático (~10 min) reflete aqui.</p>
  <?php endif; ?>
</body>
</html>
