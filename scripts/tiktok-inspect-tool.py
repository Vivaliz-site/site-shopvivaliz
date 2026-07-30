#!/usr/bin/env python3
"""Wrapper legado gerado pela reorganização estrutural."""
from pathlib import Path
_TARGET = (Path(__file__).resolve().parent / 'marketplace/tiktok/legacy/tiktok-inspect-tool.py').resolve()
_LEGACY_FILE = str(Path(__file__).resolve())
_GLOBALS = globals()
_GLOBALS['__file__'] = _LEGACY_FILE
exec(compile(_TARGET.read_text(encoding='utf-8'), _LEGACY_FILE, 'exec'), _GLOBALS)
