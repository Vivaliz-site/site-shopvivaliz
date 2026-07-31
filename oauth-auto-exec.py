#!/usr/bin/env python3
"""Compatibility wrapper for the canonical blocked Olist OAuth entrypoint."""
from __future__ import annotations

import runpy
from pathlib import Path

TARGET = Path(__file__).resolve().parent / "scripts" / "marketplace" / "olist" / "oauth_login.py"
runpy.run_path(str(TARGET), run_name="__main__")
