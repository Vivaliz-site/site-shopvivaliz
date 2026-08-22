<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/order-request-context.php';
require_once dirname(__DIR__) . '/includes/ga4-order-conversion.php';
require_once dirname(__DIR__) . '/includes/product-trust-sanitizer.php';

function sv_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$originalCookies = $_COOKIE;

// Consented browser identifiers must be preserved for paid-media attribution.
$sessionCookie = 'GS2.1.s1700000000$o1$g1$t1700000300$j0$l0$h0';
$_COOKIE = [
    'sv_privacy_consent' => 'accepted',
    '_ga' => 'GA1.1.123456789.1700000000',
    '_ga_1H55K1TZ5D' => $sessionCookie,
    '_gcl_aw' => 'GCL.1700000000.TestClick_123-ABC',
];
svorc_set([
    'funnel_client_id' => 'random-first-party-id',
    'gclid' => '',
], [['sku' => 'TEST-1']]);
$body = svorc_body();
$packedIdentity = '123456789.1700000000|' . $sessionCookie;
sv_test_assert(
    ($body['funnel_client_id'] ?? '') === $packedIdentity,
    'GA client_id and session cookie should be persisted together after consent.'
);
sv_test_assert(
    ($body['gclid'] ?? '') === 'TestClick_123-ABC',
    'gclid should be recovered from the consented _gcl_aw cookie when missing from the request.'
);

$identity = svga4_order_client_id([
    'order_number' => 'SVTEST001',
    'funnel_client_id' => $packedIdentity,
]);
sv_test_assert(($identity['tagged'] ?? false) === true, 'A canonical GA client_id should be marked attributable.');
sv_test_assert(($identity['client_id'] ?? '') === '123456789.1700000000', 'Approved purchase should reuse the browser client_id.');
sv_test_assert(
    svga4_order_session_id(['funnel_client_id' => $packedIdentity]) === '1700000000',
    'Approved purchase should normalize the GA4 session cookie to the numeric session_id used by Measurement Protocol.'
);

// Historical orders with only client_id must remain valid.
$legacyTagged = svga4_order_client_id([
    'order_number' => 'SVTEST001B',
    'funnel_client_id' => '123456789.1700000000',
]);
sv_test_assert(($legacyTagged['tagged'] ?? false) === true, 'Historical GA client_id orders should remain attributable.');
sv_test_assert(svga4_order_session_id(['funnel_client_id' => '123456789.1700000000']) === '', 'Historical orders may omit session_id safely.');

$fallback = svga4_order_client_id([
    'order_number' => 'SVTEST002',
    'funnel_client_id' => '4c21639a-e61a-4c9d-b2bc-03e8802cc05f',
]);
sv_test_assert(($fallback['tagged'] ?? true) === false, 'Legacy random funnel ids must not be mistaken for GA client_ids.');
sv_test_assert(str_starts_with((string)($fallback['client_id'] ?? ''), 'server.'), 'Legacy orders should retain a non-attributable server fallback.');

// Without explicit consent, browser advertising/analytics cookies are ignored.
$_COOKIE = [
    'sv_privacy_consent' => 'rejected',
    '_ga' => 'GA1.1.987654321.1700000001',
    '_ga_1H55K1TZ5D' => 'GS2.1.s1700000001$o1$g1$t1700000301$j0$l0$h0',
    '_gcl_aw' => 'GCL.1700000001.ShouldNotBeUsed',
];
svorc_set([
    'funnel_client_id' => 'fallback-id',
    'gclid' => '',
], [['sku' => 'TEST-2']]);
$rejected = svorc_body();
sv_test_assert(($rejected['funnel_client_id'] ?? '') === 'fallback-id', 'Rejected consent must not read GA cookies.');
sv_test_assert(($rejected['gclid'] ?? '') === '', 'Rejected consent must not read the _gcl_aw cookie.');

// Browser ecommerce tracking must cover checkout milestones without ever
// treating order creation as revenue. Purchase is canonical only after payment
// approval in the server-side webhook post-processor.
$eventsJs = (string)file_get_contents(dirname(__DIR__) . '/js/shopvivaliz-google-events.js');
$modernParserPos = strpos($eventsJs, 'var modern = value.match');
$legacyParserPos = strpos($eventsJs, 'var legacy = value.match');
sv_test_assert($modernParserPos !== false && $legacyParserPos !== false && $modernParserPos < $legacyParserPos, 'GS2 modern session cookie parser must run before legacy parser.');
sv_test_assert(str_contains($eventsJs, '[.\\$])s(\\d+)'), 'GS2 parser must recognize the .s<session_id>$ cookie segment.');
sv_test_assert(str_contains($eventsJs, "if (eventName === 'purchase') return;"), 'Browser purchase events must be suppressed.');
sv_test_assert(str_contains($eventsJs, "'add_shipping_info'"), 'GA4 add_shipping_info should be instrumented.');
sv_test_assert(str_contains($eventsJs, "'add_payment_info'"), 'GA4 add_payment_info should be instrumented.');
sv_test_assert(str_contains($eventsJs, "'remove_from_cart'"), 'GA4 remove_from_cart should be instrumented.');

$funnelEndpoint = (string)file_get_contents(dirname(__DIR__) . '/api/analytics/funnel-event.php');
sv_test_assert(str_contains($funnelEndpoint, "'add_shipping_info'"), 'First-party funnel should accept shipping milestones.');
sv_test_assert(str_contains($funnelEndpoint, "'add_payment_info'"), 'First-party funnel should accept payment milestones.');
sv_test_assert(str_contains($funnelEndpoint, "'remove_from_cart'"), 'First-party funnel should accept cart removals.');

// CRO sanitizer should remove unavailable recommendations while preserving saleable ones.
$html = <<<'HTML'
<section class="container related-products">
  <h2 class="related-title">Você também pode gostar</h2>
  <div class="product-grid related-grid">
    <article class="product-card is-out-of-stock"><a href="/produto/esgotado">Esgotado</a></article>
    <article class="product-card"><a href="/produto/disponivel">Disponível</a></article>
  </div>
</section>
HTML;
$sanitized = svpts_sanitize_product_html($html);
sv_test_assert(!str_contains($sanitized, '/produto/esgotado'), 'Sold-out recommendation should be removed.');
sv_test_assert(str_contains($sanitized, '/produto/disponivel'), 'In-stock recommendation should remain.');

$_COOKIE = $originalCookies;
fwrite(STDOUT, "PASS: Google Ads attribution and product CRO tests.\n");
