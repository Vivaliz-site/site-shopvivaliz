#!/usr/bin/env python3
"""Wrapper legado para scripts/marketplace/olist/legacy/tiny_sales_report.py."""
from pathlib import Path
_TARGET = (Path(__file__).resolve().parent / "marketplace" / "olist" / "legacy" / "tiny_sales_report.py").resolve()
_LEGACY_FILE = str(Path(__file__).resolve())
_GLOBALS = globals()
_GLOBALS["__file__"] = _LEGACY_FILE
exec(compile(_TARGET.read_text(encoding="utf-8"), _LEGACY_FILE, "exec"), _GLOBALS)
