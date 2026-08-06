#!/usr/bin/env python3
"""Static guard for real omnichannel publication and secure credentials.

A UI success message, staging status or API submission is not enough. Each
channel must call a real client, protect commerce fields, preserve rotated OAuth
tokens and either confirm read-back or explicitly remain submitted for review.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGETS = {
    "catalog_admin": ROOT / "admin/catalog-optimization/admin_catalog.php",
    "catalog_publisher": ROOT / "admin/catalog-optimization/src/CatalogPublisher.php",
    "image_admin": ROOT / "admin/ai-image-studio/admin_validate.php",
    "image_publisher": ROOT / "admin/ai-image-studio/src/OmnichannelImagePublisher.php",
    "runtime_schema": ROOT / "includes/catalog-publication-schema.php",
    "marketplace_runtime": ROOT / "includes/marketplace/MarketplaceRuntime.php",
    "storefront_runtime": ROOT / "includes/catalog-runtime.php",
    "site_seo": ROOT / "includes/product-seo.php",
    "ml": ROOT / "includes/marketplace/MercadoLivrePublisher.php",
    "shopee": ROOT / "includes/marketplace/ShopeePublisher.php",
    "amazon": ROOT / "includes/marketplace/AmazonPublisher.php",
    "tiktok": ROOT / "includes/marketplace/TikTokPublisher.php",
    "tiny": ROOT / "includes/marketplace/TinyPublisher.php",
    "shopee_python": ROOT / "scripts/utils/shopee_client.py",
    "shopee_compat": ROOT / "utils/shopee_client.py",
    "tiktok_python": ROOT / "scripts/utils/tiktok_client.py",
    "runtime_configurator": ROOT / "scripts/configure-production-runtime.py",
    "readiness": ROOT / "scripts/maintenance/marketplace_publication_readiness.php",
}

FORBIDDEN_MARKERS = (
    "[simulado]", "finge sucesso", "não foi enviado a lugar nenhum",
    "não tem propagação automática", "promoção automática para este canal não está implementada",
    "promoção para a loja não é automática", "gancho comentado", "mock_success", "fake_success",
)

REQUIRED_SNIPPETS = {
    "catalog_admin": ("CatalogOptimizationPublisher", "Salvar e publicar em", "publication_failed", "submitted"),
    "catalog_publisher": (
        "SvMercadoLivrePublisher", "SvShopeePublisher", "SvTikTokPublisher", "SvAmazonPublisher",
        "SvTinyPublisher", "'publishing'", "'published'", "'submitted'", "sv_market_write_publication",
    ),
    "image_admin": ("AiStudioOmnichannelImagePublisher", "channels[]", "Aprovar e publicar nos canais selecionados"),
    "image_publisher": (
        "SvMercadoLivrePublisher", "SvShopeePublisher", "SvTikTokPublisher", "SvAmazonPublisher",
        "SvTinyPublisher", "partial_published", "sv_market_write_publication",
    ),
    "runtime_schema": (
        "product_channel_content", "product_channel_mappings", "catalog_publications",
        "product_images", "publication_summary_json",
    ),
    "marketplace_runtime": (
        "sv_market_assert_no_commerce_fields", "sv_market_write_publication", "sv_market_save_mapping",
        "sv_market_save_channel_content", "CURLOPT_SSL_VERIFYPEER",
    ),
    "storefront_runtime": ("bullet_points", "seo_keywords", "marketing_hooks", "meta_title", "meta_description"),
    "site_seo": ("meta_title", "meta_description", "bullet_points"),
    "ml": ("/items/", "/description", "read-back", "sv_market_assert_no_commerce_fields"),
    "shopee": (
        "/api/v2/product/update_item", "/api/v2/media_space/upload_image", "get_item_base_info",
        "SHOPEE_REFRESH_TOKEN", "/api/v2/auth/access_token/get", "SHOPEE_TOKEN_FILE",
        "shopee-tokens.json", "description_confirmed", "tempnam", "rename($temporary, $path)",
    ),
    "amazon": ("/listings/2021-08-01/items/", "x-amz-access-token", "submission_status", "'status' => 'submitted'"),
    "tiktok": (
        "/product/202509/products/", "/product/202309/images/upload", "partial_edit",
        "/api/v2/token/refresh", "return_under_review_version", "TIKTOK_TOKEN_FILE",
        "tiktok-tokens.json", "'status' => $publicationStatus", "rename($temporary, $path)",
    ),
    "tiny": (
        "/produtos/", "produto.alterar.php", "produtos.pesquisa.php", "exact_sku",
        "price_preserved", "stock_untouched",
    ),
    "shopee_python": (
        "SHOPEE_REFRESH_TOKEN", "/auth/access_token/get", "_send_with_refresh",
        "SHOPEE_TOKEN_FILE", "shopee-tokens.json", "os.replace",
    ),
    "shopee_compat": ("from scripts.utils.shopee_client import ShopeeClient",),
    "tiktok_python": (
        "/product/202509/products/", "/product/202309/images/upload", "image_files",
        "/api/v2/token/refresh", "under_review=True", "TIKTOK_TOKEN_FILE", "tiktok-tokens.json", "os.replace",
    ),
    "runtime_configurator": (
        "LEGACY_KEYS", "EXTENDED_KEYS", "payload_mode", "validate_no_placeholders",
        "shared env metadata changed unexpectedly", "os.chown", "os.replace",
        "TIKTOK_APP_KEY", "AMAZON_LWA_CLIENT_ID", "SHOPEE_PARTNER_ID", "ML_CLIENT_ID",
    ),
    "readiness": (
        "private_token_file", "exact_sku_lookup_enabled", "publication_requires_api_confirmation",
        "price_payload_guard", "stock_payload_guard",
    ),
}

report: dict[str, object] = {"ok": True, "checks": {}}
for name, path in TARGETS.items():
    issues: list[str] = []
    if not path.is_file():
        issues.append("file_missing")
        text = ""
    else:
        text = path.read_text(encoding="utf-8", errors="replace")
        lower = text.lower()
        for marker in FORBIDDEN_MARKERS:
            if marker in lower:
                issues.append("simulation_marker:" + marker)
        for snippet in REQUIRED_SNIPPETS.get(name, ()):
            if snippet not in text:
                issues.append("required_snippet_missing:" + snippet)
    report["checks"][name] = {"ok": not issues, "issues": issues}
    if issues:
        report["ok"] = False

runtime = (ROOT / "includes/marketplace/MarketplaceRuntime.php").read_text(encoding="utf-8", errors="replace")
protection_issues: list[str] = []
for key in ("price", "stock", "inventory", "available_quantity", "purchasable_offer"):
    if f"'{key}'" not in runtime:
        protection_issues.append("missing_forbidden_key:" + key)
for name in ("MercadoLivrePublisher.php", "ShopeePublisher.php", "TikTokPublisher.php", "AmazonPublisher.php"):
    text = (ROOT / "includes/marketplace" / name).read_text(encoding="utf-8", errors="replace")
    if "sv_market_assert_no_commerce_fields" not in text:
        protection_issues.append("publisher_without_commerce_guard:" + name)

tiny = (ROOT / "includes/marketplace/TinyPublisher.php").read_text(encoding="utf-8", errors="replace")
if "price_preserved" not in tiny or "stock_untouched" not in tiny:
    protection_issues.append("tiny_without_price_stock_readback_guard")
if "'estoque'" in tiny or '"estoque"' in tiny:
    protection_issues.append("tiny_image_payload_mentions_stock")
report["checks"]["price_stock_guard"] = {"ok": not protection_issues, "issues": protection_issues}
if protection_issues:
    report["ok"] = False

status_issues: list[str] = []
for publisher_name in (
    "MercadoLivrePublisher.php", "ShopeePublisher.php", "TikTokPublisher.php",
    "AmazonPublisher.php", "TinyPublisher.php",
):
    text = (ROOT / "includes/marketplace" / publisher_name).read_text(encoding="utf-8", errors="replace")
    if "'http_status'" not in text or "'response'" not in text or "'external_id'" not in text:
        status_issues.append("publisher_without_evidence:" + publisher_name)
report["checks"]["publication_evidence"] = {"ok": not status_issues, "issues": status_issues}
if status_issues:
    report["ok"] = False

print(json.dumps(report, ensure_ascii=False, indent=2))
sys.exit(0 if report["ok"] else 2)
