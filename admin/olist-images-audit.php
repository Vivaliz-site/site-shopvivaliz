<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
/**
 * v9.2.86 - Auditoria de imagens Olist por SKU.
 *
 * Esta tela nao deve exibir credenciais. Ela apenas lista produtos sem imagem local
 * e orienta a importacao por SKU usando o cadastro ERP/Olist como origem oficial.
 */

require_once __DIR__ . '/../includes/product-image-resolver.php';

function olist_images_pdo(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    $candidates = [
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../includes/db.php',
        __DIR__ . '/../config.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                return $GLOBALS['pdo'];
            }
            if (isset($pdo) && $pdo instanceof PDO) {
                return $pdo;
            }
        }
    }

    throw new RuntimeException('Conexao PDO nao localizada.');
}

function olist_images_detect_products_table(PDO $pdo): ?string
{
    foreach (['products', 'produtos', 'olist_products'] as $table) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->fetchColumn()) {
            return $table;
        }
    }
    return null;
}

$pdo = olist_images_pdo();
$table = olist_images_detect_products_table($pdo);
$rows = [];
$error = null;

try {
    if (!$table) {
        throw new RuntimeException('Tabela de produtos nao localizada.');
    }

    $sql = "SELECT p.* FROM {$table} p LEFT JOIN olist_product_images i ON i.sku = p.sku AND i.status = 'active' WHERE p.sku IS NOT NULL GROUP BY p.sku HAVING COUNT(i.id) = 0 ORDER BY p.sku LIMIT 200";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Auditoria imagens Olist</title>
  <style>body{font-family:Arial,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px}th{background:#f6f6f6;text-align:left}.err{color:#b00020}</style>
</head>
<body>
  <h1>Auditoria imagens Olist por SKU</h1>
  <p>Origem oficial: cadastro ERP/Olist. Chave principal: SKU.</p>
  <?php if ($error): ?>
    <p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
  <?php else: ?>
    <p>Produtos sem imagem reconciliada: <?= count($rows) ?></p>
    <table>
      <thead><tr><th>SKU</th><th>Nome</th><th>Imagem resolvida</th><th>Acao</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <?php $image = product_image_resolve($pdo, (string)($row['sku'] ?? '')); ?>
        <tr>
          <td><?= htmlspecialchars((string)($row['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($row['name'] ?? $row['nome'] ?? $row['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($image ?? '', ENT_QUOTES, 'UTF-8') ?></td>
          <td><a href="olist-images-import-by-sku.php?sku=<?= urlencode((string)($row['sku'] ?? '')) ?>">importar por SKU</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
