# Device Variants — ShopVivaliz

Fonte: `config/shopvivaliz-device-variants.php` (v9.2.91)

## Desktop (≥1024px)
- banner_home: 1920×620 (96:31)
- category_cover: 1600×480 (10:3)
- product_hero: 1600×900 (16:9)
- product_card: 900×900 (1:1)
- image_rules: wide_composition, more_negative_space, product_large_but_not_cropped, no_tiny_text
- checkout_priority: cart_summary_visible, shipping_quote_visible, payment_methods_visible, buy_button_above_fold

## Smartphone (≤767px)
- banner_home: 1080×1350 (4:5)
- category_cover: 1080×1080 (1:1)
- product_hero: 1080×1350 (4:5)
- product_card: 900×900 (1:1)
- image_rules: product_centered, subject_large, safe_area_top_bottom, no_small_text, thumb_readable
- checkout_priority: sticky_buy_button, cep_field_visible, shipping_quote_visible, pix_boleto_visible, minimal_scroll

## Dual outputs obrigatórios
- home_banner: desktop + smartphone
- category_cover: desktop + smartphone
- product_hero: desktop + smartphone
- marketing_ad_creative: desktop + smartphone

## Regra
admin_approval_required: true — todas as imagens geradas requerem aprovação antes de ir para produção.

## Uso
Este arquivo é lido pelo pipeline de geração de imagens IA para garantir que cada imagem seja gerada
nas dimensões e composição corretas para cada device variant.
