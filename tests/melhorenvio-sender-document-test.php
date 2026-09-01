<?php
declare(strict_types=1);

putenv('MELHORENVIO_FROM_DOCUMENT=');
putenv('MELHORENVIO_FROM_CNPJ=11222333000181');

require_once dirname(__DIR__) . '/includes/melhorenvio-label.php';

$from = svml_from_address();

if (($from['company_document'] ?? '') !== '11222333000181') {
    fwrite(STDERR, "FAIL: remetente PJ deve enviar CNPJ em company_document\n");
    exit(1);
}

if (array_key_exists('document', $from) && trim((string)$from['document']) !== '') {
    fwrite(STDERR, "FAIL: remetente PJ nao deve enviar CNPJ em document\n");
    exit(1);
}

echo "OK: remetente PJ usa company_document\n";
