#!/usr/bin/env python3
"""Fail-closed PDV catalog cutover for a protected, immutable release.

Only the deploy runner may invoke --promote after required GitHub gates. The
candidate was created by daemon-sync-products.py --stage-cache using live Olist
v3; this helper never calls the ERP or modifies commercial ERP records.
"""
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import shutil
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

SHARED_ROOT = Path("/home/ubuntu/shopvivaliz-deploy/shared")
STAGE_ROOT = SHARED_ROOT / "deployment-pdv"
CACHE = SHARED_ROOT / "storage" / "products-cache-ativos.json"


def verify_snapshot(payload: Any) -> dict[str, Any]:
    if not isinstance(payload, dict):
        raise ValueError("pdv_snapshot_invalid")
    products = payload.get("itens")
    quarantine = payload.get("quarantined_skus")
    count = payload.get("source_active_count")
    if not isinstance(products, list) or len(products) < 20:
        raise ValueError("pdv_snapshot_catalog_too_small")
    if not isinstance(quarantine, list) or type(count) is not int:
        raise ValueError("pdv_snapshot_metadata_missing")
    if type(payload.get("total")) is not int or payload["total"] != len(products):
        raise ValueError("pdv_snapshot_total_mismatch")
    timestamp = payload.get("timestamp")
    if not isinstance(timestamp, str):
        raise ValueError("pdv_snapshot_timestamp_missing")
    checked = datetime.fromisoformat(timestamp.replace("Z", "+00:00"))
    age = (datetime.now(timezone.utc) - checked.astimezone(timezone.utc)).total_seconds()
    if age < -300 or age > 7200:
        raise ValueError("pdv_snapshot_stale")

    ids: set[str] = set()
    skus: set[str] = set()
    pdv_ids: set[int] = set()
    for row in products:
        if not isinstance(row, dict):
            raise ValueError("pdv_snapshot_row_invalid")
        product_id = str(row.get("id") or "").strip()
        sku = str(row.get("sku") or "").strip().casefold()
        if not product_id.isdigit() or not sku or product_id in ids or sku in skus:
            raise ValueError("pdv_snapshot_product_identity_invalid")
        ids.add(product_id)
        skus.add(sku)
        if row.get("price_source") != "olist_pdv":
            raise ValueError("pdv_snapshot_price_source_invalid")
        list_id = row.get("pdv_list_id")
        if type(list_id) is not int or list_id <= 0:
            raise ValueError("pdv_snapshot_list_id_invalid")
        pdv_ids.add(list_id)
        prices = row.get("precos")
        if not isinstance(prices, dict):
            raise ValueError("pdv_snapshot_prices_missing")
        try:
            price = float(prices.get("preco"))
            promo = float(prices.get("precoPromocional") or 0)
        except (TypeError, ValueError) as exc:
            raise ValueError("pdv_snapshot_price_invalid") from exc
        import math
        if not (math.isfinite(price) and math.isfinite(promo)) or price <= 0 or promo < 0 or (promo > 0 and promo >= price):
            raise ValueError("pdv_snapshot_price_invalid")
    if len(pdv_ids) != 1:
        raise ValueError("pdv_snapshot_list_identity_inconsistent")

    blocked_ids: set[str] = set()
    blocked_skus: set[str] = set()
    for entry in quarantine:
        if not isinstance(entry, dict) or entry.get("reason") not in {"duplicate_active_sku", "missing_erp_sku"}:
            raise ValueError("pdv_quarantine_entry_invalid")
        sku = str(entry.get("sku") or "").strip().casefold()
        raw_ids = entry.get("olist_product_ids")
        if not isinstance(raw_ids, list) or not raw_ids or any(not str(i).isdigit() for i in raw_ids):
            raise ValueError("pdv_quarantine_identity_invalid")
        if sku and (sku in blocked_skus or sku in skus):
            raise ValueError("pdv_quarantine_collision_not_isolated")
        if sku:
            blocked_skus.add(sku)
        for product_id in raw_ids:
            pid = str(product_id)
            if pid in ids or pid in blocked_ids:
                raise ValueError("pdv_quarantine_overlap")
            blocked_ids.add(pid)
    if len(products) + len(blocked_ids) != count or payload.get("quarantined_product_count") != len(blocked_ids):
        raise ValueError("pdv_snapshot_source_count_mismatch")
    return {
        "pdv_list_id": next(iter(pdv_ids)),
        "published_products": len(products),
        "quarantined_products": len(blocked_ids),
        "quarantined_skus": len(quarantine),
        "source_active_count": count,
    }


def stage_path_for(sha: str) -> Path:
    if len(sha) != 40 or any(char not in "0123456789abcdef" for char in sha):
        raise ValueError("expected_sha_invalid")
    candidate = STAGE_ROOT / f".pdv-stage-{sha}.json"
    if candidate.parent.resolve() != STAGE_ROOT.resolve():
        raise ValueError("pdv_stage_path_invalid")
    return candidate


def run(sha: str, promote: bool = False) -> dict[str, Any]:
    stage = stage_path_for(sha)
    raw = stage.read_bytes()
    summary = verify_snapshot(json.loads(raw))
    summary["candidate_sha256"] = hashlib.sha256(raw).hexdigest()
    summary["release_sha"] = sha
    if not promote:
        summary["status"] = "validated_not_published"
        return summary

    CACHE.parent.mkdir(parents=True, exist_ok=True)
    with (CACHE.parent / "olist-erp-webhook.lock").open("a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        # Recheck while holding the same writer lock as every PHP webhook.
        if stage.read_bytes() != raw:
            raise ValueError("pdv_stage_changed_during_validation")
        backup = STAGE_ROOT / f".pdv-backup-{sha}.json"
        if CACHE.is_file():
            existing = json.loads(CACHE.read_text(encoding="utf-8"))
            old_rows = existing if isinstance(existing, list) else existing.get("itens") if isinstance(existing, dict) else None
            if not isinstance(old_rows, list):
                raise ValueError("existing_catalog_unreadable")
            # First PDV release must not make most of the public catalog vanish.
            if old_rows and (summary["published_products"] < len(old_rows) * 0.9
                             or summary["quarantined_products"] > max(3, len(old_rows) * 0.05)):
                raise ValueError("pdv_cutover_catalog_drop_exceeds_threshold")
            shutil.copy2(CACHE, backup)
            backup.chmod(0o600)
        os.replace(stage, CACHE)
        CACHE.chmod(0o644)
        secondary = CACHE.parent / "cache" / "products-cache-ativos.json"
        secondary.unlink(missing_ok=True)
        derived = CACHE.parent / "cache" / "catalog-api"
        if derived.is_dir():
            for stale in derived.glob("*.json"):
                stale.unlink(missing_ok=True)
        fcntl.flock(lock.fileno(), fcntl.LOCK_UN)
    summary["status"] = "pdv_catalog_promoted_under_lock"
    return summary


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--expected-sha", required=True)
    parser.add_argument("--promote", action="store_true")
    args = parser.parse_args()
    try:
        result = run(args.expected_sha, args.promote)
        print(json.dumps(result, sort_keys=True))
        return 0
    except (OSError, TypeError, ValueError, json.JSONDecodeError) as exc:
        print(json.dumps({"ok": False, "error_type": type(exc).__name__, "status": "FAIL_CLOSED"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
