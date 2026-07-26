#!/usr/bin/env python3
"""
Teste para encontrar endpoint de tabelas de preços
"""
import os
import json
import requests
from pathlib import Path
from dotenv import load_dotenv

env_file = Path(r"C:\site-shopvivaliz\.env.local")
load_dotenv(env_file)

token = os.getenv("OLIST_ACCESS_TOKEN")
headers = {
    "Authorization": f"Bearer {token}",
    "Content-Type": "application/json"
}

# Procurar por endpoints de tabelas de preços
endpoints = [
    "/listas-preco",
    "/lista-preco",
    "/tabelas-preco",
    "/tabela-preco",
    "/precos-tabelas",
    "/precos",
    "/preco",
    "/listas-precos-tabelas",
    "/produtos/337702887/precos-tabelas",
]

print("🔍 Procurando endpoint de tabelas de preços")
print("=" * 60)

for endpoint in endpoints:
    url = f"https://api.tiny.com.br/public-api/v3{endpoint}"
    try:
        response = requests.get(url, headers=headers, timeout=5)
        print(f"\n📍 {endpoint}")
        print(f"   Status: {response.status_code}")

        if response.status_code == 200:
            data = response.json()
            print(f"   ✅ SUCESSO!")
            print(f"   Estrutura: {list(data.keys())}")
            if "itens" in data:
                print(f"   Total: {len(data['itens'])} itens")
                if data["itens"]:
                    print(f"   Primeiro item: {data['itens'][0]}")
            break
    except Exception as e:
        pass

print("\n" + "=" * 60)
print("\nTentando buscar preços de um produto específico...")

product_id = 337702887
url = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}"
response = requests.get(url, headers=headers)
if response.status_code == 200:
    product = response.json()
    print(f"\n✅ Produto {product_id}:")
    print(json.dumps(product, indent=2, ensure_ascii=False)[:1000])
