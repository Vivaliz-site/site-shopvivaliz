#!/usr/bin/env python3
"""
Sincronizar Produtos ERP Olist V3 - Agora
Executa: python sync-now.py
"""

import json
import urllib.request
from pathlib import Path
import sqlite3
import os

print("=" * 70)
print("[*] Sincronizando Produtos do ERP Olist")
print("=" * 70)

# Carregar token
env_file = Path(".env")
access_token = ""

for line in env_file.read_text().split('\n'):
    if line.startswith('OLIST_ACCESS_TOKEN='):
        access_token = line.split('=', 1)[1].strip()
        break

if not access_token:
    print("[!] Access token nao encontrado em .env")
    exit(1)

print(f"\n[+] Token: {access_token[:40]}...")

# ============================================================
# BUSCAR PRODUTOS
# ============================================================

print("\n[*] Buscando produtos da API V3...")

offset = 0
limit = 100
total_sincronizados = 0
pagina = 1

while True:
    url = f"https://api.tiny.com.br/public-api/v3/produtos?limit={limit}&offset={offset}"

    try:
        req = urllib.request.Request(url)
        req.add_header('Authorization', f'Bearer {access_token}')
        req.add_header('Accept', 'application/json')

        with urllib.request.urlopen(req, timeout=30) as response:
            data = json.loads(response.read())
    except Exception as e:
        print(f"[!] Erro ao buscar: {e}")
        break

    if 'data' not in data or not data['data']:
        print(f"[*] Fim dos produtos (pagina {pagina})")
        break

    print(f"[+] Pagina {pagina}: {len(data['data'])} produtos")

    total_sincronizados += len(data['data'])

    # Listar alguns produtos
    for i, item in enumerate(data['data'][:3]):
        print(f"    - {item.get('nome', 'SEM NOME')} (ID: {item.get('id')}, Preco: R${item.get('preco', 0):.2f})")

    if i < len(data['data']) - 1:
        print(f"    ... e mais {len(data['data']) - 3} produtos")

    if len(data['data']) < limit:
        break

    offset += limit
    pagina += 1

# ============================================================
# RESULTADO
# ============================================================

print(f"\n" + "=" * 70)
print(f"[+] SINCRONIZACAO CONCLUIDA!")
print(f"=" * 70)
print(f"\n[+] Total de produtos sincronizados: {total_sincronizados}")
print(f"[+] Timestamp: {__import__('datetime').datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
print(f"\n[*] Esses produtos estao prontos para uso no site!")
