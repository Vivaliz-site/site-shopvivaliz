#!/usr/bin/env python3
"""
Teste de atualização: vamos tentar atualizar 1 produto e ver exatamente qual é o erro
"""
import os
import json
import requests
from pathlib import Path
from dotenv import load_dotenv

# Carregar token
for f in [Path(r"C:\site-shopvivaliz\.env.local"), Path(r"C:\site-shopvivaliz\.env")]:
    if f.exists():
        load_dotenv(f)
        break

token = os.getenv("TINY_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")

print("🧪 TESTE DE ATUALIZAÇÃO DE PREÇO")
print("=" * 80)
print(f"\nToken: {len(token)} chars\n")

headers = {
    "Authorization": f"Bearer {token}",
    "Content-Type": "application/json"
}

# Buscar um produto primeiro
print("1️⃣  Buscando um produto...")
resp = requests.get(
    "https://api.tiny.com.br/public-api/v3/produtos?limit=5",
    headers=headers,
    timeout=20
)

if resp.status_code != 200:
    print(f"❌ Erro ao buscar: {resp.status_code}")
    exit(1)

products = resp.json().get("itens", [])
if not products:
    print("❌ Nenhum produto encontrado")
    exit(1)

product = products[0]
pid = product.get("id")
old_price = product.get("precos", {}).get("preco", 0)

print(f"✅ Encontrado produto ID: {pid}")
print(f"   Descrição: {product.get('descricao', '')[:50]}")
print(f"   Preço atual: R$ {old_price}")

# Tentar atualizar
print(f"\n2️⃣  Tentando atualizar preço de {old_price} para {old_price + 100}...")

new_price = old_price + 100

# Teste 1: Payload simples (só preço)
print("\n📤 Teste 1: Payload simples")
payload1 = {
    "precos": {
        "preco": new_price
    }
}

print(f"   Enviando: {json.dumps(payload1, ensure_ascii=False)}")

resp = requests.put(
    f"https://api.tiny.com.br/public-api/v3/produtos/{pid}",
    headers=headers,
    json=payload1,
    timeout=20
)

print(f"   Status: {resp.status_code}")
if resp.text:
    try:
        data = resp.json()
        print(f"   Resposta: {json.dumps(data, ensure_ascii=False, indent=2)}")
    except:
        print(f"   Resposta: {resp.text[:300]}")

# Se falhou, tentar com mais campos
if resp.status_code not in [200, 204]:
    print(f"\n📤 Teste 2: Payload com campos adicionais")

    payload2 = {
        "descricao": product.get("descricao", ""),
        "sku": product.get("sku", ""),
        "precos": {
            "preco": new_price
        }
    }

    print(f"   Campos: descricao, sku, precos")

    resp = requests.put(
        f"https://api.tiny.com.br/public-api/v3/produtos/{pid}",
        headers=headers,
        json=payload2,
        timeout=20
    )

    print(f"   Status: {resp.status_code}")
    if resp.text:
        try:
            data = resp.json()
            print(f"   Resposta: {json.dumps(data, ensure_ascii=False, indent=2)}")
        except:
            print(f"   Resposta: {resp.text[:300]}")

print("\n" + "=" * 80)
print("✅ Teste concluído")
