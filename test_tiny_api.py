#!/usr/bin/env python3
"""
Teste de endpoints da API Tiny para descobrir a estrutura correta
"""
import os
import requests
from pathlib import Path
from dotenv import load_dotenv

# Carregar .env
env_file = Path(r"C:\site-shopvivaliz\.env.local")
if env_file.exists():
    load_dotenv(env_file)

access_token = os.getenv("OLIST_ACCESS_TOKEN")
base_url = "https://api.tiny.com.br/public-api/v3"

if not access_token:
    print("❌ Token não encontrado!")
    exit(1)

headers = {
    "Authorization": f"Bearer {access_token}",
    "Content-Type": "application/json"
}

# Testes de endpoints
endpoints = [
    "/products",
    "/products?limit=5",
    "/price-tables",
    "/price_tables",
    "/catalogs",
    "/price/tables",
]

print("🔍 TESTE DE ENDPOINTS TINY API")
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
                print(f"   ✅ Response tem 'data' com {len(data.get('data', []))} itens")
                if len(data.get('data', [])) > 0:
                    first_item = data['data'][0]
                    print(f"   Estrutura do primeiro item: {list(first_item.keys())[:5]}...")
            elif "pagination" in data:
                print(f"   ✅ Response tem 'pagination'")
            else:
                print(f"   ✅ Response: {list(data.keys())}")
        elif response.status_code == 404:
            print(f"   ❌ Endpoint não encontrado (404)")
        elif response.status_code == 401:
            print(f"   ❌ Token expirado (401)")
        else:
            print(f"   ❌ Erro: {response.status_code}")
            if response.text:
                print(f"   Resposta: {response.text[:100]}")
    except Exception as e:
        print(f"   ❌ Erro: {e}")

    print()
