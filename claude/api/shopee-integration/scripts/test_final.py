#!/usr/bin/env python3
"""Compatibility wrapper for the retired Shopee credential tool."""
from __future__ import annotations

import runpy
from pathlib import Path

for base in Path(__file__).resolve().parents:
    target = base / "scripts" / "marketplace" / "shopee" / "retired_credential_tool.py"
    if target.is_file():
        runpy.run_path(str(target), run_name="__main__")
        break
else:
    raise SystemExit("canonical retired Shopee credential tool not found")
