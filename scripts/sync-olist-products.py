#!/usr/bin/env python3
"""
Sincronizar produtos do Olist (TinyERP) para o catálogo ShopVivaliz
"""
import os
import json
import requests
from pathlib import Path
from datetime import datetime

print("=" * 60)
print("SINCRONIZACAO OLIST → SHOPVIVALIZ")
print("=" * 60)

# Configuração
TINY_API_KEY = os.getenv('TINY_ERP_API_KEY', '')
TINY_API_URL = 'https://api.tiny.com.br/api/v2'

if not TINY_API_KEY:
    print("[ERRO] TINY_ERP_API_KEY nao configurada!")
    print("\nConfigure em GitHub Secrets ou .env:")
    print("  TINY_ERP_API_KEY=<sua_chave_aqui>")
    exit(1)

print(f"\n[API] Conectando a TinyERP...")

# Buscar produtos da API TinyERP
try:
    url = f"{TINY_API_URL}/produtos.json"
    params = {
        'token': TINY_API_KEY,
        'formato': 'json'
    }

    print(f"[REQUEST] GET {url}")
    response = requests.get(url, params=params, timeout=30)

    if response.status_code != 200:
        print(f"[ERRO] Status {response.status_code}: {response.text[:200]}")
        exit(1)

    data = response.json()
    produtos = data.get('produtos', [])

    print(f"[OK] {len(produtos)} produtos recebidos da TinyERP")

    if len(produtos) == 0:
        print("[AVISO] Nenhum produto encontrado")
        exit(1)

    # Processar e salvar produtos
    produtos_processados = []
    for idx, prod in enumerate(produtos[:5], 1):  # Mostra primeiros 5
        p = {
            'id': prod.get('id'),
            'nome': prod.get('nome'),
            'descricao': prod.get('descricao', ''),
            'preco': float(prod.get('preco', 0)),
            'estoque': int(prod.get('estoque_atual', 0)),
            'sku': prod.get('sku'),
            'categoria': prod.get('categoria', 'Geral'),
            'imagem': prod.get('url_imagem', ''),
        }
        produtos_processados.append(p)
        print(f"  [{idx}] {p['nome']} - R$ {p['preco']:.2f} - Est: {p['estoque']}")

    # Salvar em JSON
    output_file = Path(__file__).parent.parent / 'logs' / 'olist-products.json'
    output_file.parent.mkdir(exist_ok=True)

    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump({
            'timestamp': datetime.now().isoformat(),
            'total': len(produtos),
            'produtos': produtos_processados,
            'source': 'TinyERP',
        }, f, indent=2, ensure_ascii=False)

    print(f"\n[SAVE] Produtos salvos em: {output_file}")
    print(f"\n[OK] Sincronizacao concluida!")
    print(f"     Total de produtos: {len(produtos)}")
    print(f"     Proxima etapa: Atualizar catalogo.php para usar esses dados")

except requests.exceptions.RequestException as e:
    print(f"[ERRO] Falha na requisição: {e}")
    exit(1)
except Exception as e:
    print(f"[ERRO] {e}")
    exit(1)
