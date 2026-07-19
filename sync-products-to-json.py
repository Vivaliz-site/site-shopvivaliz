#!/usr/bin/env python3
"""
Sincronizar produtos ERP para JSON
PHP vai ler esse JSON
"""

import json
import os
import urllib.request
from pathlib import Path

print("[*] Sincronizando produtos para JSON...")

env_file = Path(".env")
token = (
    os.getenv("OLIST_ACCESS_TOKEN", "").strip()
    or os.getenv("TINY_ACCESS_TOKEN", "").strip()
    or os.getenv("TOKEN_API_OLIST", "").strip()
)

if not token and env_file.exists():
    for line in env_file.read_text(encoding="utf-8").splitlines():
        if line.startswith(("OLIST_ACCESS_TOKEN=", "TINY_ACCESS_TOKEN=", "TOKEN_API_OLIST=")):
            token = line.split('=', 1)[1].strip()
            if token:
                break

if not token:
    print("[!] Token não encontrado!")
    exit(1)

# Buscar todos os produtos
all_products = []
offset = 0
limit = 100
page = 1

while True:
    url = f"https://api.tiny.com.br/public-api/v3/produtos?limit={limit}&offset={offset}"

    try:
        req = urllib.request.Request(url)
        req.add_header('Authorization', f'Bearer {token}')

        with urllib.request.urlopen(req, timeout=30) as response:
            data = json.loads(response.read())
    except Exception as e:
        print(f"[!] Erro na página {page}: {e}")
        break

    if 'itens' not in data or not data['itens']:
        print(f"[*] Fim dos produtos (página {page})")
        break

    items = data['itens']
    all_products.extend(items)

    print(f"[+] Página {page}: {len(items)} produtos (total: {len(all_products)})")

    if len(items) < limit:
        break

    offset += limit
    page += 1

# Salvar em JSON
output_file = Path("storage/products-cache.json")
output_file.parent.mkdir(parents=True, exist_ok=True)

with open(output_file, 'w', encoding='utf-8') as f:
    json.dump({
        'total': len(all_products),
        'timestamp': __import__('datetime').datetime.now().isoformat(),
        'itens': all_products
    }, f, ensure_ascii=False, indent=2)

print(f"\n[+] SUCESSO!")
print(f"    Total: {len(all_products)} produtos")
print(f"    Salvo em: {output_file}")
