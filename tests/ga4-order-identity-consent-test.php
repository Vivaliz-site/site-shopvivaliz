<?php
declare(strict_types=1);

$root = getenv('TRACKING_TEST_ROOT') ?: dirname(__DIR__);
require_once $root . '/includes/order-request-context.php';
require_once $root . '/includes/ga4-order-conversion.php';
$failures = 0;
function ga4_identity_check(string $name, bool $condition): void {
    global $failures;
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS: ' : 'FAIL: ') . $name . PHP_EOL);
    if (!$condition) $failures++;
}
$oldCookies = $_COOKIE;
$oldGa4 = getenv('GA4_ID');
try {
    putenv('GA4_ID=G-1H55K1TZ5D');
    $_COOKIE = ['sv_privacy_consent' => 'accepted', '_ga' => 'GA1.1.123456789.1700000000', '_ga_QWYPLYMZ9' => 'GS2.1.s1111111111$o1', '_ga_1H55K1TZ5D' => 'GS2.1.s2222222222$o1'];
    svorc_set([], []);
    ga4_identity_check('Order uses the configured stream even when a retired stream cookie appears first', svga4_order_session_id(svorc_body()) === '2222222222');
    unset($_COOKIE['_ga_1H55K1TZ5D']);
    svorc_set([], []);
    ga4_identity_check('Order omits session when only an unrelated stream exists', (svorc_body()['funnel_client_id'] ?? '') === '123456789.1700000000');
    $_COOKIE['_ga_TEST42'] = 'GS2.1.s3333333333$o1';
    putenv('GA4_ID=G-TEST42');
    svorc_set([], []);
    ga4_identity_check('Explicit stream configuration selects its own cookie', svga4_order_session_id(svorc_body()) === '3333333333');
    $_COOKIE = ['sv_privacy_consent' => 'essential', '_ga' => 'GA1.1.123456789.1700000000'];
    svorc_set(['funnel_client_id' => '123456789.1700000000|2222222222', 'gclid' => 'TestClick'], []);
    ga4_identity_check('Denied consent cannot preserve a GA identity supplied in the request', (svorc_body()['funnel_client_id'] ?? '') === '');
    ga4_identity_check('Denied consent cannot become an attributable GA purchase', svga4_order_client_id(svorc_body())['tagged'] === false);
    ga4_identity_check('Denied consent clears advertising attribution', (svorc_body()['gclid'] ?? '') === '' && (svorc_body()['attribution'] ?? null) === []);
    svorc_set(['funnel_client_id' => '123456789.1700000000 |2222222222'], []);
    ga4_identity_check('Denied consent strips numeric client identity with whitespace before the session separator', (svorc_body()['funnel_client_id'] ?? '') === '' && svga4_order_client_id(svorc_body())['tagged'] === false);
    svorc_set(['funnel_client_id' => "123456789.1700000000|\n2222222222"], []);
    ga4_identity_check('Denied consent strips numeric client identity with a multiline session value', (svorc_body()['funnel_client_id'] ?? '') === '' && svga4_order_client_id(svorc_body())['tagged'] === false);
    svorc_set(['funnel_client_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'], []);
    ga4_identity_check('Existing random first-party fallback remains compatible', (svorc_body()['funnel_client_id'] ?? '') === 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
} finally {
    $_COOKIE = $oldCookies;
    $oldGa4 === false ? putenv('GA4_ID') : putenv('GA4_ID=' . $oldGa4);
}
exit($failures > 0 ? 1 : 0);
