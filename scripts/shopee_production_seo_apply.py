#!/usr/bin/env python3
"""Apply SEO title/description updates to the real Shopee catalog.

This command has no simulation mode. It requires an explicit confirmation phrase,
backs up every listing, verifies every mutation by reading the listing back, and
returns a non-zero exit code when any requested update is not proven successful.
Price and stock are invariants and are never sent in the update payload.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
from copy import deepcopy
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from shopee_full_catalog_optimizer import (  # noqa: E402
    build_description,
    build_title,
    image_ids,
    smart_validate,
)
from utils.shopee_client import ShopeeClient  # noqa: E402

CONFIRMATION = "APPLY_ALL_SHOPEE_PRODUCTS"
BACKUP_DIR = Path(os.environ.get("SHOPEE_PRODUCTION_BACKUP_DIR") or (ROOT / "storage" / "private" / "shopee-production-backups"))
REPORT_DIR = Path(os.environ.get("SHOPEE_PRODUCTION_REPORT_DIR") or (ROOT / "logs" / "shopee-production-seo"))


def verify_exact(before: dict[str, Any], expected: dict[str, Any], actual: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if actual.get("item_name") != expected.get("item_name"):
        errors.append("title_not_persisted")
    if actual.get("description") != expected.get("description"):
        errors.append("description_not_persisted")
    if image_ids(actual) != image_ids(expected):
        errors.append("images_changed_or_not_persisted")
    if actual.get("price_info") != before.get("price_info"):
        errors.append("price_invariant_violated")
    if actual.get("stock_info_v2") != before.get("stock_info_v2"):
        errors.append("stock_invariant_violated")
    return errors


def rollback_and_verify(client: ShopeeClient, before: dict[str, Any]) -> None:
    item_id = int(before["item_id"])
    client.update_product(
        item_id,
        title=str(before.get("item_name") or ""),
        description=str(before.get("description") or ""),
        image_ids=image_ids(before),
    )
    restored_items = client.get_product_details([item_id])
    if len(restored_items) != 1:
        raise RuntimeError("rollback_readback_missing")
    restored = restored_items[0]
    errors = verify_exact(before, before, restored)
    if errors:
        raise RuntimeError("rollback_verification_failed:" + ",".join(errors))


def apply_one(client: ShopeeClient, before: dict[str, Any]) -> dict[str, Any]:
    item_id = int(before["item_id"])
    title = build_title(before)
    description = build_description(before, title)
    expected = deepcopy(before)
    expected["item_name"] = title
    expected["description"] = description

    validation_errors = smart_validate(before, expected)
    if validation_errors:
        return {
            "item_id": item_id,
            "status": "blocked",
            "evidence": {"validation_errors": validation_errors},
        }

    changed_fields = []
    if title != before.get("item_name"):
        changed_fields.append("item_name")
    if description != before.get("description"):
        changed_fields.append("description")
    if not changed_fields:
        return {
            "item_id": item_id,
            "status": "verified_unchanged",
            "evidence": {"read_before": True, "changed_fields": []},
        }

    try:
        response = client.update_product(
            item_id,
            title=title,
            description=description,
            image_ids=image_ids(before),
        )
        readback_items = client.get_product_details([item_id])
        if len(readback_items) != 1:
            raise RuntimeError("post_update_readback_missing")
        readback = readback_items[0]
        verification_errors = verify_exact(before, expected, readback)
        if verification_errors:
            raise RuntimeError("verification_failed:" + ",".join(verification_errors))
        return {
            "item_id": item_id,
            "status": "updated_verified",
            "evidence": {
                "api_response": response,
                "read_after": True,
                "changed_fields": changed_fields,
                "title_before": before.get("item_name"),
                "title_after": readback.get("item_name"),
            },
        }
    except Exception as update_error:
        try:
            rollback_and_verify(client, before)
            rollback = "verified"
        except Exception as rollback_error:
            raise RuntimeError(
                f"item={item_id} update_failed={update_error}; rollback_failed={rollback_error}"
            ) from update_error
        raise RuntimeError(
            f"item={item_id} update_failed={update_error}; rollback={rollback}"
        ) from update_error


def main() -> int:
    parser = argparse.ArgumentParser(description="Apply real Shopee SEO updates with read-back evidence")
    parser.add_argument("--confirm", required=True)
    parser.add_argument("--limit", type=int, default=0)
    args = parser.parse_args()

    if args.confirm != CONFIRMATION:
        print("ERROR: confirmation phrase does not authorize production mutations", file=sys.stderr)
        return 2
    if args.limit < 0:
        print("ERROR: --limit cannot be negative", file=sys.stderr)
        return 2

    client = ShopeeClient()
    listed = list(client.iter_all_products())
    if args.limit:
        listed = listed[: args.limit]
    item_ids = [int(item["item_id"]) for item in listed]
    if not item_ids:
        print("ERROR: Shopee returned no active products; no success can be claimed", file=sys.stderr)
        return 3

    details = client.get_product_details(item_ids)
    if len(details) != len(item_ids):
        print(
            f"ERROR: expected {len(item_ids)} product details but received {len(details)}",
            file=sys.stderr,
        )
        return 3

    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    backup_path = BACKUP_DIR / f"catalog-before-{stamp}.json"
    report_path = REPORT_DIR / f"production-run-{stamp}.json"
    backup_path.write_text(json.dumps(details, ensure_ascii=False, indent=2), encoding="utf-8")

    report: dict[str, Any] = {
        "mode": "production_apply",
        "started_at": stamp,
        "requested": len(details),
        "backup": str(backup_path),
        "items": [],
    }
    fatal_failures = 0
    for index, before in enumerate(details, start=1):
        try:
            entry = apply_one(client, before)
        except Exception as exc:
            fatal_failures += 1
            entry = {
                "item_id": before.get("item_id"),
                "status": "failed",
                "evidence": {"error": str(exc)},
            }
        report["items"].append(entry)
        report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"[{index}/{len(details)}] item={entry['item_id']} status={entry['status']}")
        time.sleep(0.4)

    counts: dict[str, int] = {}
    for item in report["items"]:
        status = str(item["status"])
        counts[status] = counts.get(status, 0) + 1
    report["counts"] = counts
    report["finished_at"] = datetime.now(timezone.utc).isoformat()
    report["success"] = fatal_failures == 0 and counts.get("blocked", 0) == 0
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(json.dumps({"report": str(report_path), "counts": counts, "success": report["success"]}, ensure_ascii=False))
    if fatal_failures:
        return 1
    if counts.get("blocked", 0):
        return 4
    if counts.get("updated_verified", 0) + counts.get("verified_unchanged", 0) != len(details):
        return 5
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
