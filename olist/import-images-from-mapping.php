<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok' => false,
    'error' => 'obsolete_importer_removed',
    'message' => 'Imagens do storefront devem vir do ERP Olist/Tiny v3 via sincronizacao oficial.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(1);
