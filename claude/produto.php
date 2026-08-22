<?php
declare(strict_types=1);
// Legado Claude desativado: produto publico usa somente cache derivado do ERP Olist/Tiny v3.
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/produto.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 301);
exit;
