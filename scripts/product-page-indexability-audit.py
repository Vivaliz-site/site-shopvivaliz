#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Valida prontidão de páginas de produto dinâmicas e indexáveis."""
from __future__ import annotations

import hashlib
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parents[1]
CATALOG_PATH = ROOT / "api" / "catalog" / "fallback-products.json"
HTACCESS_PATH = ROOT / ".htaccess"
PRODUCT_PAGE_PATH = ROOT / "produto.php"
REPORT_JSON = ROOT / "logs" / "product-page-indexability-audit.json"
REPORT_MD = ROOT / "logs" / "product-page-indexability-audit.md"


def write_atomic(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(content, encoding="utf-8")
    os.replace(temporary, path)


def main() -> int:
    products = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    if not isinstance(products, list):
        raise SystemExit("catalog_payload_must_be_a_list")
    slugs = [str(product.get("slug") or "").strip() for product in products if isinstance(product, dict)]
    valid_slugs = [slug for slug in slugs if slug]
    fallback_descriptions = sum(
        1 for product in products
        if isinstance(product, dict) and not str(product.get("description") or "").strip()
    )
    htaccess = HTACCESS_PATH.read_text(encoding="utf-8")
    product_page = PRODUCT_PAGE_PATH.read_text(encoding="utf-8")

    checks = {
        "catalog_is_non_empty": len(products) > 0,
        "every_product_has_unique_slug": len(valid_slugs) == len(set(valid_slugs)) == len(products),
        "rewrite_rule_present": "RewriteRule ^produto/([a-z0-9][a-z0-9\\-]*)/?$ produto.php?slug=$1 [L,QSA]" in htaccess,
        "canonical_present": '<link rel="canonical"' in product_page,
        "product_jsonld_present": "'@type'          => 'Product'" in product_page,
        "breadcrumb_jsonld_present": "'@type' => 'BreadcrumbList'" in product_page,
        "not_found_noindex_present": '<meta name="robots" content="noindex,follow">' in product_page,
        "og_url_present": '<meta property="og:url"' in product_page,
    }
    passed = all(checks.values())
    report = {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "status": "PASSED" if passed else "FAILED",
        "passed": passed,
        "catalog_products": len(products),
        "products_with_slug": len(valid_slugs),
        "unique_slugs": len(set(valid_slugs)),
        "fallback_description_count": fallback_descriptions,
        "checks": checks,
        "inputs": {
            "catalog_sha256": hashlib.sha256(CATALOG_PATH.read_bytes()).hexdigest(),
            "htaccess_sha256": hashlib.sha256(HTACCESS_PATH.read_bytes()).hexdigest(),
            "product_page_sha256": hashlib.sha256(PRODUCT_PAGE_PATH.read_bytes()).hexdigest(),
        },
    }
    write_atomic(REPORT_JSON, json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    markdown = [
        "# Product page indexability audit", "",
        f"- generated_at: `{report['generated_at']}`",
        f"- status: **{report['status']}**",
        f"- catalog_products: {report['catalog_products']}",
        f"- products_with_slug: {report['products_with_slug']}",
        f"- unique_slugs: {report['unique_slugs']}",
        f"- fallback_description_count: {fallback_descriptions}", "", "## Checks", "",
    ]
    markdown.extend(f"- {name}: {value}" for name, value in checks.items())
    write_atomic(REPORT_MD, "\n".join(markdown) + "\n")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if passed else 2


if __name__ == "__main__":
    raise SystemExit(main())
