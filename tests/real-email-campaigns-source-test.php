<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/email-marketing-service.php');
$blogWorkflow = file_get_contents($root . '/.github/workflows/blog-publish-scheduled.yml');
$productWorkflow = file_get_contents($root . '/.github/workflows/product-highlights-email.yml');
$unsubscribe = file_get_contents($root . '/newsletter/descadastrar/index.php');
$migration = file_get_contents($root . '/migrations/2026-08-03-real-email-campaigns.sql');

foreach (compact('service', 'blogWorkflow', 'productWorkflow', 'unsubscribe', 'migration') as $name => $source) {
    if (!is_string($source) || trim($source) === '') {
        fwrite(STDERR, "FAIL: missing source {$name}\n");
        exit(1);
    }
}

$requiredService = [
    "status = 'confirmed'",
    'marketing_consent',
    'marketing_opt_in',
    'accepts_marketing',
    'email_marketing_suppressions',
    'email_campaign_deliveries',
    'send_email($email, $subject, $html, $text)',
    'GET_LOCK',
    'svem_unsubscribe_url',
    '15 * 86400',
];
foreach ($requiredService as $snippet) {
    if (!str_contains($service, $snippet)) {
        fwrite(STDERR, "FAIL: missing real campaign invariant: {$snippet}\n");
        exit(1);
    }
}

foreach (['dry_run', 'dry-run', 'simulate', 'simulation', 'fake_send', 'mock_send'] as $forbidden) {
    if (stripos($service, $forbidden) !== false) {
        fwrite(STDERR, "FAIL: simulated email path found: {$forbidden}\n");
        exit(1);
    }
}

foreach ([$blogWorkflow, $productWorkflow] as $workflow) {
    foreach (['ubuntu@144.22.157.209', '/home/ubuntu/shopvivaliz-deploy/current', 'StrictHostKeyChecking=yes', 'upload-artifact@v4'] as $snippet) {
        if (!str_contains($workflow, $snippet)) {
            fwrite(STDERR, "FAIL: workflow is not production-bound: {$snippet}\n");
            exit(1);
        }
    }
}

if (!str_contains($unsubscribe, 'hash_equals') || !str_contains($unsubscribe, 'email_marketing_suppressions')) {
    fwrite(STDERR, "FAIL: signed unsubscribe is incomplete\n");
    exit(1);
}

foreach (['email_campaign_runs', 'email_campaign_deliveries', 'UNIQUE KEY uq_email_campaign_delivery'] as $snippet) {
    if (!str_contains($migration, $snippet)) {
        fwrite(STDERR, "FAIL: delivery evidence schema missing: {$snippet}\n");
        exit(1);
    }
}

echo "real-email-campaigns-source: ok\n";
