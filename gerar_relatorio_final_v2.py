#!/usr/bin/env python3
from pathlib import Path
import runpy
_TARGET = Path(__file__).resolve().parent / "scripts" / "dev" / "legacy-reporting" / "gerar_relatorio_final_v2.py"
if __name__ == "__main__": runpy.run_path(str(_TARGET), run_name="__main__")
else: globals().update(runpy.run_path(str(_TARGET), run_name=__name__))
