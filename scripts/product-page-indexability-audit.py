#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Valida prontidão de páginas de produto dinâmicas e indexáveis."""
from __future__ import annotations

import json
import sys
from pathlib import Path

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parents[1]
CATALOG_PATH = ROOT / "api" / "catalog" / "fallback-products.json"
HTACCESS_PATH = ROOT / ".htaccess"
PRODUCT_PAGE_PATH = ROOT / "produto.php"
REPORT_JSON = ROOT / "logs" / "product-page-indexability-audit.json"
REPORT_MD = ROOT / "logs" / "product-page-indexability-audit.md"


def main() -> int:
    products = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    slugs = [str(product.get("slug") or "").strip() for product in products if isinstance(product, dict)]
    valid_slugs = [slug for slug in slugs if slug]
    fallback_descriptions = sum(
        1
        for product in products
        if isinstance(product, dict) and not str(product.get("description") or "").strip()
    )

    htaccess = HTACCESS_PATH.read_text(encoding="utf-8")
    product_page = PRODUCT_PAGE_PATH.read_text(encoding="utf-8")

    checks = {
        "catalog_products": len(products),
        "products_with_slug": len(valid_slugs),
        "unique_slugs": len(set(valid_slugs)),
        "rewrite_rule_present": "RewriteRule ^produto/([a-z0-9][a-z0-9\\-]*)/?$ produto.php?slug=$1 [L,QSA]" in htaccess,
        "canonical_present": '<link rel="canonical"' in product_page,
        "product_jsonld_present": "'@type'          => 'Product'" in product_page,
        "breadcrumb_jsonld_present": "'@type' => 'BreadcrumbList'" in product_page,
        "not_found_noindex_present": '<meta name="robots" content="noindex,follow">' in product_page,
        "og_url_present": '<meta property="og:url"' in product_page,
        "fallback_description_count": fallback_descriptions,
    }

    site_ready = (
        checks["products_with_slug"] == checks["unique_slugs"] == checks["catalog_products"]
        and checks["rewrite_rule_present"]
        and checks["canonical_present"]
        and checks["product_jsonld_present"]
        and checks["breadcrumb_jsonld_present"]
        and checks["not_found_noindex_present"]
        and checks["og_url_present"]
    )

    report = {
        "summary": checks,
        "status": "ok" if site_ready else "warning",
    }

    REPORT_JSON.parent.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    REPORT_MD.write_text(
        "\n".join(
            [
                "# Task 048 - Product page indexability audit",
                "",
                f"- catalog_products: {checks['catalog_products']}",
                f"- products_with_slug: {checks['products_with_slug']}",
                f"- unique_slugs: {checks['unique_slugs']}",
                f"- rewrite_rule_present: {checks['rewrite_rule_present']}",
                f"- canonical_present: {checks['canonical_present']}",
                f"- product_jsonld_present: {checks['product_jsonld_present']}",
                f"- breadcrumb_jsonld_present: {checks['breadcrumb_jsonld_present']}",
                f"- not_found_noindex_present: {checks['not_found_noindex_present']}",
                f"- og_url_present: {checks['og_url_present']}",
                f"- fallback_description_count: {checks['fallback_description_count']}",
                "",
                f"- status: {report['status']}",
            ]
        ) + "\n",
        encoding="utf-8",
    )

    print("Product page indexability audit")
    for key, value in checks.items():
        print(f"{key}: {value}")
    print(f"JSON saved at: {REPORT_JSON.relative_to(ROOT)}")
    print(f"Markdown saved at: {REPORT_MD.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
