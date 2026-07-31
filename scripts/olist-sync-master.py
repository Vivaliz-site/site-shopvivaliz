#!/usr/bin/env python3
"""Compatibility wrapper for the canonical Olist sync safety entrypoint."""
from __future__ import annotations

import runpy
from pathlib import Path

TARGET = Path(__file__).resolve().parent / "marketplace" / "olist" / "sync_master.py"
runpy.run_path(str(TARGET), run_name="__main__")
