<?php
/**
 * Refresh manual/CLI do catalogo ativo. Reusa o fluxo canonico para nao
 * manter uma segunda implementacao capaz de ressuscitar inativos ou fabricar
 * estoque zero.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/admin-guard.php';
}

require __DIR__ . '/sync-on-webhook.php';
require __DIR__ . '/fetch-estoque-v3.php';
