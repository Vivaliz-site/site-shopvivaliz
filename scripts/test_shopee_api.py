#!/usr/bin/env python3
"""Compatibility wrapper for the retired Shopee credential tool."""
from __future__ import annotations

import runpy
from pathlib import Path

TARGET = Path(__file__).resolve().parent / "marketplace" / "shopee" / "retired_credential_tool.py"
runpy.run_path(str(TARGET), run_name="__main__")
