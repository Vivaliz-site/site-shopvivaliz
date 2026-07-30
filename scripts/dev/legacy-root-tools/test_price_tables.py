#!/usr/bin/env python3
"""
Teste: Procurar tabelas de preço (PDV, Shopee, Amazon, TikTok) no Tiny
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

token = os.getenv("OLIST_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")

headers = {
    "Authorization": f"Bearer {token}",
    "Content-Type": "application/json"
}

print("🧪 PROCURANDO TABELAS DE PREÇO")
print("=" * 80)

# Endpoints possíveis para tabelas
endpoints = [
    "/listas-preco",
    "/lista-preco",
    "/tabelas-preco",
    "/preco-tabelas",
    "/produtos/337702887/lista-preco",  # Usar ID real
    "/produtos/337702887/listas-preco",
    "/produtos/337702887/tabelas-preco",
]

for ep in endpoints:
    url = f"https://api.tiny.com.br/public-api/v3{ep}"
    print(f"\n📍 {ep}")

    try:
        resp = requests.get(url, headers=headers, timeout=10)
        print(f"   Status: {resp.status_code}")

        if resp.status_code == 200:
            print(f"   ✅ ENCONTRADO!")
            data = resp.json()
            print(f"   Chaves: {list(data.keys())}")
            if 'itens' in data:
                print(f"   Itens: {len(data.get('itens', []))}")
                if data.get('itens'):
                    print(f"   Primeiro: {data['itens'][0]}")
            break
    except:
        pass

print("\n" + "=" * 80)
print("\n🔍 Buscando em documento Tiny...")
print("   Verificando se as tabelas vêm junto com /produtos")

# Buscar um produto completo
resp = requests.get(
    "https://api.tiny.com.br/public-api/v3/produtos/337702887",
    headers=headers,
    timeout=20
)

if resp.status_code == 200:
    prod = resp.json()
    print("\n✅ Estrutura do produto:")

    if 'precos' in prod:
        print(f"\n   precos: {json.dumps(prod['precos'], indent=4, ensure_ascii=False)}")

    # Mostrar todas as chaves
    print(f"\n   Todas as chaves do produto: {list(prod.keys())}")

    # Procurar por "tabela", "lista", "shopee", "amazon", "tiktok", "pdv"
    keywords = ['tabela', 'lista', 'shopee', 'amazon', 'tiktok', 'pdv', 'tabelas', 'listas']

    print(f"\n   Procurando por palavras-chave: {keywords}")
    encontradas = []

    for key in prod.keys():
        key_lower = key.lower()
        for kw in keywords:
            if kw in key_lower:
                encontradas.append(key)
                print(f"      ✅ {key}")

    if not encontradas:
        print(f"      ❌ Nenhuma tabela encontrada nas chaves diretas")
