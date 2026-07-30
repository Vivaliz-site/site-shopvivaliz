#!/usr/bin/env python3
"""Compatibility wrapper for the canonical Shopee production SEO executor.

Canonical implementation:
    scripts/marketplace/shopee/production_seo_apply.py

Do not add new logic here. This wrapper exists only to avoid breaking legacy
CLI calls while the repository is being reorganized.
"""
from __future__ import annotations

import runpy
from pathlib import Path

TARGET = Path(__file__).resolve().parent / "marketplace" / "shopee" / "production_seo_apply.py"

if __name__ == "__main__":
    runpy.run_path(str(TARGET), run_name="__main__")
