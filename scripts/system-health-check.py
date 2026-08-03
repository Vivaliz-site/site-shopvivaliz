#!/usr/bin/env python3
"""Compatibility wrapper for the canonical system health check."""
from __future__ import annotations

import runpy
from pathlib import Path

TARGET = Path(__file__).resolve().parent / "maintenance" / "system_health_check.py"
runpy.run_path(str(TARGET), run_name="__main__")
