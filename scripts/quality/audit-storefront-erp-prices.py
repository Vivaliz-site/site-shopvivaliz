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
import time
import urllib.request
from collections import defaultdict
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path
from typing import Any

ROOT = Path(os.environ.get("SHOPVIVALIZ_PRICE_AUDIT_ROOT", "") or Path(__file__).resolve().parents[2])
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


def amazon_prices_for(
    product_id: str, detail: dict[str, Any],
    table: tuple[Decimal, dict[str, tuple[Decimal, Decimal]]],
) -> tuple[int | None, int | None]:
    """Recompute Amazon table 991 from raw ERP cadastro, never from local cache.

    Fixed ERP exceptions apply to exact product IDs; otherwise multiply the
    original ERP detail regular and promo prices by (100+percent)/100.
    Decimal + ROUND_HALF_UP matches the site's atomic reconciler.
    """
    base_regular, base_promo = extract_prices(detail)
    if base_regular is None or base_promo is None:
        return None, None
    percent, exceptions = table
    if product_id in exceptions:
        regular, promo = exceptions[product_id]
        return cents(regular), cents(promo)
    factor = Decimal("1") + percent / Decimal("100")
    regular = (Decimal(base_regular) * factor).quantize(Decimal("1"), rounding=ROUND_HALF_UP)
    promotional = (Decimal(base_promo) * factor).quantize(Decimal("1"), rounding=ROUND_HALF_UP)
    return int(regular), int(promotional)


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
    amazon_table: tuple[Decimal, dict[str, tuple[Decimal, Decimal]]],
) -> dict[str, Any]:
    active = {str(x.get("id")): x for x in active_rows if x.get("id") is not None}
    cached = {str(x.get("id")): x for x in cache_rows if x.get("id") is not None}
    issues: list[dict[str, Any]] = []
    public: dict[str, dict[str, Any]] = {}
    for row in storefront_rows:
        if not isinstance(row, dict):
            issues.append({"code": "public_catalog_row_invalid"})
            continue
        raw_id = row.get("olist_product_id") or row.get("id")
        if raw_id is None or not str(raw_id).strip():
            issues.append({"code": "published_product_missing_product_id",
                           "sku": str(row.get("sku") or "")})
            continue
        pid = str(raw_id).strip()
        if pid in public:
            issues.append({"code": "duplicate_storefront_product_id", "id": pid,
                           "sku": str(row.get("sku") or "")})
            continue
        public[pid] = row
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
        erp_base, erp_base_promo = extract_prices(detail)
        regular, promotional = amazon_prices_for(pid, detail, amazon_table)
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
                    "cache_regular_cents": cached_regular, "cache_promo_cents": cached_promo or 0,
                    "commercial_source": "olist_tiny_amazon_991"})
            local_base = item.get("_erp_base_prices")
            if not isinstance(local_base, dict):
                issues.append({"code": "erp_original_price_missing_from_cache", "id": pid, "sku": sku})
            else:
                local_regular, local_promo = extract_prices({"precos": local_base})
                if (local_regular, local_promo or 0) != (erp_base, erp_base_promo or 0):
                    issues.append({"code": "erp_original_price_stale_in_cache", "id": pid, "sku": sku})
            if item.get("_price_source") != "olist_tiny_amazon" or item.get("_price_list_id") != 991:
                issues.append({"code": "amazon_price_provenance_missing", "id": pid, "sku": sku})
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
            published_sku = str(page.get("sku") or page.get("codigo") or "").strip()
            if not published_sku or (sku and published_sku.casefold() != sku.casefold()):
                issues.append({"code": "storefront_sku_differs_from_erp", "id": pid,
                               "erp_sku": sku, "published_sku": published_sku})
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
        "price_list_id": 991,
        "published_prices_matching_erp": verified_published,
        "excluded_or_duplicate_not_published": len(excluded),
        "duplicate_erp_sku_groups": sum(x["code"] == "duplicate_erp_sku" for x in issues),
        "duplicate_erp_sku_price_conflicts": sum(
            x["code"] == "duplicate_erp_sku" and x["conflicting_prices"] for x in issues),
        "errors": len(issues),
    }
    return {"status": "PASS" if not issues and active else "FAIL",
            "counts": counts, "issues": issues, "unpublished_exclusions": excluded}


def cache_price_fingerprint(document: dict[str, Any]) -> str:
    """Hash every cache field used for price/SKU/provenance validation.

    The stock reconciler rewrites this cache every five minutes with new stock
    and refresh timestamps even if its prices do not change. A full ERP audit
    takes longer than that cycle: a raw-file hash would always report a false
    price-race. Exclude ONLY stock, presentation, and volatile time fields.
    """
    if not isinstance(document, dict) or not isinstance(document.get("itens"), list):
        raise ValueError("cache_price_snapshot_invalid")
    projected: list[dict[str, Any]] = []
    for item in document["itens"]:
        if not isinstance(item, dict):
            raise ValueError("cache_price_snapshot_row_invalid")
        projected.append({key: item.get(key) for key in (
            "id", "sku", "codigo", "situacao", "tipo", "precos", "preco",
            "_erp_base_prices", "_price_list_id", "_price_source",
        )})
    projected.sort(key=lambda item: (
        str(item.get("id")), str(item.get("sku")),
        json.dumps(item, ensure_ascii=False, sort_keys=True, allow_nan=False),
    ))
    fields = {
        "total": document.get("total"),
        "price_list_id": document.get("price_list_id"),
        "price_source": document.get("price_source"),
        "itens": projected,
    }
    canonical = json.dumps(fields, ensure_ascii=False, sort_keys=True,
                           separators=(",", ":"), allow_nan=False).encode("utf-8")
    return hashlib.sha256(canonical).hexdigest()


def storefront_price_fingerprint(rows: list[dict[str, Any]]) -> str:
    """Detect SKU/price/product-set changes on the published live storefront."""
    if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
        raise ValueError("public_price_snapshot_invalid")
    relevant = [
        {key: row.get(key) for key in ("olist_product_id", "id", "sku", "codigo", "price")}
        for row in rows
    ]
    relevant.sort(key=lambda row: (
        str(row.get("olist_product_id") or row.get("id")), str(row.get("sku")),
        json.dumps(row, ensure_ascii=False, sort_keys=True, allow_nan=False),
    ))
    return hashlib.sha256(json.dumps(relevant, ensure_ascii=False,
        sort_keys=True, separators=(",", ":"), allow_nan=False).encode("utf-8")).hexdigest()


def fetch_details_with_targeted_retry(api: Any, token: str, active: list[dict[str, Any]],
                                      *, sleep: Any = time.sleep) -> tuple[dict[str, dict[str, Any]], list[dict[str, str]]]:
    """Retry only failed ERP details, never certify an incomplete response."""
    details: dict[str, dict[str, Any]] = {}
    pending: dict[str, str] = {}
    for index, product in enumerate(active, 1):
        pid = str(product.get("id") or "")
        if not pid.isdecimal():
            pending[pid] = "invalid_id"
            continue
        try:
            result = api.api_get("produtos/" + pid, token, timeout=14, attempts=3)
            detail = result.get("produto") if isinstance(result.get("produto"), dict) else result.get("data") if isinstance(result.get("data"), dict) else result
            if isinstance(detail.get("produto"), dict):
                detail = detail["produto"]
            if not isinstance(detail, dict) or not detail:
                raise RuntimeError("erp_detail_invalid")
            details[pid] = detail
        except Exception as exc:
            pending[pid] = type(exc).__name__
        if index % 25 == 0 or index == len(active):
            print(f"ERP_DETAILS_CHECKED={index}/{len(active)} API_ERRORS={len(pending)}", flush=True)
    # The ERP may throttle a long sequential scan. Two bounded second-pass
    # retries of ONLY the missing IDs avoid 200+ redundant API calls.
    for attempt, delay in enumerate((4, 12), 1):
        if not pending:
            break
        sleep(delay)
        for pid in list(pending):
            if not pid.isdecimal():
                continue
            try:
                result = api.api_get("produtos/" + pid, token, timeout=20, attempts=3)
                detail = result.get("produto") if isinstance(result.get("produto"), dict) else result.get("data") if isinstance(result.get("data"), dict) else result
                if isinstance(detail.get("produto"), dict):
                    detail = detail["produto"]
                if not isinstance(detail, dict) or not detail:
                    raise RuntimeError("erp_detail_invalid")
                details[pid] = detail
                del pending[pid]
            except Exception as exc:
                pending[pid] = type(exc).__name__
        print(f"ERP_DETAIL_RECOVERY_PASS={attempt} REMAINING={len(pending)}", flush=True)
    errors = [{"code": "erp_detail_query_failed" if pid.isdecimal() else "erp_product_id_invalid",
               "id": pid, "reason": reason} for pid, reason in pending.items()]
    return details, errors


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
    if (cache_doc.get("price_list_id") != 991
        or cache_doc.get("price_source") != "olist_tiny_amazon"):
        raise RuntimeError("active_cache_not_derived_from_amazon_991")
    initial_cache_price_sha = cache_price_fingerprint(cache_doc)
    site_rows, served_sha = public_products()
    initial_public_price_sha = storefront_price_fingerprint(site_rows)
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
    amazon_table = api.fetch_amazon_table(token)
    active = api.fetch_products_active(token)
    if not active:
        raise RuntimeError("erp_active_list_empty")
    details, errors = fetch_details_with_targeted_retry(api, token, active)
    result = evaluate(active, details, cache_rows, site_rows, amazon_table)
    # A deploy that moves under this lengthy full-catalog audit invalidates its
    # snapshot; do not certify an earlier release as the current storefront.
    current_live_sha = (ROOT / ".release-sha").read_text().strip()
    current_public_sha = ""
    current_public_price_sha = None
    try:
        current_public_rows, current_public_sha = public_products()
        current_public_price_sha = storefront_price_fingerprint(current_public_rows)
    except Exception:
        result["issues"].append({"code": "public_catalog_unavailable_after_price_audit"})
    if current_live_sha != live_sha or current_public_sha != served_sha:
        result["issues"].append({"code": "release_changed_during_price_audit",
            "start_sha": live_sha, "end_local_sha": current_live_sha,
            "end_public_sha": current_public_sha})
    if current_public_price_sha != initial_public_price_sha:
        result["issues"].append({"code": "storefront_changed_during_price_audit"})
    current_cache_price_sha = None
    latest_raw_cache_sha = None
    try:
        latest_cache_bytes = cache_path.read_bytes()
        latest_raw_cache_sha = hashlib.sha256(latest_cache_bytes).hexdigest()
        current_cache_price_sha = cache_price_fingerprint(json.loads(latest_cache_bytes))
    except (OSError, ValueError, TypeError):
        result["issues"].append({"code": "cache_unreadable_after_price_audit"})
    if current_cache_price_sha != initial_cache_price_sha:
        result["issues"].append({"code": "cache_changed_during_price_audit",
            "scope": "price_sku_provenance"})
    # Do not block legitimate stock/timestamp refreshes when all fields
    # relevant to price parity are identical. Never ignore actual price drift.
    result["cache_price_sha256_start"] = initial_cache_price_sha
    result["cache_price_sha256_end"] = current_cache_price_sha
    result["public_price_sha256_start"] = initial_public_price_sha
    result["public_price_sha256_end"] = current_public_price_sha
    result["cache_volatile_only_refresh"] = bool(
        latest_raw_cache_sha is not None
        and latest_raw_cache_sha != hashlib.sha256(raw_cache).hexdigest()
        and current_cache_price_sha == initial_cache_price_sha
    )
    if result["issues"]:
        result["counts"]["errors"] = len(result["issues"])
        result["status"] = "FAIL"
    if errors:
        result["issues"].extend(errors)
        result["counts"]["errors"] = len(result["issues"])
        result["status"] = "FAIL"
    result.update({
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "release_sha": live_sha, "published_release_sha": served_sha,
        "cache_timestamp": cache_doc.get("timestamp"),
        "cache_sha256": hashlib.sha256(raw_cache).hexdigest(),
        "source": "official_olist_tiny_amazon_991", "read_only": True,
        "price_list_id": 991,
        "amazon_percent": str(amazon_table[0]),
        "amazon_exception_count": len(amazon_table[1]),
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
