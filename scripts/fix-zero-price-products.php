<?php
/** Wrapper legado protegido; implementação preservada em scripts/production/legacy/. */
if (getenv('ALLOW_LEGACY_PRODUCTION_SCRIPT') !== 'YES') {
    fwrite(STDERR, "Execução bloqueada. Exige backup do banco, canário, rollback e read-back; depois defina ALLOW_LEGACY_PRODUCTION_SCRIPT=YES.\n");
    exit(2);
}
require __DIR__ . '/production/legacy/fix-zero-price-products.php';
