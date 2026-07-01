<?php
/**
 * AutoDev Tracker — Frontend Integration Helper
 *
 * Include this file near the top of every site page (after session_start,
 * before any HTML output):
 *
 *   require_once __DIR__ . '/autodev/integration/tracker.php';
 *
 * It will:
 *  - Detect the page type from the URL and fire the appropriate event
 *  - Always fire a page_view event
 *  - Set the autodev_session cookie for cross-page session tracking
 *  - Inject a tiny JS bounce-detection snippet (beacon API, < 1 KB)
 *
 * All logic runs inside try/catch so a tracker failure never breaks the page.
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────

try {
    $autodev_tracker_root = dirname(__DIR__); // autodev/
    require_once $autodev_tracker_root . '/core/event_collector.php';

    // ── Session cookie ───────────────────────────────────────────────────────
    // We use our own cookie so tracking works even without PHP sessions.
    if (empty($_COOKIE['autodev_session'])) {
        $session_id = bin2hex(random_bytes(16));
        setcookie(
            'autodev_session',
            $session_id,
            [
                'expires'  => time() + 86400 * 30, // 30 days
                'path'     => '/',
                'samesite' => 'Lax',
                'httponly' => false, // JS needs to read it for the beacon
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]
        );
        $_COOKIE['autodev_session'] = $session_id;
    }

    // ── Capture request context ──────────────────────────────────────────────
    $autodev_ip         = $_SERVER['REMOTE_ADDR'] ?? '';
    $autodev_ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $autodev_uri        = $_SERVER['REQUEST_URI'] ?? '/';
    $autodev_session_id = $_COOKIE['autodev_session'];

    // Strip query string for pattern matching, keep it for event data
    $autodev_path = strtok($autodev_uri, '?');
    $autodev_path = '/' . trim($autodev_path, '/');

    // ── Common extra context stored with every event ─────────────────────────
    $autodev_base_context = [
        'session_id'  => $autodev_session_id,
        'ip'          => $autodev_ip,
        'user_agent'  => $autodev_ua,
        'uri'         => $autodev_uri,
        'referrer'    => $_SERVER['HTTP_REFERER'] ?? '',
    ];

    // ── URL-pattern detection & specific event ───────────────────────────────
    $autodev_extra_event = null;

    if (preg_match('#/produto/([^/?]+)#i', $autodev_path, $m)) {
        // Product page — extract slug or numeric ID
        $product_raw = urldecode($m[1]);
        $autodev_extra_event = [
            'type'    => 'product_view',
            'context' => array_merge($autodev_base_context, [
                'product_id' => preg_match('/^\d+$/', $product_raw) ? (int)$product_raw : $product_raw,
            ]),
        ];

    } elseif (preg_match('#/carrinho#i', $autodev_path)) {
        $autodev_extra_event = [
            'type'    => 'cart_view',
            'context' => $autodev_base_context,
        ];

    } elseif (preg_match('#/checkout#i', $autodev_path)) {
        $autodev_extra_event = [
            'type'    => 'checkout_start',
            'context' => $autodev_base_context,
        ];

    } elseif (preg_match('#/(pedido-confirmado|obrigado)#i', $autodev_path)) {
        // Pull order_id from query string if available (?order=123)
        $order_id = $_GET['order'] ?? $_GET['pedido'] ?? null;
        $autodev_extra_event = [
            'type'    => 'order_complete',
            'context' => array_merge($autodev_base_context, [
                'order_id' => $order_id !== null ? (int)$order_id : null,
            ]),
        ];
    }

    // Always fire page_view
    track_event('page_view', $autodev_base_context);

    // Fire the page-specific event if detected
    if ($autodev_extra_event !== null) {
        track_event($autodev_extra_event['type'], $autodev_extra_event['context']);
    }

} catch (\Throwable $autodev_tracker_err) {
    // Never break the page — silently absorb tracker failures.
    // Uncomment the next line during local debugging only:
    // error_log('[AutoDev tracker] ' . $autodev_tracker_err->getMessage());
}

// ── Bounce-detection JS snippet ───────────────────────────────────────────────
// Injected inline; uses sendBeacon so it fires even on tab close.
// If the user leaves within 3 s it records a "bounce" event server-side.
?>
<script>
(function () {
  'use strict';
  var BOUNCE_THRESHOLD_MS = 3000;
  var arrived = Date.now();
  var bounced = false;

  function sendBounce() {
    if (bounced) return;
    bounced = true;
    var elapsed = Date.now() - arrived;
    if (elapsed >= BOUNCE_THRESHOLD_MS) return; // not a bounce

    var payload = JSON.stringify({
      event:      'bounce',
      session_id: (document.cookie.match(/autodev_session=([^;]+)/) || [])[1] || '',
      uri:        window.location.href,
      elapsed_ms: elapsed
    });

    // Prefer sendBeacon (works on page unload); fall back to sync XHR
    var endpoint = '<?= htmlspecialchars(
    rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/autodev/integration/beacon.php',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
) ?>';

    if (navigator.sendBeacon) {
      navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }));
    } else {
      try {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint, false); // sync fallback
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(payload);
      } catch (e) { /* silent */ }
    }
  }

  // Fire on visibility change (tab switch / close) and unload
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') sendBounce();
  });
  window.addEventListener('pagehide', sendBounce);
  window.addEventListener('beforeunload', sendBounce);
})();
</script>
<?php
// Clean up our local variables so they don't leak into the including page.
unset(
    $autodev_tracker_root,
    $autodev_ip,
    $autodev_ua,
    $autodev_uri,
    $autodev_path,
    $autodev_session_id,
    $autodev_base_context,
    $autodev_extra_event,
    $autodev_tracker_err,
    $m,
    $product_raw,
    $order_id
);
