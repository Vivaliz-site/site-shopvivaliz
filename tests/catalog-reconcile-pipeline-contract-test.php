<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pipeline = (string)file_get_contents($root . '/.github/workflows/master-production-pipeline.yml');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($pipeline, 'cmp -s "$release/deploy/systemd/shopvivaliz-catalog-reconcile.service" "$previous/deploy/systemd/shopvivaliz-catalog-reconcile.service"'),
    'pipeline must detect reconcile service changes'
);
$assert(
    str_contains($pipeline, 'cmp -s "$release/deploy/systemd/shopvivaliz-catalog-reconcile.timer" "$previous/deploy/systemd/shopvivaliz-catalog-reconcile.timer"'),
    'pipeline must detect reconcile timer changes'
);

echo "catalog-reconcile-pipeline-contract: ok\n";
