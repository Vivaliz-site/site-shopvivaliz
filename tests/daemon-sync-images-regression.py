#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "daemon-sync-products.py"
SPEC = importlib.util.spec_from_file_location("daemon_sync_products", MODULE_PATH)
if SPEC is None or SPEC.loader is None:
    raise SystemExit("cannot load daemon-sync-products.py")
daemon = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(daemon)

product = daemon.public_product({
    "id": 10,
    "sku": "SKU-10",
    "situacao": "A",
    "estoque": {"quantidade": 7},
    "anexos": [
        {"url": "https://s3.amazonaws.com/tiny-anexos-us/erp/example-1.jpg", "id": "internal-1"},
        {"url": "https://s3.amazonaws.com/tiny-anexos-us/erp/example-2.jpg"},
    ],
})

expected = [
    {"url": "https://s3.amazonaws.com/tiny-anexos-us/erp/example-1.jpg"},
    {"url": "https://s3.amazonaws.com/tiny-anexos-us/erp/example-2.jpg"},
]
if product.get("anexos") != expected:
    raise SystemExit(f"catalog daemon dropped ERP images: {product.get('anexos')!r}")

print("OK catalog daemon preserves sanitized ERP image attachments")
