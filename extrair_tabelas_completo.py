#!/usr/bin/env python3
"""
Extrair tabelas de preço completo do Tiny
GET /listas-precos retorna todas as tabelas disponíveis
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

print("🎯 EXTRAINDO TODAS AS TABELAS DE PREÇO DO TINY")
print("=" * 100)

# GET /listas-precos - Lista TODAS as tabelas
url = "https://api.tiny.com.br/public-api/v3/listas-precos"

try:
    resp = requests.get(url, headers=headers, timeout=20)
    print(f"\nStatus: {resp.status_code}\n")

    if resp.status_code == 200:
        data = resp.json()
        print("✅ TABELAS ENCONTRADAS:\n")
        print(json.dumps(data, indent=2, ensure_ascii=False))

        # Processar
        print("\n" + "=" * 100)
        print("📊 ANÁLISE DAS TABELAS:\n")

        tables = data.get("itens", [])
        print(f"Total de tabelas: {len(tables)}\n")

        for table in tables:
            id_tabela = table.get("id")
            desc = table.get("descricao")
            acrescimo = table.get("acrescimoDesconto", 0)
            print(f"  ID: {id_tabela:4d} | Descrição: {desc:30s} | Acréscimo/Desconto: {acrescimo}")

        print("\n" + "=" * 100)
        print("🎯 IDENTIFICAÇÃO DE TABELAS DESEJADAS:\n")

        # Mapear para as tabelas pedidas
        target_tables = {
            "PDV": None,
            "Amazon": None,
            "Shopee": None,
            "TikTok": None,
        }

        for table in tables:
            desc = table.get("descricao", "").lower()
            id_tabela = table.get("id")

            if "pdv" in desc:
                target_tables["PDV"] = id_tabela
                print(f"  ✅ PDV encontrado: ID {id_tabela}")
            elif "amazon" in desc and "fba" not in desc.lower():
                target_tables["Amazon"] = id_tabela
                print(f"  ✅ Amazon encontrado: ID {id_tabela}")
            elif "shopee" in desc:
                target_tables["Shopee"] = id_tabela
                print(f"  ✅ Shopee encontrado: ID {id_tabela}")
            elif "tiktok" in desc or "tik tok" in desc:
                target_tables["TikTok"] = id_tabela
                print(f"  ✅ TikTok encontrado: ID {id_tabela}")

        print("\n" + "=" * 100)
        print("📋 RESUMO FINAL:\n")
        for nome, id_tabela in target_tables.items():
            status = f"✅ ID {id_tabela}" if id_tabela else "❌ Não encontrada"
            print(f"  {nome:15s}: {status}")

        # Agora buscar preços de um produto nessas tabelas
        print("\n" + "=" * 100)
        print("💰 TESTANDO EXTRAÇÃO DE PREÇOS DE UM PRODUTO:\n")

        product_id = 337702887

        # Tentar obter preços da tabela PDV
        if target_tables["PDV"]:
            url_pdv = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}/listas-precos/{target_tables['PDV']}"
            print(f"  Tentando: GET /produtos/{product_id}/listas-precos/{target_tables['PDV']}")

            try:
                resp_pdv = requests.get(url_pdv, headers=headers, timeout=10)
                print(f"  Status: {resp_pdv.status_code}")
                if resp_pdv.status_code == 200:
                    print(f"  ✅ Preço PDV: {resp_pdv.json()}")
            except:
                print(f"  ❌ Erro na requisição")

    else:
        print(f"❌ Erro: {resp.status_code}")
        print(f"Resposta: {resp.text[:500]}")

except Exception as e:
    print(f"❌ Exceção: {e}")
