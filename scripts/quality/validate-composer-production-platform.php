<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$test = $root . '/tests/composer-production-platform-contract-test.php';
passthru(PHP_BINARY . ' ' . escapeshellarg($test), $code);
exit($code);
