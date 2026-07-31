#!/usr/bin/env python3
"""
TRACE: Acompanhar um item do começo ao fim
1. Item ML → 2. Encontrar no Mapeamento → 3. ID Tiny → 4. Preços do Cadastro → 5. Preços das Tabelas
"""
import os
import requests
import pandas as pd
from pathlib import Path
from dotenv import load_dotenv
from difflib import SequenceMatcher

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
print("TRACE: Acompanhar um item ML ate os precos das tabelas")
print("="*100)

# ITEM TESTE
ITEM_TESTE = "Assento Almofadado Thema Incepa/hawaii B"

print(f"\n[0] ITEM ESCOLHIDO:")
print(f"    {ITEM_TESTE}")

# 1. CARREGAR MAPEAMENTO
print(f"\n[1] Procurando no MAPEAMENTO (Anuncios)...")

mapping_ml_tiny = {}

for mfile in [
    r'C:\Users\user\Downloads\anuncios_2026-07-26-12-26-48.xls',
    r'C:\Users\user\Downloads\anuncios_2026-07-26-12-26-50.xls',
]:
    if Path(mfile).exists():
        df = pd.read_excel(mfile, sheet_name=0)
        for idx, row in df.iterrows():
            titulo = str(row['Título']).strip()
            id_tiny = int(row['Id'])
            sku = row.get('Produto (SKU)')
            mapping_ml_tiny[titulo] = {'id': id_tiny, 'sku': sku}

# Procurar o item
best_match = None
best_score = 0

item_lower = ITEM_TESTE.lower()

for titulo_map, dados in mapping_ml_tiny.items():
    score = SequenceMatcher(None, item_lower, titulo_map.lower()).ratio()

    if score > best_score:
        best_score = score
        best_match = (titulo_map, dados)

if best_match and best_score > 0.5:
    titulo_encontrado, dados_encontrados = best_match
    id_tiny = dados_encontrados['id']
    sku_tiny = dados_encontrados['sku']

    print(f"    ENCONTRADO!")
    print(f"      Titulo no Mapeamento: {titulo_encontrado}")
    print(f"      Score de similaridade: {best_score:.2%}")
    print(f"      ID Tiny: {id_tiny}")
    print(f"      SKU: {sku_tiny}")
else:
    print(f"    NAO ENCONTRADO")
    exit(1)

# 2. CARREGAR PRODUTO TINY
print(f"\n[2] Buscando PRODUTO no Tiny (ID {id_tiny})...")

url_prod = f"https://api.tiny.com.br/public-api/v3/produtos/{id_tiny}"
resp = requests.get(url_prod, headers=headers, timeout=20)

if resp.status_code == 200:
    prod = resp.json()
    sku_tiny = prod.get('sku')
    desc_tiny = prod.get('descricao')
    preco_cadastro = prod.get('precos', {}).get('preco')

    print(f"    ENCONTRADO!")
    print(f"      SKU: {sku_tiny}")
    print(f"      Descricao: {desc_tiny}")
    print(f"      Preco Cadastro: R$ {preco_cadastro}")
else:
    print(f"    Erro: {resp.status_code}")
    exit(1)

# 3. CARREGAR PRECOS DAS TABELAS
print(f"\n[3] Buscando PRECOS DAS TABELAS para este produto...")

TABELAS = {"PDV": 982, "Shopee": 989, "Amazon": 991, "TikTok": 990}

for tabela_nome, tabela_id in TABELAS.items():
    url_tabela = f"https://api.tiny.com.br/public-api/v3/listas-precos/{tabela_id}?produto_id={id_tiny}"

    try:
        resp = requests.get(url_tabela, headers=headers, timeout=10)

        if resp.status_code == 200:
            data = resp.json()
            excecoes = data.get("excecoes", [])

            # Procurar pela excecao deste produto
            preco_tabela = None

            for exc in excecoes:
                if exc.get("idProduto") == id_tiny:
                    preco_tabela = exc.get("preco")
                    break

            if preco_tabela:
                print(f"    {tabela_nome:15s}: R$ {preco_tabela:10.2f}")
            else:
                print(f"    {tabela_nome:15s}: (nao configurado para este produto)")
        else:
            print(f"    {tabela_nome:15s}: Erro {resp.status_code}")

    except Exception as e:
        print(f"    {tabela_nome:15s}: Erro - {str(e)[:40]}")

print(f"\n" + "="*100)
print(f"TRACE CONCLUIDO!")
print("="*100)
