#!/usr/bin/env python3
from pathlib import Path
import runpy
_TARGET = Path(__file__).resolve().parent / "scripts" / "dev" / "legacy-reporting" / "relatorio_final_com_6_precos.py"
if __name__ == "__main__": runpy.run_path(str(_TARGET), run_name="__main__")
else: globals().update(runpy.run_path(str(_TARGET), run_name=__name__))
