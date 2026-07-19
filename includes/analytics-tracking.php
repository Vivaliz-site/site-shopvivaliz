<?php
/**
 * 📊 Analytics Tracking - GA4 + Facebook Pixel + TikTok
 * Impacto: Insights +100%, Retargeting optimization
 */

class AnalyticsTracking {
    private $ga4_id = '';
    private $facebook_pixel = '';
    private $tiktok_pixel = '';
    private $google_ads_id = '';
    private $google_ads_conversion_label = '';
    private $events = [];

    public function __construct() {
        $this->ga4_id = getenv('GA4_ID') ?: (getenv('GOOGLE_ANALYTICS_ID') ?: (getenv('GOOGLE_ANALYTICS') ?: (getenv('GOOGLE_ANALITYCS') ?: 'G-XXXXXXXXXX')));
        $this->facebook_pixel = getenv('FACEBOOK_PIXEL') ?: '';
        $this->tiktok_pixel = getenv('TIKTOK_PIXEL') ?: '';
        $id = getenv('GOOGLE_ADS_ID') ?: (getenv('GOOGLE_ADS_CONVERSION_ID') ?: '');
        if ($id !== '' && !str_starts_with($id, 'AW-') && is_numeric($id)) {
            $id = 'AW-' . $id;
        }
        $this->google_ads_id = $id;
        $this->google_ads_conversion_label = getenv('GOOGLE_ADS_CONVERSION_LABEL') ?: '';
    }

    public function trackPageView($page_title, $page_path) {
        $this->events[] = [
            'name' => 'page_view',
            'params' => [
                'page_title' => $page_title,
                'page_location' => $this->buildAbsoluteUrl($page_path),
                'page_referrer' => (string)($_SERVER['HTTP_REFERER'] ?? ''),
            ]
        ];
    }

    public function trackViewItem($product) {
        $this->events[] = [
            'name' => 'view_item',
            'params' => [
                'currency' => 'BRL',
                'value' => $product['price'],
                'items' => [[
                    'item_id' => $product['id'],
                    'item_name' => $product['name'],
                    'item_brand' => 'ShopVivaliz',
                    'item_category' => $product['category'] ?? 'Geral',
                    'price' => $product['price'],
                ]]
            ]
        ];
    }

    public function trackAddToCart($product, $quantity = 1) {
        $this->events[] = [
            'name' => 'add_to_cart',
            'params' => [
                'currency' => 'BRL',
                'value' => $product['price'] * $quantity,
                'items' => [[
                    'item_id' => $product['id'],
                    'item_name' => $product['name'],
                    'quantity' => $quantity,
                    'price' => $product['price'],
                ]]
            ]
        ];
    }

    public function trackPurchase($order) {
        $this->events[] = [
            'name' => 'purchase',
            'params' => [
                'currency' => 'BRL',
                'transaction_id' => $order['id'],
                'value' => $order['total'],
                'tax' => $order['tax'] ?? 0,
                'shipping' => $order['shipping'] ?? 0,
                'coupon' => $order['coupon'] ?? '',
                'items' => $order['items'] ?? []
            ]
        ];
    }

    public function trackSearch($search_term, $results_count) {
        $this->events[] = [
            'name' => 'search',
            'params' => [
                'search_term' => $search_term,
                'results_count' => (int)$results_count,
            ]
        ];
    }

    public function trackCustomEvent($event_name, $params = []) {
        $this->events[] = [
            'name' => $event_name,
            'params' => $params
        ];
    }

    public function sendEvents() {
        if (empty($this->events)) return;

        // GA4
        $this->sendToGA4();

        // Facebook Pixel
        if ($this->facebook_pixel) {
            $this->sendToFacebookPixel();
        }

        // TikTok Pixel
        if ($this->tiktok_pixel) {
            $this->sendToTikTokPixel();
        }

        // Limpar eventos
        $this->events = [];
    }

    private function sendToGA4() {
        $ga4Secret = getenv('GA4_SECRET') ?: '';
        if ($this->ga4_id === '' || $this->ga4_id === 'G-XXXXXXXXXX' || $ga4Secret === '') {
            return;
        }

        $payload = [];

        foreach ($this->events as $event) {
            $payload[] = [
                'name' => $event['name'],
                'params' => array_merge(
                    $event['params'],
                    [
                        'session_id' => $this->getSessionId(),
                        'timestamp_micros' => (int)(microtime(true) * 1000000),
                        'user_id' => $this->getUserId(),
                    ]
                )
            ];
        }

        // GA4 Measurement Protocol
        $ch = curl_init('https://www.google-analytics.com/mp/collect');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'client_id' => $this->getClientId(),
                'events' => $payload,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_URL => "https://www.google-analytics.com/mp/collect?measurement_id={$this->ga4_id}&api_secret={$ga4Secret}"
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    private function sendToFacebookPixel() {
        // Facebook Conversion API
        $accessToken = getenv('FACEBOOK_ACCESS_TOKEN') ?: '';
        if ($this->facebook_pixel === '' || $accessToken === '') {
            return;
        }

        foreach ($this->events as $event) {
            $facebookEvent = $this->mapToFacebookEvent($event['name']);

            if (!$facebookEvent) continue;

            $payload = [
                'data' => [
                    [
                        'event_name' => $facebookEvent,
                        'event_time' => time(),
                        'action_source' => 'website',
                        'user_data' => [
                            'em' => hash('sha256', (string)($_SESSION['user_email'] ?? '')),
                            'ph' => hash('sha256', (string)($_SESSION['user_phone'] ?? '')),
                        ],
                        'custom_data' => $event['params'],
                    ]
                ]
            ];

            $ch = curl_init("https://graph.facebook.com/v17.0/{$this->facebook_pixel}/events");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_URL => "https://graph.facebook.com/v17.0/{$this->facebook_pixel}/events?access_token={$accessToken}"
            ]);

            curl_exec($ch);
            curl_close($ch);
        }
    }

    private function sendToTikTokPixel() {
        // TikTok Pixel
        $accessToken = getenv('TIKTOK_PIXEL_TOKEN') ?: '';
        if ($this->tiktok_pixel === '' || $accessToken === '') {
            return;
        }

        foreach ($this->events as $event) {
            $tiktokEvent = $this->mapToTikTokEvent($event['name']);

            if (!$tiktokEvent) continue;

            $payload = [
                'event' => $tiktokEvent,
                'event_id' => uniqid(),
                'timestamp' => date('Y-m-d H:i:s'),
                'context' => [
                    'user' => [
                        'external_id' => $this->getUserId(),
                    ],
                    'page' => [
                        'url' => $this->buildAbsoluteUrl((string)($_SERVER['REQUEST_URI'] ?? '/')),
                    ]
                ],
                'properties' => $event['params'],
            ];

            $ch = curl_init('https://track.tiktok.com/v1/events');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "Access-Token: {$accessToken}"
                ]
            ]);

            curl_exec($ch);
            curl_close($ch);
        }
    }

    private function mapToFacebookEvent($ga_event) {
        $mapping = [
            'page_view' => 'PageView',
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'purchase' => 'Purchase',
            'search' => 'Search',
        ];

        return $mapping[$ga_event] ?? null;
    }

    private function mapToTikTokEvent($ga_event) {
        $mapping = [
            'page_view' => 'PageView',
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'purchase' => 'PlaceAnOrder',
            'search' => 'Search',
        ];

        return $mapping[$ga_event] ?? null;
    }

    private function getClientId() {
        if (empty($_COOKIE['_ga'])) {
            $_COOKIE['_ga'] = bin2hex(random_bytes(8));
            setcookie('_ga', $_COOKIE['_ga'], [
                'expires' => time() + 63072000,
                'path' => '/',
                'secure' => $this->isHttps(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        return $_COOKIE['_ga'];
    }

    private function getSessionId() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_id();
        }

        return $this->getClientId();
    }

    private function getUserId() {
        return $_SESSION['user_id'] ?? $this->getClientId();
    }

    private function buildAbsoluteUrl($path) {
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $normalizedPath = '/' . ltrim((string)$path, '/');
        return ($this->isHttps() ? 'https://' : 'http://') . $host . $normalizedPath;
    }

    private function isHttps() {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $https === 'on' || $https === '1' || $forwarded === 'https';
    }

    public function getTrackingCode() {
        $blocks = [];

        $gtm_id = getenv('TAG_MANAGER') ?: '';
        if ($gtm_id !== '') {
            $blocks[] = <<<JS
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$gtm_id}');</script>
<!-- End Google Tag Manager -->
JS;
        }

        if ($this->google_ads_id !== '') {
            $blocks[] = <<<JS
<!-- Google Ads -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$this->google_ads_id}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$this->google_ads_id}');
</script>
JS;
        }

        if ($this->ga4_id !== '' && $this->ga4_id !== 'G-XXXXXXXXXX') {
            $blocks[] = <<<JS
<!-- GA4 -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$this->ga4_id}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$this->ga4_id}', {
    'anonymize_ip': true,
    'cookie_flags': 'SameSite=None;Secure'
  });
</script>
JS;
        }

        if ($this->facebook_pixel !== '') {
            $blocks[] = <<<JS
<!-- Facebook Pixel -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '{$this->facebook_pixel}');
  fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id={$this->facebook_pixel}&ev=PageView&noscript=1" /></noscript>
JS;
        }

        if ($this->tiktok_pixel !== '') {
            $blocks[] = <<<JS
<!-- TikTok Pixel -->
<script>
  !function (w, d, t) {
    w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq[ttq.methods[n]].apply(ttq.instance,[t].concat(ttq.methods[n]));return e};ttq.instances=[],ttq._i={},ttq._t={},ttq._o=!0,ttq.setPixelId=function(t){ttq._i[t]=[],ttq.instance(t)},ttq.trackEvent=function(t){return ttq.track(t)};
  }(window, document, 'ttq');
  ttq.setPixelId('{$this->tiktok_pixel}');
  ttq.track('PageView');
</script>
JS;
        }

        return implode("\n\n", $blocks);
    }
}

// Global instance
$GLOBALS['analytics'] = new AnalyticsTracking();

// Helper functions
function track_page_view($title, $path = null) {
    $GLOBALS['analytics']->trackPageView($title, $path ?? $_SERVER['REQUEST_URI']);
}

function track_view_item($product) {
    $GLOBALS['analytics']->trackViewItem($product);
}

function track_add_to_cart($product, $qty = 1) {
    $GLOBALS['analytics']->trackAddToCart($product, $qty);
}

function track_purchase($order) {
    $GLOBALS['analytics']->trackPurchase($order);
}

function track_search($searchTerm, $resultsCount = 0) {
    $GLOBALS['analytics']->trackSearch($searchTerm, $resultsCount);
}

function send_analytics() {
    $GLOBALS['analytics']->sendEvents();
}

function get_tracking_code() {
    return $GLOBALS['analytics']->getTrackingCode();
}

// Auto-send on shutdown
register_shutdown_function('send_analytics');
