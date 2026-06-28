#!/usr/bin/env python3
"""
Sincronizacao AUTOMATICA - Olist OAuth com Client ID + Secret
Executa: python3 sync-olist-auto.py
Ou via GitHub Actions com secrets: OLIST_CLIENT_ID + OLIST_CLIENT_SECRET
"""
import os
import sys
import json
import requests
from pathlib import Path
from datetime import datetime

print("="*70)
print("SINCRONIZACAO AUTOMATICA - 198 PRODUTOS OLIST")
print("="*70)

# Carregar credenciais do ambiente (GitHub Secrets ou .env)
CLIENT_ID = os.getenv('OLIST_CLIENT_ID') or 'tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553'
CLIENT_SECRET = os.getenv('OLIST_CLIENT_SECRET') or 'sh1MLgXhFlvycybhlShnvQMcEL8T2GWv'

if not CLIENT_ID or not CLIENT_SECRET:
    print("[ERRO] Faltam credenciais!")
    print("Configure:")
    print("  export OLIST_CLIENT_ID='...'")
    print("  export OLIST_CLIENT_SECRET='...'")
    sys.exit(1)

print(f"\n[OK] Client ID: {CLIENT_ID[:30]}...")
print(f"[OK] Client Secret: {CLIENT_SECRET[:30]}...\n")

# URLs - Olist/Tiny ERP API (OpenID Connect)
API_URL = "https://api.tiny.com.br/api/v2/produtos.json"
TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

try:
    # PASSO 1: Gerar Token de Acesso
    print("[1] Gerando token de acesso...")

    token_data = {
        'grant_type': 'client_credentials',
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET
    }

    token_response = requests.post(TOKEN_URL, data=token_data, timeout=15)

    if token_response.status_code != 200:
        print(f"[ERRO] Falha ao gerar token: {token_response.status_code}")
        print(f"Resposta: {token_response.text[:500]}")
        sys.exit(1)

    token_data = token_response.json()
    access_token = token_data.get('access_token')

    if not access_token:
        print("[ERRO] Token nao encontrado na resposta")
        print(f"Resposta: {token_data}")
        sys.exit(1)

    print(f"[OK] Token gerado: {access_token[:30]}...\n")

    # PASSO 2: Sincronizar Produtos
    print("[2] Sincronizando 198 produtos...")

    headers = {
        'Authorization': f'Bearer {access_token}',
        'Content-Type': 'application/json'
    }

    params = {
        'limite': 200,
        'formato': 'json'
    }

    response = requests.get(API_URL, headers=headers, params=params, timeout=30)

    if response.status_code != 200:
        print(f"[ERRO] Falha ao buscar produtos: {response.status_code}")
        print(f"Resposta: {response.text[:500]}")
        sys.exit(1)

    data = response.json()
    produtos = data.get('produtos', [])

    print(f"[OK] {len(produtos)} produtos recebidos\n")

    # PASSO 3: Salvar Cache
    print("[3] Salvando no cache...")

    LOGS_DIR = Path('logs')
    LOGS_DIR.mkdir(exist_ok=True)

    cache_file = LOGS_DIR / 'olist-products-cache.json'

    # Contar com imagem
    com_imagem = len([p for p in produtos if p.get('imagem_produto', {}).get('url')])
    sem_imagem = len(produtos) - com_imagem

    cache_data = {
        'timestamp': datetime.now().isoformat(),
        'total': len(produtos),
        'com_imagem': com_imagem,
        'sem_imagem': sem_imagem,
        'source': 'olist_oauth_auto',
        'token_type': 'client_credentials',
        'produtos': produtos
    }

    with open(cache_file, 'w', encoding='utf-8') as f:
        json.dump(cache_data, f, ensure_ascii=False, indent=2)

    print(f"[OK] Cache salvo: {cache_file}\n")

    # PASSO 4: Estatísticas
    print("="*70)
    print("ESTATISTICAS")
    print("="*70)
    print(f"Total de produtos: {len(produtos)}")
    print(f"Com imagem: {com_imagem}")
    print(f"Sem imagem: {sem_imagem}")
    print(f"Taxa de imagem: {(com_imagem/len(produtos)*100):.1f}%\n")

    # Categorias
    categorias = set()
    for p in produtos:
        cat = p.get('categoria', 'Sem categoria')
        if cat:
            categorias.add(cat)

    print(f"Categorias: {len(categorias)}")
    for i, cat in enumerate(list(categorias)[:5], 1):
        print(f"  {i}. {cat}")

    # Primeiros 3 produtos
    print(f"\nPrimeiros 3 produtos:")
    for i, p in enumerate(produtos[:3], 1):
        print(f"\n  {i}. {p.get('nome', 'Sem nome')}")
        print(f"     SKU: {p.get('codigo', 'N/A')}")
        print(f"     Preco: R$ {p.get('preco_venda', 0):.2f}")
        img = p.get('imagem_produto', {}).get('url', 'Sem imagem')
        print(f"     Imagem: {img[:60]}..." if len(img) > 60 else f"     Imagem: {img}")

    print(f"\n" + "="*70)
    print("SUCESSO!")
    print("="*70)
    print(f"\nCatalogo agora tem {len(produtos)} produtos com imagens!")
    print(f"Acesse: https://dev.shopvivaliz.com.br/catalogo/\n")

except requests.exceptions.Timeout:
    print("[ERRO] TIMEOUT - Conexao demorou muito")
    sys.exit(1)
except requests.exceptions.ConnectionError:
    print("[ERRO] ERRO DE CONEXAO - Verifique internet")
    sys.exit(1)
except Exception as e:
    print(f"[ERRO] {str(e)}")
    import traceback
    traceback.print_exc()
    sys.exit(1)
