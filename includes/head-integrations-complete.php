<?php
/**
 * ShopVivaliz - Complete Integrations Setup
 * Google Analytics, Tag Manager, Meta Pixel, Conversion Tracking
 * Date: 2026-07-19
 */

// Determine environment
$isProduction = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$domain = $_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br';
?>

<!-- ============================================================================
     GOOGLE TAG MANAGER (Head - No Script)
     ============================================================================ -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-PHZ55CP3');</script>

<!-- ============================================================================
     GOOGLE ANALYTICS 4 (GA4)
     ============================================================================ -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-1H55K1TZ5D"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-1H55K1TZ5D', {
    'page_path': window.location.pathname,
    'page_title': document.title,
    'send_page_view': true,
    'allow_google_signals': true,
    'allow_ad_personalization_signals': true
  });
</script>

<!-- ============================================================================
     META PIXEL (Facebook/Instagram Conversion Tracking)
     ============================================================================ -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '429506906425647');
  fbq('track', 'PageView');

  // Track additional events
  fbq('track', 'ViewContent');
</script>
<noscript>
  <img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=429506906425647&ev=PageView&noscript=1"
  />
</noscript>

<!-- ============================================================================
     GOOGLE SITE VERIFICATION
     ============================================================================ -->
<meta name="google-site-verification" content="YOUR_GOOGLE_SITE_VERIFICATION_CODE" />

<!-- ============================================================================
     ENHANCED E-COMMERCE TRACKING (GA4)
     ============================================================================ -->
<script>
// Track page view with additional context
gtag('event', 'page_view', {
  'page_path': window.location.pathname,
  'page_title': document.title,
  'page_location': window.location.href
});

// Track scroll depth
document.addEventListener('scroll', function() {
  var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
  var docHeight = document.documentElement.scrollHeight - window.innerHeight;
  var scrollPercent = (scrollTop / docHeight) * 100;

  if (scrollPercent > 25 && !window.scrollTracked25) {
    gtag('event', 'scroll', { 'percent_scrolled': 25 });
    window.scrollTracked25 = true;
  }
  if (scrollPercent > 50 && !window.scrollTracked50) {
    gtag('event', 'scroll', { 'percent_scrolled': 50 });
    window.scrollTracked50 = true;
  }
  if (scrollPercent > 75 && !window.scrollTracked75) {
    gtag('event', 'scroll', { 'percent_scrolled': 75 });
    window.scrollTracked75 = true;
  }
});

// Track time on page
setTimeout(function() {
  gtag('event', 'engagement', {
    'engagement_time_msec': 10000
  });
}, 10000);
</script>

<!-- ============================================================================
     CONVERSION TRACKING HELPERS (Triggered by checkout)
     ============================================================================ -->
<script>
// Purchase conversion tracking
function trackPurchase(orderId, value, currency = 'BRL') {
  // Google Analytics 4
  gtag('event', 'purchase', {
    'transaction_id': orderId,
    'value': parseFloat(value),
    'currency': currency,
    'items': []
  });

  // Meta Pixel
  if (typeof fbq !== 'undefined') {
    fbq('track', 'Purchase', {
      value: parseFloat(value),
      currency: currency,
      content_type: 'product'
    });
  }
}

// Add to cart tracking
function trackAddToCart(itemName, price, quantity = 1) {
  // Google Analytics 4
  gtag('event', 'add_to_cart', {
    'items': [{
      'item_id': itemName,
      'item_name': itemName,
      'price': parseFloat(price),
      'quantity': parseInt(quantity)
    }]
  });

  // Meta Pixel
  if (typeof fbq !== 'undefined') {
    fbq('track', 'AddToCart', {
      content_name: itemName,
      content_type: 'product',
      value: parseFloat(price),
      currency: currency
    });
  }
}

// Begin checkout
function trackBeginCheckout() {
  gtag('event', 'begin_checkout');
  if (typeof fbq !== 'undefined') {
    fbq('track', 'InitiateCheckout');
  }
}

// View item
function trackViewItem(itemName, itemId, price) {
  gtag('event', 'view_item', {
    'items': [{
      'item_id': itemId,
      'item_name': itemName,
      'price': parseFloat(price)
    }]
  });

  if (typeof fbq !== 'undefined') {
    fbq('track', 'ViewContent', {
      content_name: itemName,
      content_type: 'product',
      value: parseFloat(price),
      currency: 'BRL'
    });
  }
}

// Make functions globally available
window.ShopVivalizTracking = {
  trackPurchase: trackPurchase,
  trackAddToCart: trackAddToCart,
  trackBeginCheckout: trackBeginCheckout,
  trackViewItem: trackViewItem
};
</script>

<!-- ============================================================================
     DATALAYER INITIALIZATION (For GTM)
     ============================================================================ -->
<script>
// Initialize dataLayer if not already done
if (typeof dataLayer === 'undefined') {
  var dataLayer = [];
}

// Push page information to dataLayer
dataLayer.push({
  'pageType': '<?php echo basename($_SERVER['PHP_SELF'], '.php'); ?>',
  'pagePath': '<?php echo $_SERVER['REQUEST_URI']; ?>',
  'pageTitle': '<?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'ShopVivaliz'; ?>',
  'environment': '<?php echo $isProduction ? 'production' : 'development'; ?>',
  'timestamp': new Date().toISOString()
});
</script>

<!-- ============================================================================
     OPTIMIZE (Optional - Google Optimize for A/B Testing)
     ============================================================================ -->
<script src="https://www.googleoptimize.com/optimize.js?id=OPT-XXXXXXX"></script>

<!-- ============================================================================
     DEBUG MODE (Development Only)
     ============================================================================ -->
<?php if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') === 0): ?>
<script>
  window.ShopVivalizDebug = true;
  console.log('[Tracking] Google Analytics 4 initialized');
  console.log('[Tracking] Google Tag Manager initialized');
  console.log('[Tracking] Meta Pixel initialized');
  console.log('[Tracking] DataLayer ready', dataLayer);
</script>
<?php endif; ?>
