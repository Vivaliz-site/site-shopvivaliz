#!/usr/bin/env python3
"""
Debug: Encontrar como acessar os preços das tabelas (PDV, Shopee, Amazon, TikTok)
"""
import os
import json
import requests
from pathlib import Path
from dotenv import load_dotenv

for f in [Path(r"C:\site-shopvivaliz\.env.local"), Path(r"C:\site-shopvivaliz\.env")]:
    if f.exists():
        load_dotenv(f)
        break

token = os.getenv("TINY_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")

headers = {
    "Authorization": f"Bearer {token}",
    "Content-Type": "application/json"
}

print("\n🔍 DEBUG: TESTANDO ENDPOINTS DE TABELAS DE PREÇO")
print("="*100)

# Usar um produto que sabemos que existe
product_id = 337703360  # Vaso Decorativo - linha 2

print(f"\n📦 Produto teste: ID {product_id}")
print("="*100)

# 1. Tentar GET /produtos/{id}/listas-precos (sem table_id)
print("\n1️⃣ Tentando: GET /produtos/{id}/listas-precos")
print("-"*100)

url1 = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}/listas-precos"
resp1 = requests.get(url1, headers=headers, timeout=10)
print(f"Status: {resp1.status_code}")
if resp1.status_code in [200, 201]:
    print("✅ ENCONTRADO!")
    print(json.dumps(resp1.json(), indent=2, ensure_ascii=False))
else:
    print(f"❌ Erro: {resp1.text[:300]}")

# 2. Tentar GET /produtos/{id}/lista-preco (singular)
print("\n2️⃣ Tentando: GET /produtos/{id}/lista-preco")
print("-"*100)

url2 = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}/lista-preco"
resp2 = requests.get(url2, headers=headers, timeout=10)
print(f"Status: {resp2.status_code}")
if resp2.status_code in [200, 201]:
    print("✅ ENCONTRADO!")
    print(json.dumps(resp2.json(), indent=2, ensure_ascii=False))
else:
    print(f"❌ Erro: {resp2.text[:300]}")

# 3. Tentar GET /produtos/{id}/listas-precos/{table_id}
print("\n3️⃣ Tentando: GET /produtos/{id}/listas-precos/{table_id}")
print("-"*100)

table_ids = {"PDV": 982, "Shopee": 989, "Amazon": 991, "TikTok": 990}

for nome, table_id in table_ids.items():
    url3 = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}/listas-precos/{table_id}"
    resp3 = requests.get(url3, headers=headers, timeout=10)
    print(f"\n   {nome} (ID {table_id}): Status {resp3.status_code}")

    if resp3.status_code in [200, 201]:
        print(f"   ✅ ENCONTRADO!")
        data = resp3.json()
        print(f"   Resposta: {json.dumps(data, indent=4, ensure_ascii=False)[:200]}")
    else:
        print(f"   ❌ Erro: {resp3.text[:150]}")

# 4. Tentar GET /produtos/{id} com expand/include
print("\n4️⃣ Tentando: GET /produtos/{id} com parâmetros de expansão")
print("-"*100)

params_list = [
    {"expand": "listas-precos"},
    {"include": "listas-precos"},
    {"fields": "id,sku,descricao,precos,listas-precos"},
]

for params in params_list:
    url4 = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}"
    resp4 = requests.get(url4, headers=headers, params=params, timeout=10)
    print(f"\n   Params {params}: Status {resp4.status_code}")

    if resp4.status_code in [200, 201]:
        data = resp4.json()
        # Verificar se há novas chaves
        if isinstance(data, dict):
            keys = data.keys()
            print(f"   Chaves retornadas: {list(keys)}")

            # Procurar por "lista", "preco", "tabela"
            for key in keys:
                if 'lista' in key.lower() or 'tabela' in key.lower() or 'preco' in key.lower():
                    print(f"   ✅ ENCONTRADA CHAVE: {key}")
                    val = data[key]
                    if isinstance(val, (list, dict)):
                        print(f"      {json.dumps(val, indent=6, ensure_ascii=False)[:300]}")
                    else:
                        print(f"      {val}")

# 5. Procurar endpoint alternativo: /produtos/{id}/precos
print("\n5️⃣ Tentando endpoints alternativos")
print("-"*100)

alt_endpoints = [
    f"/produtos/{product_id}/precos",
    f"/produtos/{product_id}/preco-tabelas",
    f"/produtos/{product_id}/precos-tabelas",
    f"/lista-preco/produtos/{product_id}",
    f"/listas-preco/produtos/{product_id}",
]

for ep in alt_endpoints:
    url_alt = f"https://api.tiny.com.br/public-api/v3{ep}"
    resp_alt = requests.get(url_alt, headers=headers, timeout=10)
    print(f"\n   {ep}: Status {resp_alt.status_code}")

    if resp_alt.status_code in [200, 201]:
        print(f"   ✅ ENCONTRADO!")
        print(f"   {json.dumps(resp_alt.json(), indent=4, ensure_ascii=False)[:300]}")

print("\n" + "="*100)
print("🎯 CONCLUSÃO:")
print("   Se nenhum endpoint acima retornar dados das tabelas,")
print("   então as tabelas de preço não são accessíveis via API v3")
print("   Alternativas:")
print("   1. Usar relatório do dashboard Tiny")
print("   2. Acessar banco de dados diretamente")
print("   3. Usar API v2 do Tiny (se disponível)")
print("="*100)
