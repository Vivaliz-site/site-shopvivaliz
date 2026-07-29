#!/usr/bin/env python3
"""Testar o endpoint /listas-precos para descobrir format correto"""
import os
import requests
from pathlib import Path
from dotenv import load_dotenv

for f in [Path(r"C:\site-shopvivaliz\.env.local"), Path(r"C:\site-shopvivaliz\.env")]:
    if f.exists():
        load_dotenv(f)
        break

token = os.getenv("OLIST_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")

headers = {
    "Authorization": f"Bearer {token}",
    "Content-Type": "application/json"
}

print("\n" + "="*100)
print("TESTAR ENDPOINT /listas-precos")
print("="*100)

# 1. Listar todas as tabelas
print("\n[1] GET /listas-precos (listar tabelas)...")
url = "https://api.tiny.com.br/public-api/v3/listas-precos"
resp = requests.get(url, headers=headers, timeout=10)
print(f"    Status: {resp.status_code}")

if resp.status_code == 200:
    dados = resp.json()
    print(f"    Resultado:")
    import json
    print(json.dumps(dados, indent=2)[:500])

# 2. Testar POST para tabela 982 (PDV)
print("\n[2] POST /listas-precos/982 (testar atualizar PDV)...")

# Tentar formato 1: lista de produtos
payload1 = {
    "produtos": [
        {
            "idProduto": 337764380,
            "preco": 298.00
        }
    ]
}

resp = requests.post(
    "https://api.tiny.com.br/public-api/v3/listas-precos/982",
    headers=headers,
    json=payload1,
    timeout=10
)

print(f"    Payload 1 (lista): {payload1}")
print(f"    Status: {resp.status_code}")
if resp.status_code != 200:
    print(f"    Erro: {resp.text[:200]}")

# 3. Listar preos de um produto especifico
print("\n[3] GET /produtos/337764380/listas-precos (precos de um produto)...")
resp = requests.get(
    "https://api.tiny.com.br/public-api/v3/produtos/337764380/listas-precos",
    headers=headers,
    timeout=10
)
print(f"    Status: {resp.status_code}")
if resp.status_code == 200:
    dados = resp.json()
    print(f"    Resultado (primeiras 300 chars):")
    import json
    print(json.dumps(dados, indent=2)[:300])
else:
    print(f"    Erro: {resp.text[:200]}")

# 4. Testar PUT direto no produto/tabela
print("\n[4] PUT /produtos/337764380/lista-preco/982 (testar update direto)...")
payload = {
    "preco": 298.00
}

resp = requests.put(
    "https://api.tiny.com.br/public-api/v3/produtos/337764380/lista-preco/982",
    headers=headers,
    json=payload,
    timeout=10
)

print(f"    Payload: {payload}")
print(f"    Status: {resp.status_code}")
if resp.status_code != 200:
    print(f"    Erro: {resp.text[:200]}")

print("\n" + "="*100)
