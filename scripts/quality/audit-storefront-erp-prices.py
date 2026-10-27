#!/usr/bin/env python3
"""Fail-closed, read-only comparison of the complete public price catalog with Olist ERP v3.

This program does not edit products or prices. Production execution is bounded by
the ERP's built-in request throttling and emits an auditable JSON report.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import re
import sys
import urllib.request
from collections import defaultdict
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
PUBLIC_ROOT = "https://shopvivaliz.com.br"
EXPLICIT_EXCLUDED_SKUS = frozenset({"parafuso5x16"})


def cents(value: Any) -> int | None:
    try:
        return int((Decimal(str(value if value is not None else "0")) * 100).quantize(
            Decimal("1"), rounding=ROUND_HALF_UP))
    except (TypeError, InvalidOperation, ValueError):
        return None


def effective_price(regular: int | None, promotional: int | None) -> int | None:
    if regular is None or regular <= 0:
        return None
    return promotional if promotional is not None and 0 < promotional < regular else regular


def extract_prices(item: dict[str, Any]) -> tuple[int | None, int | None]:
    values = item.get("precos") if isinstance(item.get("precos"), dict) else {}
    regular = values.get("preco", values.get("preco_venda", item.get("preco")))
    promotional = values.get("precoPromocional", values.get("preco_promocional", 0))
    return cents(regular), cents(promotional)


def read_json(url: str) -> dict[str, Any]:
    req = urllib.request.Request(url, headers={
        "User-Agent": "ShopVivaliz-ERP-Price-ReadOnly-Audit/1.0",
        "Accept": "application/json",
        "Cache-Control": "no-cache",
    })
    with urllib.request.urlopen(req, timeout=25) as response:
        if response.status != 200:
            raise RuntimeError("unexpected_public_http_status")
        payload = json.loads(response.read(5_000_000))
    if not isinstance(payload, dict):
        raise RuntimeError("invalid_public_json")
    return payload


def public_products() -> tuple[list[dict[str, Any]], str]:
    version = str(read_json(PUBLIC_ROOT + "/api/health/version.php").get("release_sha") or "")
    products = []
    for page in range(1, 501):
        payload = read_json(PUBLIC_ROOT + f"/api/catalog/products.php?limit=200&page={page}&no_cache=1")
        rows = payload.get("products")
        if not isinstance(rows, list):
            raise RuntimeError("public_catalog_missing_products")
        products.extend(row for row in rows if isinstance(row, dict))
        pages = int(payload.get("total_pages") or 0)
        if pages < 1 or pages > 500:
            raise RuntimeError("public_catalog_invalid_page_count")
        if page >= pages:
            if len(products) != int(payload.get("total", -1)):
                raise RuntimeError("public_catalog_incomplete_pagination")
            return products, version
    raise RuntimeError("public_catalog_pagination_limit")


def evaluate(
    active_rows: list[dict[str, Any]],
    erp_details: dict[str, dict[str, Any]],
    cache_rows: list[dict[str, Any]],
    storefront_rows: list[dict[str, Any]],
) -> dict[str, Any]:
    active = {str(x.get("id")): x for x in active_rows if x.get("id") is not None}
    cached = {str(x.get("id")): x for x in cache_rows if x.get("id") is not None}
    public = {str(x.get("olist_product_id") or x.get("id")): x for x in storefront_rows
              if x.get("olist_product_id") or x.get("id")}
    issues: list[dict[str, Any]] = []
    excluded: list[dict[str, Any]] = []
    verified = 0
    verified_published = 0
    sku_groups: dict[str, list[dict[str, Any]]] = defaultdict(list)

    for pid in sorted(active):
        detail = erp_details.get(pid)
        source = active[pid]
        item = cached.get(pid)
        page = public.get(pid)
        sku = str((detail or {}).get("sku") or (detail or {}).get("codigo")
                  or (item or {}).get("sku") or source.get("sku") or "").strip()
        if not detail:
            issues.append({"code": "erp_detail_missing", "id": pid, "sku": sku})
            continue
        regular, promotional = extract_prices(detail)
        effective = effective_price(regular, promotional)
        if effective is None:
            issues.append({"code": "erp_price_invalid", "id": pid, "sku": sku})
            continue
        verified += 1
        if sku:
            sku_groups[sku.casefold()].append({
                "id": pid, "regular_cents": regular,
                "promotional_cents": promotional or 0,
                "effective_cents": effective,
            })
        if item is None:
            issues.append({"code": "active_erp_product_not_cached", "id": pid, "sku": sku})
        else:
            cached_regular, cached_promo = extract_prices(item)
            if (cached_regular, cached_promo or 0) != (regular, promotional or 0):
                issues.append({"code": "cache_price_differs_from_erp", "id": pid, "sku": sku,
                    "erp_regular_cents": regular, "erp_promo_cents": promotional or 0,
                    "cache_regular_cents": cached_regular, "cache_promo_cents": cached_promo or 0})
        if page is None:
            if sku.casefold() in EXPLICIT_EXCLUDED_SKUS:
                excluded.append({"id": pid, "sku": sku, "reason": "explicit_storefront_exclusion"})
            elif not sku:
                excluded.append({"id": pid, "sku": sku, "reason": "missing_sku"})
            elif any(str(other.get("sku") or "").strip().casefold() == sku.casefold()
                     for other_id, other in cached.items() if other_id != pid):
                excluded.append({"id": pid, "sku": sku, "reason": "duplicate_sku"})
            else:
                issues.append({"code": "active_priced_erp_product_not_published", "id": pid, "sku": sku})
        else:
            published_price = cents(page.get("price"))
            if published_price != effective:
                issues.append({"code": "storefront_price_differs_from_erp", "id": pid, "sku": sku,
                    "erp_effective_cents": effective, "published_cents": published_price})
            else:
                verified_published += 1
    for sku, members in sorted(sku_groups.items()):
        if len(members) > 1:
            issues.append({"code": "duplicate_erp_sku", "sku": sku, "members": members,
                "conflicting_prices": len({(m["regular_cents"], m["promotional_cents"])
                                             for m in members}) > 1})
    for pid in sorted(set(cached) - set(active)):
        issues.append({"code": "cache_product_missing_from_active_erp", "id": pid})
    for pid in sorted(set(public) - set(active)):
        issues.append({"code": "published_product_missing_from_active_erp", "id": pid})
    for pid in sorted(set(public) - set(cached)):
        issues.append({"code": "published_product_missing_from_cache", "id": pid})
    counts = {
        "erp_active": len(active), "cache_active": len(cached),
        "site_published": len(public), "erp_details_verified": verified,
        "published_prices_matching_erp": verified_published,
        "excluded_or_duplicate_not_published": len(excluded),
        "duplicate_erp_sku_groups": sum(x["code"] == "duplicate_erp_sku" for x in issues),
        "duplicate_erp_sku_price_conflicts": sum(
            x["code"] == "duplicate_erp_sku" and x["conflicting_prices"] for x in issues),
        "errors": len(issues),
    }
    return {"status": "PASS" if not issues and active else "FAIL",
            "counts": counts, "issues": issues, "unpublished_exclusions": excluded}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--expected-sha", default="", help="exact immutable release SHA")
    parser.add_argument("--output", default="/tmp/shopvivaliz-erp-price-audit.json")
    args = parser.parse_args()
    if args.expected_sha and not re.fullmatch(r"[0-9a-f]{40}", args.expected_sha):
        parser.error("invalid expected_sha")
    os.chdir(ROOT)
    live_sha = (ROOT / ".release-sha").read_text().strip()
    if args.expected_sha and live_sha != args.expected_sha:
        raise RuntimeError("immutable_release_sha_mismatch")
    cache_path = ROOT / "storage/products-cache-ativos.json"
    raw_cache = cache_path.read_bytes()
    cache_doc = json.loads(raw_cache)
    cache_rows = cache_doc.get("itens")
    if not isinstance(cache_rows, list) or not cache_rows:
        raise RuntimeError("active_catalog_cache_unavailable")
    site_rows, served_sha = public_products()
    if not re.fullmatch(r"[0-9a-f]{40}", served_sha) or served_sha != live_sha:
        raise RuntimeError("public_release_sha_mismatch")
    daemon = ROOT / "daemon-sync-products.py"
    spec = importlib.util.spec_from_file_location("shopvivaliz_erp_audit_readonly", daemon)
    if spec is None or spec.loader is None:
        raise RuntimeError("erp_api_helper_unavailable")
    api = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = api
    spec.loader.exec_module(api)
    token = api.get_token()
    if not token:
        raise RuntimeError("erp_oauth_token_missing")
    active = api.fetch_products_active(token)
    if not active:
        raise RuntimeError("erp_active_list_empty")
    details: dict[str, dict[str, Any]] = {}
    errors: list[dict[str, str]] = []
    for index, product in enumerate(active, 1):
        pid = str(product.get("id") or "")
        if not pid.isdecimal():
            errors.append({"code": "erp_product_id_invalid", "id": pid})
            continue
        try:
            result = api.api_get("produtos/" + pid, token, timeout=14, attempts=3)
            detail = result.get("produto") if isinstance(result.get("produto"), dict) else result.get("data") if isinstance(result.get("data"), dict) else result
            if isinstance(detail.get("produto"), dict):
                detail = detail["produto"]
            if not isinstance(detail, dict):
                raise RuntimeError("erp_detail_invalid")
            details[pid] = detail
        except Exception as exc:
            errors.append({"code": "erp_detail_query_failed", "id": pid, "reason": type(exc).__name__})
        if index % 25 == 0 or index == len(active):
            print(f"ERP_DETAILS_CHECKED={index}/{len(active)} API_ERRORS={len(errors)}", flush=True)
    result = evaluate(active, details, cache_rows, site_rows)
    if errors:
        result["issues"].extend(errors)
        result["counts"]["errors"] = len(result["issues"])
        result["status"] = "FAIL"
    result.update({
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "release_sha": live_sha, "published_release_sha": served_sha,
        "cache_timestamp": cache_doc.get("timestamp"),
        "cache_sha256": hashlib.sha256(raw_cache).hexdigest(),
        "source": "official_olist_tiny_erp_v3", "read_only": True,
    })
    destination = Path(args.output)
    destination.parent.mkdir(parents=True, exist_ok=True)
    temp = destination.with_suffix(destination.suffix + ".tmp")
    temp.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    temp.replace(destination)
    print("PRICE_PARITY_VERDICT=" + result["status"])
    print("PRICE_PARITY_COUNTS=" + json.dumps(result["counts"], sort_keys=True))
    print("PRICE_PARITY_REPORT=" + str(destination))
    for finding in result["issues"][:12]:
        print("PRICE_PARITY_ISSUE=" + json.dumps(finding, ensure_ascii=False))
    return 0 if result["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())
