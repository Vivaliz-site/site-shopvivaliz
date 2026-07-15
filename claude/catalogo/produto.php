<?php
// Redireciona para a página de produto canônica preservando parâmetros
$id = $_GET['id'] ?? $_GET['sku'] ?? '';
$dest = '/claude/produto.php' . ($id !== '' ? '?id=' . urlencode($id) : '');
header('Location: ' . $dest, true, 301);
exit;
