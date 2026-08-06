#!/usr/bin/env python3
"""Static guard: catalog and image approvals must call real publishers."""
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
    "ml": ROOT / "includes/marketplace/MercadoLivrePublisher.php",
    "shopee": ROOT / "includes/marketplace/ShopeePublisher.php",
    "amazon": ROOT / "includes/marketplace/AmazonPublisher.php",
    "tiktok": ROOT / "includes/marketplace/TikTokPublisher.php",
    "tiny": ROOT / "includes/marketplace/TinyPublisher.php",
    "tiktok_python": ROOT / "scripts/utils/tiktok_client.py",
}

FORBIDDEN_MARKERS = (
    "[simulado]",
    "finge sucesso",
    "não foi enviado a lugar nenhum",
    "não tem propagação automática",
    "promoção automática para este canal não está implementada",
    "promoção para a loja não é automática",
    "gancho comentado",
)

REQUIRED_SNIPPETS = {
    "catalog_admin": ("CatalogOptimizationPublisher", "Salvar e publicar em", "publication_failed"),
    "catalog_publisher": (
        "SvMercadoLivrePublisher",
        "SvShopeePublisher",
        "SvTikTokPublisher",
        "SvAmazonPublisher",
        "SvTinyPublisher",
        "'publishing'",
        "'published'",
        "'submitted'",
    ),
    "image_admin": ("AiStudioOmnichannelImagePublisher", "channels[]", "Aprovar e publicar nos canais selecionados"),
    "image_publisher": (
        "SvMercadoLivrePublisher",
        "SvShopeePublisher",
        "SvTikTokPublisher",
        "SvAmazonPublisher",
        "SvTinyPublisher",
        "partial_published",
    ),
    "ml": ("/items/", "/description", "read-back"),
    "shopee": ("/api/v2/product/update_item", "/api/v2/media_space/upload_image", "get_item_base_info"),
    "amazon": ("/listings/2021-08-01/items/", "x-amz-access-token", "submission_status"),
    "tiktok": ("/product/202509/products/", "/product/202309/images/upload", "partial_edit"),
    "tiny": ("/produtos/", "produto.alterar.php", "price_preserved"),
    "tiktok_python": ("/product/202509/products/", "/product/202309/images/upload", "image_files"),
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

# Payload protection is centralized and every publisher must invoke it.
runtime = (ROOT / "includes/marketplace/MarketplaceRuntime.php").read_text(encoding="utf-8", errors="replace")
protection_issues: list[str] = []
for key in ("price", "stock", "inventory", "available_quantity", "purchasable_offer"):
    if f"'{key}'" not in runtime:
        protection_issues.append("missing_forbidden_key:" + key)
for name in ("MercadoLivrePublisher.php", "ShopeePublisher.php", "TikTokPublisher.php", "AmazonPublisher.php", "TinyPublisher.php"):
    text = (ROOT / "includes/marketplace" / name).read_text(encoding="utf-8", errors="replace")
    if "sv_market_assert_no_commerce_fields" not in text:
        protection_issues.append("publisher_without_commerce_guard:" + name)
report["checks"]["price_stock_guard"] = {"ok": not protection_issues, "issues": protection_issues}
if protection_issues:
    report["ok"] = False

print(json.dumps(report, ensure_ascii=False, indent=2))
sys.exit(0 if report["ok"] else 2)
