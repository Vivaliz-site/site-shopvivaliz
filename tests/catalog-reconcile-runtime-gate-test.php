<?php
declare(strict_types=1);

$pipeline = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/master-production-pipeline.yml');
$needle = 'sudo systemctl is-active --quiet shopvivaliz-catalog-reconcile.timer || fail=1';
if (!str_contains($pipeline, $needle)) {
    throw new RuntimeException('production pipeline must gate on catalog reconcile timer health');
}

echo "catalog-reconcile-runtime-gate: ok\n";
