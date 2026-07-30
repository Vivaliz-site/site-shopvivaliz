#!/usr/bin/env python3
"""Wrapper legado protegido para gerador histórico de reparo de imagens Olist."""
from __future__ import annotations

import os
import runpy
from pathlib import Path

if os.getenv("ALLOW_LEGACY_OLIST_IMAGE_REPAIR") != "YES":
    raise SystemExit(
        "Execução bloqueada: o script histórico gera placeholders e modifica cache local. "
        "Revise backup, dry-run e read-back; depois defina ALLOW_LEGACY_OLIST_IMAGE_REPAIR=YES."
    )

_TARGET = Path(__file__).resolve().parent / "legacy" / "repair-olist-images.py"
runpy.run_path(str(_TARGET), run_name="__main__")
