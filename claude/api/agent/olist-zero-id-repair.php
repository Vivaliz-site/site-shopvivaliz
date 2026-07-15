<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function svz_pdo(): ?PDO
{
    foreach (array('sv_pdo', 'sv_db', 'db', 'get_pdo') as $fn) {
        if (function_exists($fn)) {
            $db = $fn();
            if ($db instanceof PDO) return $db;
        }
    }
    foreach (array(__DIR__ . '/../../config.php', __DIR__ . '/../../includes/config.php', __DIR__ . '/../../app/config.php', __DIR__ . '/../../bootstrap.php') as $file) {
        if (is_file($file)) {
            require_once $file;
            foreach (array('sv_pdo', 'sv_db', 'db', 'get_pdo') as $fn) {
                if (function_exists($fn)) {
                    $db = $fn();
                    if ($db instanceof PDO) return $db;
                }
            }
        }
    }
    return null;
}

function svz_column(PDO $pdo, string $table, string $column): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute(array($table, $column));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function svz_count(PDO $pdo, string $table, string $column): ?int
{
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = 0')->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

function svz_nullable(PDO $pdo, string $table, string $column, array &$out): void
{
    $meta = svz_column($pdo, $table, $column);
    if (!$meta) return;
    if ((string)$meta['IS_NULLABLE'] === 'YES') return;
    $type = (string)$meta['COLUMN_TYPE'];
    try {
        $pdo->exec('ALTER TABLE ' . $table . ' MODIFY ' . $column . ' ' . $type . ' NULL');
        $out['actions'][] = array('table' => $table, 'column' => $column, 'made_nullable' => true);
    } catch (Throwable $e) {
        $out['errors'][] = 'nullable_failed ' . $table . '.' . $column . ': ' . $e->getMessage();
    }
}

$apply = isset($_GET['apply']) && (string)$_GET['apply'] === '1';
$pdo = svz_pdo();
$out = array('ok' => false, 'agent' => 'olist_zero_id_repair', 'apply' => $apply, 'generated_at' => date('c'), 'checks' => array(), 'actions' => array(), 'errors' => array());

if (!$pdo) {
    $out['errors'][] = 'database unavailable';
    http_response_code(500);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

try {
    $targets = array(
        array('olist_products', 'olist_id'),
        array('olist_products', 'olist_product_id'),
        array('olist_products', 'idProduto'),
        array('olist_product_images', 'olist_id'),
        array('olist_product_images', 'olist_product_id')
    );
    foreach ($targets as $target) {
        $table = $target[0];
        $column = $target[1];
        $meta = svz_column($pdo, $table, $column);
        if (!$meta) continue;
        $out['checks'][$table . '.' . $column . '.nullable'] = (string)$meta['IS_NULLABLE'];
        $out['checks'][$table . '.' . $column . '.zero'] = svz_count($pdo, $table, $column);
        if ($apply) {
            svz_nullable($pdo, $table, $column, $out);
            try {
                $affected = $pdo->exec('UPDATE ' . $table . ' SET ' . $column . ' = NULL WHERE ' . $column . ' = 0');
                $out['actions'][] = array('table' => $table, 'column' => $column, 'zero_to_null' => $affected);
            } catch (Throwable $e) {
                $out['errors'][] = 'zero_to_null_failed ' . $table . '.' . $column . ': ' . $e->getMessage();
            }
        }
    }
    $out['ok'] = count($out['errors']) === 0;
} catch (Throwable $e) {
    $out['errors'][] = $e->getMessage();
}

http_response_code($out['ok'] ? 200 : 500);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
