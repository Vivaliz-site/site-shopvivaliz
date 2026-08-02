<?php
/**
 * Head Analytics e recursos públicos compartilhados.
 */

$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$requestPath = '/' . trim($requestPath, '/');
if ($requestPath === '/') {
    $requestPath = '/';
}

require_once __DIR__ . '/checkout-output-hardening.php';
svcoh_register($requestPath);

// O consentimento precisa ser visivel para o PHP. Sem o cookie explicito,
// segredos de envio server-side sao removidos apenas desta requisicao, o que
// impede Measurement Protocol/CAPI de criarem identificadores antes da escolha.
$serverConsentAccepted = hash_equals(
    'accepted',
    (string)($_COOKIE['sv_privacy_consent'] ?? '')
);
if (!$serverConsentAccepted) {
    foreach (['GA4_SECRET', 'FACEBOOK_ACCESS_TOKEN', 'TIKTOK_PIXEL_TOKEN'] as $secretKey) {
        putenv($secretKey);
        unset($_ENV[$secretKey], $_SERVER[$secretKey]);
    }
}

require_once __DIR__ . '/analytics-tracking.php';

// Consent Mode existe antes de GTM/gtag. O cookie e a copia em localStorage
// permanecem sincronizados pelo js/privacy-consent-v1.js.
echo <<<'HTML'
<script>
(function () {
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function(){ window.dataLayer.push(arguments); };
  var value = '';
  try {
    var match = document.cookie.match(/(?:^|;\s*)sv_privacy_consent=([^;]+)/);
    value = match ? decodeURIComponent(match[1]) : '';
    if (!value) {
      var saved = JSON.parse(localStorage.getItem('shopvivaliz_privacy_consent_v1') || 'null');
      value = saved && saved.value ? saved.value : '';
    }
  } catch (error) {}
  var granted = value === 'accepted';
  window.gtag('consent', 'default', {
    analytics_storage: granted ? 'granted' : 'denied',
    ad_storage: granted ? 'granted' : 'denied',
    ad_user_data: granted ? 'granted' : 'denied',
    ad_personalization: granted ? 'granted' : 'denied',
    functionality_storage: 'granted',
    security_storage: 'granted',
    wait_for_update: 500
  });
})();
</script>
HTML;

echo $GLOBALS['analytics']->getTrackingCode();

if (in_array($requestPath, ['/carrinho', '/checkout', '/checkout/retorno'], true)) {
    echo "\n<meta name=\"robots\" content=\"noindex,nofollow,noarchive\">\n";
}

$googleEventsFile = dirname(__DIR__) . '/js/shopvivaliz-google-events.js';
$googleEventsVersion = is_file($googleEventsFile) ? (string)filemtime($googleEventsFile) : '1';
echo "\n<script defer src=\"/js/shopvivaliz-google-events.js?v=" . htmlspecialchars($googleEventsVersion, ENT_QUOTES, 'UTF-8') . "\"></script>\n";

$privacyCss = dirname(__DIR__) . '/css/privacy-consent-v1.css';
$privacyJs = dirname(__DIR__) . '/js/privacy-consent-v1.js';
$privacyCssVersion = is_file($privacyCss) ? (string)filemtime($privacyCss) : '1';
$privacyJsVersion = is_file($privacyJs) ? (string)filemtime($privacyJs) : '1';
echo '<link rel="stylesheet" href="/css/privacy-consent-v1.css?v=' . htmlspecialchars($privacyCssVersion, ENT_QUOTES, 'UTF-8') . '">' . "\n";
echo '<script defer src="/js/privacy-consent-v1.js?v=' . htmlspecialchars($privacyJsVersion, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";

if ($requestPath === '/checkout') {
    $checkoutPaymentJs = dirname(__DIR__) . '/js/checkout-payment-v2.js';
    $checkoutPaymentVersion = is_file($checkoutPaymentJs) ? (string)filemtime($checkoutPaymentJs) : '1';
    echo '<script defer src="/js/checkout-payment-v2.js?v=' . htmlspecialchars($checkoutPaymentVersion, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
}

$company = @include dirname(__DIR__) . '/config/company-profile.php';
$company = is_array($company) ? $company : [];
$whatsappRaw = (string)($company['social_media']['whatsapp'] ?? $company['phone'] ?? '');
$whatsappDigits = preg_replace('/\D+/', '', $whatsappRaw) ?: '';
$whatsappUrl = $whatsappDigits !== ''
    ? 'https://wa.me/' . $whatsappDigits . '?text=' . rawurlencode('Olá! Vim pelo site da ShopVivaliz e gostaria de atendimento.')
    : '/contato';
$config = ['whatsappUrl' => $whatsappUrl];
echo '<script>window.ShopVivalizPublicConfig=' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>' . "\n";
$GLOBALS['sv_public_config_included'] = true;

$experienceCss = dirname(__DIR__) . '/css/public-experience-v1.css';
$experienceJs = dirname(__DIR__) . '/js/public-experience-v1.js';
$cssVersion = is_file($experienceCss) ? (string)filemtime($experienceCss) : '1';
$jsVersion = is_file($experienceJs) ? (string)filemtime($experienceJs) : '1';
echo '<link rel="stylesheet" href="/css/public-experience-v1.css?v=' . htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') . '">' . "\n";
echo '<script defer src="/js/public-experience-v1.js?v=' . htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
$GLOBALS['sv_public_experience_included'] = true;

if (function_exists('track_page_view')) {
    $title = $GLOBALS['page_title'] ?? 'Page';
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    track_page_view($title, $path);
}
?>