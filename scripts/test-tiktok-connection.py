#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Testa a conexao real com a TikTok Shop API usando os secrets salvos."""
import os, sys, subprocess, json
from pathlib import Path

def get_secret_value(name):
    # gh nao retorna valores de secret por seguranca; pedir ao usuario
    # rodar isso DENTRO do GitHub Actions ou usar .env.local
    return os.environ.get(name)

sys.path.insert(0, str(Path(__file__).parent))
from utils.tiktok_client import TikTokClient

required = ["TIKTOK_APP_KEY", "TIKTOK_APP_SECRET", "TIKTOK_ACCESS_TOKEN", "TIKTOK_SHOP_CIPHER"]
missing = [v for v in required if not os.environ.get(v)]
if missing:
    print("ERRO: variaveis ausentes no ambiente local: " + ", ".join(missing))
    print("Esse teste precisa rodar com as envs setadas localmente,")
    print("ou via GitHub Actions (que ja tem os secrets).")
    sys.exit(1)

client = TikTokClient()
print("Testando conexao com TikTok Shop API...")
try:
    count = 0
    for product in client.iter_all_products(page_size=10):
        count += 1
        print(f"  Produto: {product.get('product_id')} - {product.get('product_name', '')[:50]}")
        if count >= 5:
            break
    print(f"\nSUCESSO! Conexao funcionando. {count} produto(s) listado(s).")
except Exception as e:
    print(f"\nERRO na chamada API: {e}")
    sys.exit(1)
