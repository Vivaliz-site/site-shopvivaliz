<?php
/** Read-only structural check. Never sends an event or loads a purchase sender. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/bootstrap-env.php';

$missingOrPlaceholder = static function (string $value): bool {
    $value = strtolower(trim($value));
    return $value === ''
        || in_array($value, ['placeholder', 'changeme'], true)
        || str_starts_with($value, 'your_')
        || str_contains($value, 'replace-with')
        || preg_match('/^(?:g-)?x+$/D', $value) === 1;
};

// Choose the first truthy raw value exactly as AnalyticsTracking does;
// trim only after selection so whitespace in a primary key fails, not falls back.
$ga4Id = trim((string)(getenv('GA4_ID') ?: (getenv('GOOGLE_ANALYTICS_ID')
    ?: (getenv('GOOGLE_ANALYTICS') ?: (getenv('GOOGLE_ANALITYCS') ?: '')))));
$ga4Secret = trim((string)(getenv('GA4_SECRET') ?: ''));
$errors = [];
$idValid = !$missingOrPlaceholder($ga4Id) && preg_match('/^G-[A-Z0-9]+$/D', $ga4Id) === 1;
$secretPresent = !$missingOrPlaceholder($ga4Secret);
if (!$idValid) {
    $errors[] = 'GA4_ID_missing_or_invalid';
}
if (!$secretPresent) {
    $errors[] = 'GA4_SECRET_missing_or_placeholder';
}

foreach (['includes/analytics-tracking.php', 'includes/head-analytics.php', 'pedido-confirmado.php'] as $relative) {
    $path = dirname(__DIR__) . '/' . $relative;
    if (!is_file($path) || !is_readable($path)) {
        $errors[] = 'required_file_unavailable:' . $relative;
    }
}

// Presence and syntax do not prove authentication, collection or attribution.
// Do not print values, length, exceptions containing URLs, or synthetic orders.
echo 'GA4_ID_FORMAT=' . ($idValid ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'GA4_SECRET_CONFIGURED=' . ($secretPresent ? 'true' : 'false') . PHP_EOL;
echo 'TRACKING_VERIFICATION=STRUCTURAL_ONLY' . PHP_EOL;
echo 'CREDENTIAL_VALIDITY=NOT_VERIFIED' . PHP_EOL;
echo 'PURCHASE_DELIVERY=NOT_VERIFIED' . PHP_EOL;
echo 'EVENTS_SENT=0' . PHP_EOL;
echo 'NETWORK_POLICY=NO_REQUESTS_BY_DESIGN' . PHP_EOL;
echo 'TRACKING_CONFIG=' . ($errors === [] ? 'PASS' : 'FAIL') . PHP_EOL;
foreach ($errors as $error) {
    echo 'CONFIG_ERROR=' . $error . PHP_EOL;
}
echo 'NEXT_STEP=Follow docs/knowledge/ga4-server-validation.md; never create synthetic production purchases.' . PHP_EOL;
exit($errors === [] ? 0 : 1);
