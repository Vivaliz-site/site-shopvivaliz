#!/usr/bin/env python3
"""Publica imagens reais na Shopee e TikTok Shop, sem modo simulado."""
from __future__ import annotations

import csv
import json
import logging
import os
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

import requests

try:
    from dotenv import load_dotenv

    load_dotenv()
except ImportError:
    pass

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

ROOT = Path(__file__).resolve().parent.parent
UPLOAD_MAPPING_FILE = ROOT / "storage" / "uploaded_urls.csv"
EXECUTION_LOG = ROOT / "logs" / "marketplace_upload_execution.json"
sys.path.insert(0, str(Path(__file__).resolve().parent / "utils"))


def image_urls(row: dict[str, str]) -> list[str]:
    return [
        value.strip()
        for value in (row.get(f"image_url_{index}", "") for index in range(1, 10))
        if value and value.strip()
    ]


def download_images(urls: list[str]) -> list[Path]:
    files: list[Path] = []
    try:
        for url in urls:
            response = requests.get(url, timeout=40)
            response.raise_for_status()
            suffix = Path(url.split("?", 1)[0]).suffix.lower()
            if suffix not in {".jpg", ".jpeg", ".png", ".webp"}:
                suffix = ".jpg"
            handle = tempfile.NamedTemporaryFile(suffix=suffix, delete=False)
            try:
                handle.write(response.content)
                files.append(Path(handle.name))
            finally:
                handle.close()
        return files
    except Exception:
        for path in files:
            path.unlink(missing_ok=True)
        raise


def run_rows(callback: Callable[[str, list[str]], None]) -> tuple[int, int, int]:
    if not UPLOAD_MAPPING_FILE.is_file():
        raise RuntimeError(f"Mapeamento de imagens ausente: {UPLOAD_MAPPING_FILE}")
    succeeded = 0
    failed = 0
    skipped = 0
    with UPLOAD_MAPPING_FILE.open("r", encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            sku = str(row.get("sku") or "").strip()
            urls = image_urls(row)
            if not sku or not urls:
                skipped += 1
                continue
            try:
                callback(sku, urls)
                succeeded += 1
            except Exception as exc:
                logger.error("SKU %s falhou: %s", sku, exc)
                failed += 1
    return succeeded, failed, skipped


def upload_shopee() -> dict[str, int | bool]:
    from shopee_client import ShopeeClient

    client = ShopeeClient()
    item_ids = [int(item["item_id"]) for item in client.iter_all_products() if item.get("item_id")]
    details = client.get_product_details(item_ids)
    sku_to_id = {
        str(item.get("item_sku") or "").strip(): int(item["item_id"])
        for item in details
        if item.get("item_id") and str(item.get("item_sku") or "").strip()
    }

    def publish(sku: str, urls: list[str]) -> None:
        item_id = sku_to_id.get(sku)
        if not item_id:
            raise RuntimeError("produto não localizado na loja Shopee")
        files = download_images(urls[:9])
        try:
            image_ids = [client.upload_image(str(path)) for path in files]
            client.update_product(item_id, image_ids=image_ids)
            readback = client.get_product_details([item_id])
            if not readback:
                raise RuntimeError("read-back da Shopee não confirmou o produto")
        finally:
            for path in files:
                path.unlink(missing_ok=True)

    succeeded, failed, skipped = run_rows(publish)
    return {"configured": True, "success": failed == 0, "succeeded": succeeded, "failed": failed, "skipped": skipped}


def upload_tiktok() -> dict[str, int | bool]:
    from tiktok_client import TikTokClient

    client = TikTokClient()

    def publish(sku: str, urls: list[str]) -> None:
        product = client.find_product_by_sku(sku)
        product_id = str(product.get("id") or product.get("product_id") or "")
        if not product_id:
            raise RuntimeError("TikTok Shop não retornou product_id")
        files = download_images(urls[:9])
        try:
            client.update_product(product_id, image_files=files)
            readback = client.get_product(product_id)
            if not (readback.get("main_images") or []):
                raise RuntimeError("read-back do TikTok Shop não confirmou imagens")
        finally:
            for path in files:
                path.unlink(missing_ok=True)

    succeeded, failed, skipped = run_rows(publish)
    return {"configured": True, "success": failed == 0, "succeeded": succeeded, "failed": failed, "skipped": skipped}


def safe_run(name: str, callback: Callable[[], dict[str, int | bool]]) -> dict[str, int | bool | str]:
    logger.info("Iniciando publicação real em %s", name)
    try:
        result = callback()
        logger.info("%s: %s", name, result)
        return result
    except Exception as exc:
        logger.error("%s indisponível: %s", name, exc)
        return {"configured": False, "success": False, "succeeded": 0, "failed": 0, "skipped": 0, "error": str(exc)}


def main() -> int:
    report = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "shopee": safe_run("Shopee", upload_shopee),
        "tiktok": safe_run("TikTok Shop", upload_tiktok),
    }
    report["success"] = bool(report["shopee"].get("success")) and bool(report["tiktok"].get("success"))
    EXECUTION_LOG.parent.mkdir(parents=True, exist_ok=True)
    EXECUTION_LOG.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if report["success"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
