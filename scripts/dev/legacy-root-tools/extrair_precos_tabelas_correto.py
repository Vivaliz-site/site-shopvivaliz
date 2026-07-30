#!/usr/bin/env python3
"""
Extrair preços das tabelas de preço do Tiny
Testando diferentes abordagens
"""
import os
import json
import time
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

print("\n🔍 TESTANDO ENDPOINTS PARA EXTRAIR PREÇOS DAS TABELAS")
print("="*100)

# Primeiro, obter lista de todos os produtos com todas as informações
print("\n1️⃣ Obtendo lista de produtos...")

url_produtos = "https://api.tiny.com.br/public-api/v3/produtos?limit=100&offset=0"
resp = requests.get(url_produtos, headers=headers, timeout=30)

if resp.status_code != 200:
    print(f"❌ Erro ao carregar: {resp.status_code}")
    exit(1)

products = resp.json().get("itens", [])
print(f"✅ Carregados {len(products)} produtos")

# Pegar um produto para investigar
if products:
    prod = products[0]
    product_id = prod.get("id")
    print(f"\n2️⃣ Analisando produto ID {product_id}: {prod.get('descricao')}")
    print("="*100)

    # Verificar o que vem no objeto produto
    print("\n   Chaves do produto:")
    for key in sorted(prod.keys()):
        val = prod[key]
        if isinstance(val, dict):
            print(f"   - {key}: {list(val.keys())}")
        elif isinstance(val, list):
            print(f"   - {key}: lista com {len(val)} itens")
        else:
            print(f"   - {key}: {val}")

    # Agora, tentar obter preços via diferentes endpoints
    print(f"\n3️⃣ Testando endpoints para preços das TABELAS do produto {product_id}")
    print("="*100)

    # Tentar: GET /produtos/{id} com expand de preços
    print(f"\n   a) GET /produtos/{product_id}?expand=precos")
    url_exp1 = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}?expand=precos"
    resp = requests.get(url_exp1, headers=headers, timeout=10)
    print(f"      Status: {resp.status_code}")
    if resp.status_code == 200:
        data = resp.json()
        if 'precos' in data:
            print(f"      ✅ Preços encontrados: {json.dumps(data['precos'], indent=8, ensure_ascii=False)}")

    time.sleep(0.5)

    # Tentar: GET /precos com filtro de produto
    print(f"\n   b) GET /precos?produto_id={product_id}")
    url_exp2 = f"https://api.tiny.com.br/public-api/v3/precos?produto_id={product_id}"
    resp = requests.get(url_exp2, headers=headers, timeout=10)
    print(f"      Status: {resp.status_code}")
    if resp.status_code == 200:
        data = resp.json()
        print(f"      ✅ Resposta: {json.dumps(data, indent=8, ensure_ascii=False)[:500]}")

    time.sleep(0.5)

    # Tentar: GET /lista-preco/produtos/{id}
    print(f"\n   c) GET /lista-preco/produtos/{product_id}")
    url_exp3 = f"https://api.tiny.com.br/public-api/v3/lista-preco/produtos/{product_id}"
    resp = requests.get(url_exp3, headers=headers, timeout=10)
    print(f"      Status: {resp.status_code}")
    if resp.status_code == 200:
        data = resp.json()
        print(f"      ✅ Resposta: {json.dumps(data, indent=8, ensure_ascii=False)[:500]}")

    time.sleep(0.5)

    # Tentar com campos específicos
    print(f"\n   d) GET /produtos/{product_id}?fields=id,sku,descricao,precos")
    url_exp4 = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}?fields=id,sku,descricao,precos"
    resp = requests.get(url_exp4, headers=headers, timeout=10)
    print(f"      Status: {resp.status_code}")
    if resp.status_code == 200:
        data = resp.json()
        print(f"      Chaves: {list(data.keys())}")
        if 'precos' in data:
            print(f"      ✅ Preços: {json.dumps(data['precos'], indent=8, ensure_ascii=False)}")

# Agora investigar se há um endpoint separado para listas de preço
print(f"\n4️⃣ Investigando endpoint /listas-precos")
print("="*100)

url_listas = "https://api.tiny.com.br/public-api/v3/listas-precos"
resp = requests.get(url_listas, headers=headers, timeout=10)
print(f"   GET /listas-precos: Status {resp.status_code}")

if resp.status_code == 200:
    data = resp.json()
    tabelas = data.get("itens", [])
    print(f"   ✅ {len(tabelas)} tabelas encontradas:")

    for tab in tabelas:
        print(f"      - ID {tab.get('id'):3d}: {tab.get('descricao'):30s}")

    # Agora tentar acessar preços de UMA tabela específica
    if tabelas and products:
        tab_id = tabelas[0].get('id')
        prod_id = products[0].get('id')

        print(f"\n5️⃣ Tentando acessar preços da tabela {tab_id} para o produto {prod_id}")
        print("-"*100)

        # Estratégia A: /listas-precos/{tab_id}/produtos/{prod_id}
        print(f"\n   a) GET /listas-precos/{tab_id}/produtos/{prod_id}")
        url_a = f"https://api.tiny.com.br/public-api/v3/listas-precos/{tab_id}/produtos/{prod_id}"
        resp = requests.get(url_a, headers=headers, timeout=10)
        print(f"      Status: {resp.status_code}")
        if resp.status_code == 200:
            print(f"      ✅ {json.dumps(resp.json(), indent=8, ensure_ascii=False)[:300]}")

        time.sleep(0.5)

        # Estratégia B: /listas-precos/{tab_id}?produto_id={prod_id}
        print(f"\n   b) GET /listas-precos/{tab_id}?produto_id={prod_id}")
        url_b = f"https://api.tiny.com.br/public-api/v3/listas-precos/{tab_id}?produto_id={prod_id}"
        resp = requests.get(url_b, headers=headers, timeout=10)
        print(f"      Status: {resp.status_code}")
        if resp.status_code == 200:
            print(f"      ✅ {json.dumps(resp.json(), indent=8, ensure_ascii=False)[:300]}")

        time.sleep(0.5)

        # Estratégia C: /listas-precos/{tab_id} (pegar todos e filtrar)
        print(f"\n   c) GET /listas-precos/{tab_id} (todos os preços da tabela)")
        url_c = f"https://api.tiny.com.br/public-api/v3/listas-precos/{tab_id}"
        resp = requests.get(url_c, headers=headers, timeout=10)
        print(f"      Status: {resp.status_code}")
        if resp.status_code == 200:
            data = resp.json()
            items = data.get("itens", [])
            print(f"      ✅ {len(items)} itens encontrados na tabela")
            if items:
                print(f"      Primeiro item: {json.dumps(items[0], indent=8, ensure_ascii=False)[:300]}")

print("\n" + "="*100)
print("✅ Investigação concluída!")
