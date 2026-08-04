#!/usr/bin/env python3
"""
Teste de endpoints da API Tiny com nomes em português
"""
import os
import requests
from pathlib import Path
from dotenv import load_dotenv

# Carregar .env
env_file = Path(r"C:\site-shopvivaliz\.env.local")
if env_file.exists():
    load_dotenv(env_file)

access_token = os.getenv("TINY_ACCESS_TOKEN")
base_url = "https://api.tiny.com.br/public-api/v3"

if not access_token:
    print("❌ Token não encontrado!")
    exit(1)

headers = {
    "Authorization": f"Bearer {access_token}",
    "Content-Type": "application/json",
    "Accept": "application/json",
    "User-Agent": "ShopVivaliz/3.0"
}

# Testes de endpoints em português
endpoints = [
    "/produtos",
    "/produtos?limit=5",
    "/produtos/BRP1129628561",  # Tentando com um SKU
    "/lista-precos",
    "/tabela-precos",
    "/precos",
    "/categorias",
    "/categorias/todas",
    "/pedidos",
]

print("🔍 TESTE DE ENDPOINTS TINY API (Português)")
print("=" * 80)
print(f"Token: {access_token[:30]}...")
print(f"Base URL: {base_url}\n")

for endpoint in endpoints:
    url = f"{base_url}{endpoint}"
    print(f"📍 GET {endpoint}")
    try:
        response = requests.get(url, headers=headers, timeout=10)
        print(f"   Status: {response.status_code}")

        if response.status_code == 200:
            data = response.json()
            if "data" in data:
                print(f"   ✅ Response tem 'data'")
                items = data.get('data', [])
                if isinstance(items, list):
                    print(f"      Total de itens: {len(items)}")
                    if len(items) > 0:
                        print(f"      Primeiro item: {list(items[0].keys())[:5]}...")
                elif isinstance(items, dict):
                    print(f"      Chaves: {list(items.keys())[:5]}...")
            elif isinstance(data, list):
                print(f"   ✅ Response é lista com {len(data)} itens")
                if len(data) > 0:
                    print(f"      Primeiro item: {list(data[0].keys())[:5]}..." if isinstance(data[0], dict) else f"      Tipo: {type(data[0])}")
            else:
                print(f"   ✅ Response: {list(data.keys())[:5]}...")
        else:
            print(f"   ❌ Erro: {response.status_code}")
            if response.text:
                print(f"   Resposta: {response.text[:150]}")
    except Exception as e:
        print(f"   ❌ Erro: {e}")

    print()
