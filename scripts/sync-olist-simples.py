#!/usr/bin/env python3
"""
Sincronizacao SIMPLES - Tenta multiplos endpoints
"""
import os
import json
import requests
from pathlib import Path
from datetime import datetime

print("Testando sincronizacao Olist...")

# Credenciais
CLIENT_ID = os.getenv('OLIST_CLIENT_ID', 'tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553')
CLIENT_SECRET = os.getenv('OLIST_CLIENT_SECRET', 'sh1MLgXhFlvycybhlShnvQMcEL8T2GWv')

print(f"Client ID: {CLIENT_ID[:40]}...")

# Tentar metodo 1: Token como parametro
print("\n[Teste 1] Usando token como parametro...")
try:
    response = requests.get(
        'https://api.tiny.com.br/api/v2/produtos.json',
        params={
            'token': CLIENT_ID,  # Tentar client_id como token
            'formato': 'json',
            'limitar': 200
        },
        timeout=10
    )

    if response.status_code == 200:
        data = response.json()
        produtos = data.get('produtos', [])
        print(f"[OK] Funciono! {len(produtos)} produtos recebidos")

        # Salvar
        Path('logs').mkdir(exist_ok=True)
        with open('logs/olist-products-cache.json', 'w', encoding='utf-8') as f:
            json.dump({
                'timestamp': datetime.now().isoformat(),
                'total': len(produtos),
                'produtos': produtos
            }, f, indent=2)

        print(f"[OK] Cache salvo com {len(produtos)} produtos!")
        exit(0)
    else:
        print(f"[Falhou] Status {response.status_code}")
except Exception as e:
    print(f"[Erro] {e}")

# Tentar metodo 2: Bearer token
print("\n[Teste 2] Usando Bearer token...")
try:
    response = requests.get(
        'https://api.tiny.com.br/api/v2/produtos.json',
        headers={'Authorization': f'Bearer {CLIENT_ID}'},
        params={'formato': 'json', 'limitar': 200},
        timeout=10
    )

    if response.status_code == 200:
        data = response.json()
        produtos = data.get('produtos', [])
        print(f"[OK] Funciono! {len(produtos)} produtos recebidos")
        exit(0)
    else:
        print(f"[Falhou] Status {response.status_code}")
except Exception as e:
    print(f"[Erro] {e}")

print("\n[ATENCAO] Nenhum metodo funcionou!")
print("Como voce usa essas credenciais normalmente?")
print("Preciso do endpoint exato ou do formato correto.")
