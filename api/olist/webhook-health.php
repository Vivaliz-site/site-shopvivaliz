<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/runtime-env-reader.php';

$diagnostics = [
    'ok' => true,
    'service' => 'olist-webhook-health',
    'generated_at' => date('c'),
    'checks' => [],
];

// The old webhook-processor.php is now an intentional 410 tombstone. A
// health check for its mere existence can falsely report a working integration
// even when the signed Olist/Tiny v3 ingress has disappeared or lost auth.
$canonical = __DIR__ . '/webhook.php';
$legacy = __DIR__ . '/webhook-processor.php';
$apache = $root . '/.htaccess';
$canonicalSource = is_file($canonical) && is_readable($canonical)
    ? (string)file_get_contents($canonical) : '';
$legacySource = is_file($legacy) && is_readable($legacy)
    ? (string)file_get_contents($legacy) : '';
$apacheSource = is_file($apache) && is_readable($apache)
    ? (string)file_get_contents($apache) : '';
$diagnostics['checks']['canonical_entrypoint_readable'] = $canonicalSource !== '';
$diagnostics['checks']['canonical_provider_gate_present'] =
    str_contains($canonicalSource, "sv_webhook_secret_gate('olist', false)");
$diagnostics['checks']['canonical_v3_product_reconciliation_present'] =
    str_contains($canonicalSource, 'svoei_refresh_product_from_v3');
$diagnostics['checks']['legacy_processor_retired'] =
    str_contains($legacySource, "http_response_code(410)") &&
    !preg_match('/\\b(?:UPDATE|INSERT|DELETE)\\s+(?:FROM\\s+|INTO\\s+)?products\\b/i', $legacySource);
$diagnostics['checks']['legacy_http_blocked'] =
    str_contains($apacheSource, 'RewriteRule ^api/olist/webhook-processor\\.php$ - [F,L,NC]');

$logDir = $root . '/logs';
$diagnostics['checks']['logs_writable'] = is_dir($logDir) && is_writable($logDir);

$db = null;
try {
    $database = svre_database_config($root);
    $host = $database['host'];
    $port = (int)$database['port'];
    $name = $database['name'];
    $user = $database['user'];
    $pass = $database['pass'];

    if ($name === '' || $user === '') {
        throw new RuntimeException('database_configuration_missing');
    }
    if (!class_exists('mysqli') || !function_exists('mysqli_report')) {
        throw new RuntimeException('mysqli_extension_unavailable');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli($host, $user, $pass, $name, $port);
    if ($db->connect_errno !== 0) {
        throw new RuntimeException('database_connection_failed');
    }
    $db->set_charset('utf8mb4');
    $probe = $db->query('SELECT 1');
    if ($probe === false) {
        throw new RuntimeException('database_probe_failed');
    }
    $probe->free();

    $diagnostics['checks']['database_connected'] = true;
} catch (Throwable $e) {
    $diagnostics['checks']['database_connected'] = false;
    $diagnostics['checks']['database_error'] = 'Runtime database configuration unavailable or connection failed';
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}

$diagnostics['ok'] =
    $diagnostics['checks']['canonical_entrypoint_readable']
    && $diagnostics['checks']['canonical_provider_gate_present']
    && $diagnostics['checks']['canonical_v3_product_reconciliation_present']
    && $diagnostics['checks']['legacy_processor_retired']
    && $diagnostics['checks']['legacy_http_blocked']
    && $diagnostics['checks']['logs_writable']
    && $diagnostics['checks']['database_connected'];

http_response_code($diagnostics['ok'] ? 200 : 503);
echo json_encode(
    $diagnostics,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
