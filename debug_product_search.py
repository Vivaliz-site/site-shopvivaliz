#!/usr/bin/env python3
import os
import json
import requests
from pathlib import Path
from dotenv import load_dotenv

env_file = Path(r"C:\site-shopvivaliz\.env.local")
load_dotenv(env_file)

token = os.getenv("TINY_ACCESS_TOKEN")
headers = {
    "Authorization": f"Bearer {token}",
    "Content-Type": "application/json"
}

# Exemplos de PRODUCT_NUMBER da planilha
examples = ["U3683632897", "U2953019277", "U1449109084", "U1963231373"]

print("🔍 Procurando produtos nos 1000 primeiros...")

# Buscar todos os produtos
resp = requests.get(
    "https://api.tiny.com.br/public-api/v3/produtos?limit=1000",
    headers=headers,
    timeout=20
)
data = resp.json()
products = data.get("itens", [])

print(f"Total de produtos retornados: {len(products)}\n")

# Procurar pelos exemplos
print("Procurando pelos PRODUCT_NUMBER do ML:")
found_count = 0

for ex in examples:
    for prod in products:
        sku = prod.get("sku", "")
        gtin = prod.get("gtin", "")
        desc = prod.get("descricao", "")[:60]

        if ex.lower() in sku.lower() or ex.lower() in gtin.lower():
            print(f"\n✅ Encontrado: {ex}")
            print(f"   SKU: {sku}")
            print(f"   GTIN: {gtin}")
            print(f"   Descrição: {desc}")
            print(f"   ID: {prod.get('id')}")
            print(f"   Preço: {prod.get('precos', {}).get('preco')}")
            found_count += 1
            break

if found_count == 0:
    print("\n❌ Nenhum match encontrado")
    print("\nPrimeiros 15 produtos no Tiny (SKU e descrição):")
    for i, prod in enumerate(products[:15], 1):
        sku = prod.get("sku", "")
        desc = prod.get("descricao", "")[:40]
        print(f"  {i:2d}. SKU: {sku:20s} | {desc}")

print(f"\nTotal paginação: {data.get('paginacao', {})}")
