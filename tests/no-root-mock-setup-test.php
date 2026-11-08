<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forbiddenPaths = [
    $root . '/setup-auto.sh',
    $root . '/claude/medusa',
    $root . '/medusa',
    $root . '/claude/api/medusa-webhook.php',
    $root . '/claude/api/sync-olist-products.php',
    $root . '/claude/api/sync-shopee-products.php',
    $root . '/claude/api/olist/webhook.php',
    $root . '/automation/eha/reports/medusa-last-run.json',
    $root . '/automation/eha/reports/medusa-run-history.jsonl',
    $root . '/TESTAR_AGORA.ps1',
    $root . '/RODAR_AGORA.ps1',
];

foreach ($forbiddenPaths as $path) {
    if (file_exists($path)) {
        fwrite(STDERR, "Forbidden cancelled or mock component exists: {$path}\n");
        exit(1);
    }
}

// The canonical Olist/Tiny webhook must survive retirement of the parallel adapter.
if (!is_file($root . '/api/olist/webhook.php')) {
    fwrite(STDERR, "Canonical ERP webhook must remain present\n");
    exit(1);
}

foreach (['/claude/index.php', '/claude/dashboard/index.php'] as $path) {
    if (file_exists($root . $path)) {
        $ui = file_get_contents($root . $path);
        if (is_string($ui) && stripos($ui, 'medusa') !== false) {
            fwrite(STDERR, "Retired platform must not appear in active dashboard: {$path}\n");
            exit(1);
        }
    }
}

$readme = file_get_contents($root . '/README.md');
if (!is_string($readme) || stripos($readme, 'MedusaJS') !== false
    || str_contains($readme, 'medusa-migracao-roadmap.md')) {
    fwrite(STDERR, "README must describe the official supported stack\n");
    exit(1);
}

foreach ([
    '/scripts/send-audit-report.php',
    '/scripts/test-and-send-report.php',
    '/scripts/diagnose_github_actions.sh',
] as $path) {
    if (file_exists($root . $path)) {
        $script = file_get_contents($root . $path);
        if (is_string($script) && stripos($script, 'medusa') !== false) {
            fwrite(STDERR, "Operational script references retired platform: {$path}\n");
            exit(1);
        }
    }
}

$rootScripts = glob($root . '/*.{sh,ps1}', GLOB_BRACE) ?: [];
$forbiddenPatterns = [
    '/pk_live_default/i',
    '/mock\s+api/i',
    '/simulad[oa]/i',
    '/claude\/medusa/i',
    '/\bmedusa\b/i',
];

foreach ($rootScripts as $script) {
    $source = file_get_contents($script);
    if (!is_string($source)) {
        continue;
    }
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            fwrite(STDERR, "Cancelled, mock or placeholder setup pattern found in root script: {$script}\n");
            exit(1);
        }
    }
}

echo "OK: no cancelled Medusa or mock-oriented root setup\n";
