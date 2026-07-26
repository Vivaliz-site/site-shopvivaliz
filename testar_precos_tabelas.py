#!/usr/bin/env python3
import os
import requests
from pathlib import Path
from dotenv import load_dotenv

for f in [Path(r'C:\site-shopvivaliz\.env.local'), Path(r'C:\site-shopvivaliz\.env')]:
    if f.exists():
        load_dotenv(f)
        break

token = os.getenv('OLIST_ACCESS_TOKEN') or os.getenv('TINY_ACCESS_TOKEN')

headers = {
    'Authorization': f'Bearer {token}',
    'Content-Type': 'application/json'
}

print('\n🔍 TESTE: Verificar dados das tabelas\n')

test_ids = [337703360, 337764380, 337764276]
table_ids = {'PDV': 982, 'Shopee': 989, 'Amazon': 991, 'TikTok': 990}

for prod_id in test_ids:
    print(f'Produto ID: {prod_id}')
    print('-'*80)

    for tabela_nome, tabela_id in table_ids.items():
        url = f'https://api.tiny.com.br/public-api/v3/listas-precos/{tabela_id}?produto_id={prod_id}'

        try:
            resp = requests.get(url, headers=headers, timeout=10)

            if resp.status_code == 200:
                data = resp.json()
                excecoes = data.get('excecoes', [])

                print(f'  {tabela_nome}: {len(excecoes)} exceções')
                if excecoes:
                    print(f'    Primeira: ID {excecoes[0].get("idProduto")} = R$ {excecoes[0].get("preco")}')
            else:
                print(f'  {tabela_nome}: Status {resp.status_code}')
        except Exception as e:
            print(f'  {tabela_nome}: Erro')

    print()
