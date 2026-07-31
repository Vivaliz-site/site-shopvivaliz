#!/usr/bin/env python3
"""Compatibility wrapper for the canonical continuous executor block."""
from __future__ import annotations

import runpy
from pathlib import Path

TARGET = Path(__file__).resolve().parent / "ai" / "continuous_executor.py"
runpy.run_path(str(TARGET), run_name="__main__")
