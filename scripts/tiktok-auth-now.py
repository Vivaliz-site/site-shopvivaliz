#!/usr/bin/env python3
"""
Wrapper seguro para autorizar TikTok Shop.

Use:
    python scripts/tiktok-auth-now.py

As credenciais devem ficar em variaveis de ambiente ou .env.local.
Este arquivo existe apenas por compatibilidade com comandos antigos.
"""
import runpy
from pathlib import Path


if __name__ == "__main__":
    runpy.run_path(str(Path(__file__).with_name("tiktok-get-token.py")), run_name="__main__")
