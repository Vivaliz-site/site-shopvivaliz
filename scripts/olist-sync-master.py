#!/usr/bin/env python3
"""Wrapper legado para scripts/marketplace/olist/olist-sync-master.py."""
from pathlib import Path
import runpy

_TARGET = Path(__file__).resolve().parent / "marketplace" / "olist" / "olist-sync-master.py"

if __name__ == "__main__":
    runpy.run_path(str(_TARGET), run_name="__main__")
else:
    globals().update(runpy.run_path(str(_TARGET), run_name=__name__))
