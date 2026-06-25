<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function svz_pdo(): ?PDO
{
    foreach (array('sv_pdo', 'sv_db', 'db', 'get_pdo') as $fn) {
        if (function_exists($fn)) {
            $db = $fn();
            if ($db instanceof PDO) {
                return $db;
            }
        }
    }
    $candidates = array(
        __DIR__ . '/../../config.php',
        __DIR__ . '/../../includes/config.php',
        __DIR__ . '/../../app/config.php',
        __DIR__ . '/../../bootstrap.php',
    );
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            foreach (array('sv_pdo', 'sv_db', 'db', 'get_pdo') as $fn) {
                if (function_exists($fn)) {
                    $db = $fn();
                    if ($db instanceof PDO) {
                        return $db;
                    }
                }
            }
        }
    }
    return null;
}

function svz_table(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute(array($table));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function svz_col(PDO $pdo, string $table, string $col): bool
{
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute(array($table, $col));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function svz_count(PDO $pdo, string $sql): ?int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

$apply = isset($_GET['apply']) && (string)$_GET['apply'] === '1';
$pdo = svz_pdo();
$out = array(
    'ok' => false,
    'agent' => 'olist_zero_id_repair',
    'apply' => $apply,
    'generated_at' => date('c'),
    'checks' => array(),
    'actions' => array(),
    'errors' => array(),
);

if (!$pdo) {
    $out['errors'][] = 'database unavailable';
    http_response_code(500);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

try {
    $targets = array('olist_products', 'olist_product_images');
    foreach ($targets as $table) {
        if (svz_table($pdo, $table) && svz_col($pdo, $table, 'olist_id')) {
            $out['checks'][$table . '.olist_id_zero'] = svz_count($pdo, 'SELECT COUNT(*) FROM ' . $table . ' WHERE olist_id = 0');
            if ($apply) {
                $affected = $pdo->exec('UPDATE ' . $table . ' SET olist_id = NULL WHERE olist_id = 0');
                $out['actions'][] = array('table' => $table, 'column' => 'olist_id', 'set_zero_to_null' => $affected);
            }
        }
        if (svz_table($pdo, $table) && svz_col($pdo, $table, 'olist_product_id')) {
            $out['checks'][$table . '.olist_product_id_zero'] = svz_count($pdo, 'SELECT COUNT(*) FROM ' . $table . ' WHERE olist_product_id = 0');
            if ($apply) {
                $affected = $pdo->exec('UPDATE ' . $table . ' SET olist_product_id = NULL WHERE olist_product_id = 0');
                $out['actions'][] = array('table' => $table, 'column' => 'olist_product_id', 'set_zero_to_null' => $affected);
            }
        }
        if (svz_table($pdo, $table) && svz_col($pdo, $table, 'idProduto')) {
            $out['checks'][$table . '.idProduto_zero'] = svz_count($pdo, 'SELECT COUNT(*) FROM ' . $table . ' WHERE idProduto = 0');
            if ($apply) {
                $affected = $pdo->exec('UPDATE ' . $table . ' SET idProduto = NULL WHERE idProduto = 0');
                $out['actions'][] = array('table' => $table, 'column' => 'idProduto', 'set_zero_to_null' => $affected);
            }
        }
    }
    $out['ok'] = true;
} catch (Throwable $e) {
    $out['errors'][] = $e->getMessage();
}

http_response_code($out['ok'] ? 200 : 500);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
