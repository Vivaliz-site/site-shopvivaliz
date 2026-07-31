<?php
/**
 * Facebook Shop & Instagram Shop Configuration
 * Setup for selling on Facebook and Instagram
 * Date: 2026-07-19
 */

/**
 * Facebook Shop Configuration
 *
 * SETUP CHECKLIST:
 * 1. Go to facebook.com/business
 * 2. Create Business Account (if not exist)
 * 3. Install Facebook Pixel (done via head-integrations-complete.php)
 * 4. Setup Instagram Shop
 * 5. Add Products Catalog
 * 6. Connect Product Feed
 */

$facebookShopConfig = [
    'pixel_id' => '429506906425647',
    'business_id' => 'YOUR_BUSINESS_ID', // Get from Meta Business Manager
    'catalog_id' => 'YOUR_CATALOG_ID', // Get from Meta Business Manager
    'page_id' => 'YOUR_PAGE_ID', // Get from your Facebook Page
    'instagram_business_account' => 'YOUR_INSTAGRAM_ACCOUNT_ID',

    // Product Feed Settings
    'product_feed' => [
        'name' => 'ShopVivaliz Catalog',
        'source' => 'https://shopvivaliz.com.br/facebook-shop-feed.php',
        'update_frequency' => 'daily',
        'format' => 'xml'
    ],

    // Checkout Settings
    'checkout' => [
        'type' => 'shopvivaliz_checkout', // On-site checkout
        'currency' => 'BRL',
        'payment_methods' => ['pix', 'credit_card', 'boleto'],
        'shipping_enabled' => true
    ],

    // Shop Policies
    'shop_policies' => [
        'returns_policy' => 'https://shopvivaliz.com.br/politica-devolucoes',
        'privacy_policy' => 'https://shopvivaliz.com.br/politica-privacidade',
        'terms_of_service' => 'https://shopvivaliz.com.br/termos'
    ]
];

/**
 * STEP-BY-STEP SETUP GUIDE
 *
 * Step 1: Create Facebook Business Manager Account
 * - Go to business.facebook.com
 * - Create new business
 * - Verify business email
 *
 * Step 2: Add Instagram Account
 * - Create Instagram Business Account
 * - Connect to Facebook Page
 * - Add payment method
 *
 * Step 3: Setup Product Catalog
 * - Go to Commerce Manager > Catalogs
 * - Create new catalog
 * - Add product feed (XML)
 * - Map product attributes
 *
 * Step 4: Connect Product Feed
 * - URL: https://shopvivaliz.com.br/facebook-shop-feed.php
 * - Format: XML
 * - Update Schedule: Daily
 *
 * Step 5: Enable Shopping
 * - Go to Business Settings > Commerce
 * - Enable Shop on Facebook
 * - Enable Shop on Instagram
 *
 * Step 6: Configure Checkout
 * - Choose: On-site checkout (recommended)
 * - Add payment methods
 * - Setup shipping
 */

/**
 * CONVERSION TRACKING FOR FACEBOOK SHOP
 * Automatically track purchases and revenue
 */

function initFacebookShopTracking() {
    ?>
    <script>
    // Facebook Shop Event Tracking

    // Track Shop Visit
    fbq('track', 'ViewContent', {
        content_type: 'product',
        value: 0,
        currency: 'BRL'
    });

    // Track Product Views
    document.addEventListener('DOMContentLoaded', function() {
        // Find all product elements
        var productLinks = document.querySelectorAll('[data-product-id]');
        productLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                fbq('track', 'ViewContent', {
                    content_id: this.dataset.productId,
                    content_name: this.dataset.productName,
                    content_type: 'product',
                    value: parseFloat(this.dataset.productPrice),
                    currency: 'BRL'
                });
            });
        });
    });

    // Track Cart Add
    window.TrackAddToFacebookCart = function(productId, productName, price) {
        fbq('track', 'AddToCart', {
            content_id: productId,
            content_name: productName,
            value: parseFloat(price),
            currency: 'BRL',
            content_type: 'product'
        });
    };

    // Track Purchase
    window.TrackFacebookPurchase = function(orderId, value) {
        fbq('track', 'Purchase', {
            value: parseFloat(value),
            currency: 'BRL',
            content_type: 'product'
        });
    };
    </script>
    <?php
}

/**
 * INSTAGRAM SHOPPING SETUP
 *
 * Requirements:
 * 1. Instagram Business Account (connected to Facebook)
 * 2. Product Catalog (from Commerce Manager)
 * 3. Storefront enabled
 * 4. Product tags in posts
 *
 * Features:
 * - In-app checkout
 * - Product recommendations
 * - Shopping tags in posts
 * - Stories shopping stickers
 * - Checkout experience
 */

$instagramShopConfig = [
    'enabled' => true,
    'business_account_id' => 'YOUR_INSTAGRAM_ACCOUNT_ID',
    'catalog_id' => 'YOUR_CATALOG_ID',
    'storefront_enabled' => true,
    'checkout_type' => 'instagram_checkout', // In-app checkout
    'currencies' => ['BRL'],

    // Shopping Features
    'features' => [
        'product_tagging' => true,
        'shopping_tags_in_posts' => true,
        'shopping_tags_in_stories' => true,
        'checkout_experience' => 'in_app',
        'product_recommendations' => true,
        'collections' => true
    ]
];

/**
 * FACEBOOK SHOP PRODUCT FEED FORMAT
 *
 * Required Fields:
 * - id (SKU)
 * - title (Product Name)
 * - description
 * - image_url
 * - price
 * - currency
 * - availability (in stock / out of stock)
 * - url (Product Link)
 * - category
 * - brand
 *
 * Optional Fields:
 * - condition (new/used)
 * - mpn
 * - gtin
 * - sale_price
 * - sale_price_effective_date
 */

return [
    'facebook' => $facebookShopConfig,
    'instagram' => $instagramShopConfig,
    'tracking_init' => 'initFacebookShopTracking'
];
?>
