<?php

declare(strict_types=1);

return array(
    'version' => '9.2.104',
    'updated_at' => '2026-07-15T12:00:00-03:00',
    'variants' => array(
        'desktop' => array(
            'label' => 'Desktop',
            'viewport_min_width' => 1024,
            'primary_breakpoint' => '>=1024px',
            'banner_home' => array('width' => 1920, 'height' => 620, 'ratio' => '96:31'),
            'category_cover' => array('width' => 1600, 'height' => 480, 'ratio' => '10:3'),
            'product_hero' => array('width' => 1600, 'height' => 900, 'ratio' => '16:9'),
            'product_card' => array('width' => 900, 'height' => 900, 'ratio' => '1:1'),
            'checkout_priority' => array('cart_summary_visible', 'shipping_quote_visible', 'payment_methods_visible', 'buy_button_above_fold'),
            'image_rules' => array('wide_composition', 'more_negative_space', 'product_large_but_not_cropped', 'no_tiny_text'),
        ),
        'smartphone' => array(
            'label' => 'Smartphone',
            'viewport_max_width' => 767,
            'primary_breakpoint' => '<=767px',
            'banner_home' => array('width' => 1080, 'height' => 1350, 'ratio' => '4:5'),
            'category_cover' => array('width' => 1080, 'height' => 1080, 'ratio' => '1:1'),
            'product_hero' => array('width' => 1080, 'height' => 1350, 'ratio' => '4:5'),
            'product_card' => array('width' => 900, 'height' => 900, 'ratio' => '1:1'),
            'checkout_priority' => array('sticky_buy_button', 'cep_field_visible', 'shipping_quote_visible', 'pix_boleto_visible', 'minimal_scroll'),
            'image_rules' => array('product_centered', 'subject_large', 'safe_area_top_bottom', 'no_small_text', 'thumb_readable'),
        ),
    ),
    'required_dual_outputs' => array(
        'home_banner' => array('desktop', 'smartphone'),
        'category_cover' => array('desktop', 'smartphone'),
        'product_hero' => array('desktop', 'smartphone'),
        'marketing_ad_creative' => array('desktop', 'smartphone'),
    ),
    'admin_approval_required' => true,
);
