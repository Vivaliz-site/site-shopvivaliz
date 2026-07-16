#!/usr/bin/env python3
"""
Sincronizacao TINY API - 198 produtos direto
Metodo: Basic Auth com Client ID + Secret
"""
import os
import json
import requests
from pathlib import Path
from datetime import datetime
import base64

print("="*70)
print("SINCRONIZACAO TINY API - 198 PRODUTOS")
print("="*70)

# Credenciais
TINY_CLIENT_ID = os.getenv("TINY_CLIENT_ID") or os.getenv("OLIST_CLIENT_ID") or ""
TINY_CLIENT_SECRET = os.getenv("TINY_CLIENT_SECRET") or os.getenv("OLIST_CLIENT_SECRET") or ""

if not TINY_CLIENT_ID or not TINY_CLIENT_SECRET:
    raise SystemExit("TINY_CLIENT_ID/TINY_CLIENT_SECRET ou OLIST_CLIENT_ID/OLIST_CLIENT_SECRET precisam estar configurados")

print(f"\n[OK] Client ID: {TINY_CLIENT_ID[:40]}...")
print(f"[OK] Client Secret: {TINY_CLIENT_SECRET[:30]}...\n")

# URLs
API_URL = "https://api.tiny.com.br/api/v2/produtos.json"

try:
    # METODO 1: Usar as credenciais como token direto (pode ser que CLIENT_ID seja o token)
    print("[TESTE 1] Usando CLIENT_ID como token direto...")

    response = requests.get(
        API_URL,
        params={
            'token': TINY_CLIENT_ID,
            'formato': 'json',
            'limitar': 200
        },
        timeout=15
    )

    print(f"Status: {response.status_code}")

    if response.status_code == 200:
        data = response.json()
        produtos = data.get('produtos', [])

        if produtos:
            print(f"\n[SUCESSO] {len(produtos)} produtos recebidos!\n")

            # Salvar cache
            Path('logs').mkdir(exist_ok=True)

            com_imagem = len([p for p in produtos if p.get('imagem_produto', {}).get('url')])
            sem_imagem = len(produtos) - com_imagem

            cache_data = {
                'timestamp': datetime.now().isoformat(),
                'total': len(produtos),
                'com_imagem': com_imagem,
                'sem_imagem': sem_imagem,
                'source': 'tiny_api_direct',
                'produtos': produtos
            }

            cache_file = Path('storage/cache/olist-products-cache.json')
            with open(cache_file, 'w', encoding='utf-8') as f:
                json.dump(cache_data, f, ensure_ascii=False, indent=2)

            print(f"[OK] Cache salvo: {cache_file}")
            print(f"\nESTATISTICAS:")
            print(f"  Total: {len(produtos)}")
            print(f"  Com imagem: {com_imagem}")
            print(f"  Sem imagem: {sem_imagem}")
            print(f"  Taxa: {(com_imagem/len(produtos)*100):.1f}%")

            # Primeiros 3
            print(f"\nPRIMEIROS 3 PRODUTOS:")
            for i, p in enumerate(produtos[:3], 1):
                print(f"\n  {i}. {p.get('nome', 'Sem nome')}")
                print(f"     SKU: {p.get('codigo', 'N/A')}")
                print(f"     Preco: R$ {p.get('preco_venda', 0):.2f}")
                img = p.get('imagem_produto', {}).get('url', 'Sem imagem')
                print(f"     Imagem: {img[:55]}..." if len(str(img)) > 55 else f"     Imagem: {img}")

            print(f"\n" + "="*70)
            print("SINCRONIZACAO CONCLUIDA COM SUCESSO!")
            print("="*70)
            print(f"\nCatalogo agora tem {len(produtos)} produtos!")
            print(f"Acesse: https://dev.shopvivaliz.com.br/catalogo/")
            exit(0)
        else:
            print("[AVISO] Resposta vazia, sem produtos")
            print(f"Resposta: {data}")
    else:
        print(f"[FALHOU] Status {response.status_code}")
        print(f"Resposta: {response.text[:300]}")

    # METODO 2: Basic Auth
    print("\n[TESTE 2] Usando Basic Auth...")

    credentials = base64.b64encode(f"{TINY_CLIENT_ID}:{TINY_CLIENT_SECRET}".encode()).decode()

    response = requests.get(
        API_URL,
        headers={'Authorization': f'Basic {credentials}'},
        params={'formato': 'json', 'limitar': 200},
        timeout=15
    )

    print(f"Status: {response.status_code}")

    if response.status_code == 200:
        data = response.json()
        produtos = data.get('produtos', [])

        if produtos:
            print(f"[SUCESSO] {len(produtos)} produtos recebidos!")
            exit(0)
    else:
        print(f"[FALHOU] Status {response.status_code}")

    print("\n[ERRO] Nenhum metodo funcionou!")
    print("Qual é a forma correta de autenticar com essas credenciais?")

except Exception as e:
    print(f"\n[ERRO] {str(e)}")
    import traceback
    traceback.print_exc()
    exit(1)
