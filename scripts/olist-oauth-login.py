#!/usr/bin/env python3
"""Wrapper legado; implementação canônica em scripts/marketplace/olist/olist-oauth-login.py."""
from pathlib import Path
import runpy
_TARGET = Path(__file__).resolve().parent / "marketplace" / "olist" / "olist-oauth-login.py"
if __name__ == "__main__": runpy.run_path(str(_TARGET), run_name="__main__")
else: globals().update(runpy.run_path(str(_TARGET), run_name=__name__))
