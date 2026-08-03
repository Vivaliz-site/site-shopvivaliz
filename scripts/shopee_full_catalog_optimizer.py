#!/usr/bin/env python3
"""Otimiza todo o catalogo Shopee sem alterar preco ou estoque.

Uso:
  python scripts/shopee_full_catalog_optimizer.py --dry-run
  python scripts/shopee_full_catalog_optimizer.py --apply

Requer os secrets ja usados pelo ShopeeClient e OPENAI_API_KEY para imagens.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import time
from copy import deepcopy
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from utils.shopee_client import ShopeeClient  # noqa: E402
from ia.images.image_generator import generate_for_product  # noqa: E402
from ia.images.image_validator import validate_batch  # noqa: E402

BACKUP_DIR = ROOT / "storage" / "private" / "shopee-optimizer-backups"
REPORT_DIR = ROOT / "logs" / "shopee-optimizer"


def clean_text(value: str) -> str:
    value = re.sub(r"\s+", " ", (value or "").strip())
    return value


def build_title(item: dict[str, Any]) -> str:
    original = clean_text(item.get("item_name") or "")
    attrs = item.get("attribute_list") or []
    useful: list[str] = []
    for attr in attrs:
        name = clean_text(str(attr.get("attribute_name") or ""))
        values = attr.get("attribute_value_list") or []
        if name.lower() in {"marca", "modelo", "material", "tamanho", "cor"} and values:
            val = clean_text(str(values[0].get("value_unit") or values[0].get("original_value_name") or ""))
            if val and val.lower() not in original.lower():
                useful.append(val)
    title = clean_text(" ".join([original, *useful[:3]]))
    title = re.sub(r"[^\w\s\-./]", "", title, flags=re.UNICODE)
    return title[:120].strip() or original[:120]


def build_description(item: dict[str, Any], title: str) -> str:
    original = clean_text(item.get("description") or "")
    attrs = item.get("attribute_list") or []
    specs: list[str] = []
    for attr in attrs:
        name = clean_text(str(attr.get("attribute_name") or ""))
        values = attr.get("attribute_value_list") or []
        vals = [clean_text(str(v.get("value_unit") or v.get("original_value_name") or "")) for v in values]
        vals = [v for v in vals if v]
        if name and vals:
            specs.append(f"- {name}: {', '.join(vals)}")
    sections = [title]
    if original:
        sections += ["", original]
    if specs:
        sections += ["", "Especificacoes do produto:", *specs[:30]]
    sections += ["", "Confira medidas, compatibilidade e variacoes antes da compra."]
    return "\n".join(sections)[:3000]


def image_ids(item: dict[str, Any]) -> list[str]:
    image = item.get("image") or {}
    return [str(x) for x in (image.get("image_id_list") or []) if x]


def smart_validate(before: dict[str, Any], after: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if before.get("price_info") != after.get("price_info"):
        errors.append("price_changed")
    if before.get("stock_info_v2") != after.get("stock_info_v2"):
        errors.append("stock_changed")
    title = after.get("item_name") or ""
    if not 10 <= len(title) <= 120:
        errors.append("invalid_title_length")
    desc = after.get("description") or ""
    if len(desc) < 40:
        errors.append("description_too_short")
    return errors


def process_images(client: ShopeeClient, item: dict[str, Any]) -> tuple[list[str], dict[str, Any]]:
    """Generate, validate and upload images. This function is apply-only."""
    current = image_ids(item)
    generated = generate_for_product(item)
    validation = validate_batch(generated)
    uploaded: list[str] = []
    for kind, path in generated.items():
        ok, reason = validation[kind]
        if ok and path:
            uploaded.append(client.upload_image(str(path)))
    merged = (current[:1] + uploaded + current[1:])[:9]
    return merged or current, {
        "generated": {k: str(v) if v else None for k, v in generated.items()},
        "validation": {k: {"ok": ok, "reason": reason} for k, (ok, reason) in validation.items()},
        "uploaded": uploaded,
    }


def restore_product(client: ShopeeClient, before: dict[str, Any]) -> None:
    client.update_product(
        int(before["item_id"]),
        title=str(before.get("item_name") or ""),
        description=str(before.get("description") or ""),
        image_ids=image_ids(before),
    )


def apply_product_update(
    client: ShopeeClient,
    before: dict[str, Any],
    *,
    title: str,
    description: str,
    images: list[str],
) -> None:
    """Apply one update and automatically restore the original listing on failure."""
    item_id = int(before["item_id"])
    try:
        client.update_product(item_id, title=title, description=description, image_ids=images)
        verified = client.get_product_details([item_id])[0]
        if verified.get("price_info") != before.get("price_info"):
            raise RuntimeError("price invariant violated")
        if verified.get("stock_info_v2") != before.get("stock_info_v2"):
            raise RuntimeError("stock invariant violated")
    except Exception as update_error:
        try:
            restore_product(client, before)
            restored = client.get_product_details([item_id])[0]
            if (
                restored.get("item_name") != before.get("item_name")
                or restored.get("description") != before.get("description")
                or image_ids(restored) != image_ids(before)
            ):
                raise RuntimeError("rollback verification failed")
        except Exception as rollback_error:
            raise RuntimeError(
                f"Update failed for {item_id}; rollback also failed: {rollback_error}"
            ) from update_error
        raise RuntimeError(f"Update failed for {item_id}; rollback completed") from update_error


def main() -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--apply", action="store_true")
    mode.add_argument("--dry-run", action="store_true")
    parser.add_argument("--skip-images", action="store_true")
    parser.add_argument("--limit", type=int, default=0)
    args = parser.parse_args()
    apply = bool(args.apply)

    client = ShopeeClient()
    listed = list(client.iter_all_products())
    if args.limit:
        listed = listed[: args.limit]
    ids = [int(x["item_id"]) for x in listed]
    details = client.get_product_details(ids)

    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    backup_path = BACKUP_DIR / f"catalog-{stamp}.json"
    report_path = REPORT_DIR / f"run-{stamp}.json"
    backup_path.write_text(json.dumps(details, ensure_ascii=False, indent=2), encoding="utf-8")

    report: dict[str, Any] = {"mode": "apply" if apply else "dry-run", "total": len(details), "items": []}
    for index, item in enumerate(details, start=1):
        before = deepcopy(item)
        title = build_title(item)
        description = build_description(item, title)
        images = image_ids(item)
        image_report: dict[str, Any] = {
            "skipped": True,
            "reason": "disabled_by_flag" if args.skip_images else "dry_run_no_external_mutation",
        }
        if apply and not args.skip_images:
            images, image_report = process_images(client, item)
        candidate = deepcopy(item)
        candidate["item_name"] = title
        candidate["description"] = description
        candidate.setdefault("image", {})["image_id_list"] = images
        validation_errors = smart_validate(before, candidate)
        entry = {
            "item_id": item.get("item_id"),
            "before_title": before.get("item_name"),
            "after_title": title,
            "validation_errors": validation_errors,
            "images": image_report,
            "updated": False,
        }
        if apply and not validation_errors:
            apply_product_update(
                client,
                before,
                title=title,
                description=description,
                images=images,
            )
            entry["updated"] = True
        report["items"].append(entry)
        report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"[{index}/{len(details)}] item={item.get('item_id')} updated={entry['updated']} errors={validation_errors}")
        time.sleep(0.4)

    report["updated"] = sum(1 for x in report["items"] if x["updated"])
    report["blocked"] = sum(1 for x in report["items"] if x["validation_errors"])
    report["backup"] = str(backup_path)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"report": str(report_path), "backup": str(backup_path), "updated": report["updated"]}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
