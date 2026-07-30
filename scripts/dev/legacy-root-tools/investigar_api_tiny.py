#!/usr/bin/env python3
"""
Investigação: Encontrar a melhor forma de acessar tabelas de preço no Tiny
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

print("🔍 INVESTIGAÇÃO: TABELAS DE PREÇO NO TINY")
print("=" * 100)

# Estratégia 1: Procurar endpoints que retornam informações de "lista"
print("\n1️⃣  ESTRATÉGIA 1: Procurar por 'lista' no formato plural/singular")
print("-" * 100)

endpoints_lista = [
    "/listas",
    "/lista",
    "/lista-precos",
    "/listas-precos",
    "/lista-preco",
    "/listas-preco",
]

for ep in endpoints_lista:
    url = f"https://api.tiny.com.br/public-api/v3{ep}"
    try:
        resp = requests.get(url, headers=headers, timeout=10)
        print(f"  GET {ep:30s} → {resp.status_code}")
        if resp.status_code == 200:
            print(f"     ✅ ENCONTRADO!")
            data = resp.json()
            print(f"     Estrutura: {json.dumps(data, indent=6, ensure_ascii=False)[:500]}")
    except:
        pass

# Estratégia 2: Procurar endpoints que retornam lista de um produto específico
print("\n2️⃣  ESTRATÉGIA 2: Preços de um produto específico (ID direto)")
print("-" * 100)

product_id = 337702887  # Produto que sabemos que existe

endpoints_produto = [
    f"/produtos/{product_id}/listas-preco",
    f"/produtos/{product_id}/lista-preco",
    f"/produtos/{product_id}/precos-tabelas",
    f"/produtos/{product_id}/preco-tabelas",
    f"/precos/{product_id}",
    f"/lista-preco/produtos/{product_id}",
]

for ep in endpoints_produto:
    url = f"https://api.tiny.com.br/public-api/v3{ep}"
    try:
        resp = requests.get(url, headers=headers, timeout=10)
        print(f"  GET {ep:50s} → {resp.status_code}")
        if resp.status_code == 200:
            print(f"     ✅ ENCONTRADO!")
            data = resp.json()
            print(f"     Resposta: {json.dumps(data, indent=6, ensure_ascii=False)[:500]}")
    except:
        pass

# Estratégia 3: Procurar endpoint para listar TODAS as listas/tabelas de preço
print("\n3️⃣  ESTRATÉGIA 3: Listar TODAS as tabelas de preço (global)")
print("-" * 100)

endpoints_global = [
    "/tabelas-preco",
    "/tabela-preco",
    "/preco-tabelas",
    "/listas-preco/tabelas",
    "/preco/tabelas",
    "/produtos/listas-preco",
]

for ep in endpoints_global:
    url = f"https://api.tiny.com.br/public-api/v3{ep}"
    try:
        resp = requests.get(url, headers=headers, timeout=10)
        print(f"  GET {ep:40s} → {resp.status_code}")
        if resp.status_code == 200:
            print(f"     ✅ ENCONTRADO!")
            data = resp.json()
            print(f"     Resposta: {json.dumps(data, indent=6, ensure_ascii=False)[:500]}")
    except:
        pass

# Estratégia 4: Verificar se há informação de preços no objeto produto com parametrização
print("\n4️⃣  ESTRATÉGIA 4: GET /produtos com parâmetros especiais")
print("-" * 100)

params_list = [
    {"include": "listas-preco"},
    {"include": "preco-tabelas"},
    {"preco": "tabelas"},
    {"expand": "preco-tabelas"},
    {"fields": "id,sku,descricao,precos,listas-preco"},
]

for params in params_list:
    try:
        resp = requests.get(
            f"https://api.tiny.com.br/public-api/v3/produtos/337702887",
            headers=headers,
            params=params,
            timeout=10
        )
        print(f"  GET /produtos/337702887 com params {params}")
        print(f"     Status: {resp.status_code}")
        if resp.status_code == 200:
            data = resp.json()
            # Procurar por novas chaves
            orig_keys = {'id', 'sku', 'descricao', 'tipo', 'descricaoComplementar', 'situacao',
                        'produtoPai', 'unidade', 'unidadePorCaixa', 'ncm', 'gtin', 'origem',
                        'garantia', 'observacoes', 'categoria', 'marca', 'dimensoes', 'precos',
                        'estoque', 'fornecedores', 'seo', 'tributacao', 'anexos', 'variacoes',
                        'kit', 'producao', 'codigoListaServicos', 'tipoVariacao'}
            new_keys = set(data.keys()) - orig_keys
            if new_keys:
                print(f"     ✅ NOVAS CHAVES: {new_keys}")
                for key in new_keys:
                    print(f"        {key}: {data[key]}")
    except:
        pass

print("\n" + "=" * 100)
print("🎯 CONCLUSÃO:")
print("   Se nenhum endpoint acima retornar dados de tabelas,")
print("   então a API v3 não expõe esse recurso.")
print("   Alternativas:")
print("   1. Usar API v2 do Tiny (se disponível)")
print("   2. Acessar relatório/dashboard do Tiny manualmente")
print("   3. Usar outra integração (webhook, banco de dados)")
