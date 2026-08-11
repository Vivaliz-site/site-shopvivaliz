<?php
declare(strict_types=1);

// Mantem o guard historico intacto em um core versionado e registra uma
// segunda camada, externa, para validar identificadores de Product JSON-LD
// contra a mesma fonte usada pelo feed do Merchant Center.
require_once __DIR__ . '/public-trust-guard-core.php';
require_once __DIR__ . '/structured-data-trust.php';

svsdt_register();
