#!/usr/bin/env python3
"""
Sincroniza tokens renovados de .env da VM para GitHub Secrets.
Deve ser chamado DEPOIS que daemon-token-renewer.py renova os tokens.
"""

import os
import subprocess
import sys
from pathlib import Path

ENV_FILE = Path("/home/ubuntu/site-shopvivaliz/.env")
TOKENS_TO_SYNC = ["OLIST_ACCESS_TOKEN", "OLIST_REFRESH_TOKEN"]

def read_env():
    """Lê .env e retorna dict com variáveis."""
    if not ENV_FILE.exists():
        print(f"❌ .env não encontrado: {ENV_FILE}")
        return {}

    config = {}
    for line in ENV_FILE.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        config[key.strip()] = value.strip()
    return config

def sync_to_github(key, value):
    """Salva secret em GitHub Secrets."""
    try:
        result = subprocess.run(
            ["gh", "secret", "set", key, "--body", value],
            cwd="/home/ubuntu/site-shopvivaliz",
            capture_output=True,
            text=True,
            timeout=30
        )
        if result.returncode == 0:
            print(f"✅ {key} sincronizado com GitHub")
            return True
        else:
            print(f"❌ Erro ao sincronizar {key}: {result.stderr}")
            return False
    except Exception as e:
        print(f"❌ Exceção ao sincronizar {key}: {e}")
        return False

def main():
    print("🔄 Sincronizando tokens de .env para GitHub Secrets...")
    config = read_env()

    if not config:
        print("❌ Nenhuma variável encontrada em .env")
        sys.exit(1)

    synced = 0
    for token_key in TOKENS_TO_SYNC:
        if token_key in config and config[token_key]:
            if sync_to_github(token_key, config[token_key]):
                synced += 1
        else:
            print(f"⚠️  {token_key} não encontrado em .env")

    print(f"\n✅ {synced}/{len(TOKENS_TO_SYNC)} tokens sincronizados com sucesso")
    sys.exit(0 if synced == len(TOKENS_TO_SYNC) else 1)

if __name__ == "__main__":
    main()
