<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/abandoned-cart-cron-install.yml');
$docs = file_get_contents($root . '/docs/carrinho-abandonado.md');

if (!is_string($workflow) || !is_string($docs)) {
    fwrite(STDERR, "Unable to read abandoned-cart cron files\n");
    exit(1);
}

$canonicalScript = '/home/ubuntu/shopvivaliz-deploy/current/scripts/send-abandoned-cart-emails.php';
$canonicalLog = '/home/ubuntu/shopvivaliz-deploy/shared/logs/abandoned-cart-email.log';
$legacyScript = '/home/ubuntu/site-shopvivaliz/scripts/send-abandoned-cart-emails.php';

foreach ([$workflow, $docs] as $content) {
    if (!str_contains($content, $canonicalScript)) {
        fwrite(STDERR, "Canonical immutable-deploy script path is missing\n");
        exit(1);
    }
    if (!str_contains($content, $canonicalLog)) {
        fwrite(STDERR, "Canonical shared log path is missing\n");
        exit(1);
    }
}

if (str_contains($workflow, $legacyScript)) {
    fwrite(STDERR, "Workflow still installs the legacy abandoned-cart script path\n");
    exit(1);
}
if (!str_contains($workflow, "grep -vF 'send-abandoned-cart-emails.php'")) {
    fwrite(STDERR, "Workflow must remove stale abandoned-cart cron entries before reinstalling\n");
    exit(1);
}
if (!str_contains($workflow, "ABANDONED_CART_CRON_VERIFIED")) {
    fwrite(STDERR, "Workflow must verify the installed cron entry\n");
    exit(1);
}
if (!str_contains($workflow, 'flock -n')) {
    fwrite(STDERR, "Recovery cron must be protected against overlapping runs\n");
    exit(1);
}

fwrite(STDOUT, "abandoned-cart-cron-path-test: ok\n");
