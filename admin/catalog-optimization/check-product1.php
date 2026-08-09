<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/config_optimization.php';
header('Content-Type: application/json; charset=utf-8');
$db = catalog_ai_db();
$stmt = $db->prepare('SELECT id, name, description, updated_at FROM products WHERE id = 1');
$stmt->execute();
echo json_encode(['ok' => true, 'produto_1' => $stmt->fetch(PDO::FETCH_ASSOC)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
