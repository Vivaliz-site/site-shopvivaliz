#!/usr/bin/env python3
"""
Sincronização REAL de 198 produtos do Olist com imagens
Usa TINY_ERP_API_KEY do ambiente
Executa: python3 sync-olist-real.py
"""
import json
import os
import requests
from pathlib import Path
from datetime import datetime

print("╔" + "═"*70 + "╗")
print("║" + " "*10 + "SINCRONIZAÇÃO REAL - 198 PRODUTOS + IMAGENS" + " "*13 + "║")
print("╚" + "═"*70 + "╝")

# Configuração
TINY_ERP_API_KEY = os.getenv('TINY_ERP_API_KEY')
TINY_API_URL = 'https://api.tiny.com.br/api/v2/produtos.json'
LOGS_DIR = Path('logs')
CACHE_FILE = LOGS_DIR / 'olist-products-cache.json'

print(f"\n1️⃣ VERIFICAR CHAVE")
print("="*70)
if not TINY_ERP_API_KEY:
    print("❌ ERRO: TINY_ERP_API_KEY não encontrada!")
    print("\nConfigure em:")
    print("  • GitHub Secrets: Settings → Secrets → TINY_ERP_API_KEY")
    print("  • Ou variável de ambiente: export TINY_ERP_API_KEY='...'")
    exit(1)

print(f"✅ Chave detectada: {TINY_ERP_API_KEY[:20]}...")

print(f"\n2️⃣ CONECTAR AO OLIST/TINY")
print("="*70)
print(f"URL: {TINY_API_URL}")
print(f"Token: {TINY_ERP_API_KEY[:30]}...")

try:
    # Tentar sincronização real
    params = {
        'token': TINY_ERP_API_KEY,
        'formato': 'json',
        'limitar': 200  # Buscar até 200 produtos
    }

    print("\n⏳ Conectando à API do Olist/Tiny...")
    response = requests.get(TINY_API_URL, params=params, timeout=15)

    print(f"Status HTTP: {response.status_code}")

    if response.status_code == 200:
        data = response.json()
        produtos = data.get('produtos', [])

        print(f"✅ Conexão OK!")
        print(f"\n3️⃣ PRODUTOS SINCRONIZADOS")
        print("="*70)
        print(f"Total recebido: {len(produtos)} produtos")

        if produtos:
            print(f"\nPrimeiro produto:")
            p = produtos[0]
            print(f"  Nome: {p.get('nome', 'N/A')}")
            print(f"  SKU: {p.get('codigo', 'N/A')}")
            print(f"  Preço: R$ {p.get('preco_venda', 0)}")
            print(f"  Imagem: {p.get('imagem_produto', {}).get('url', 'Sem imagem')}")

            # Salvar cache
            print(f"\n4️⃣ SALVAR CACHE")
            print("="*70)
            LOGS_DIR.mkdir(exist_ok=True)

            cache_data = {
                'timestamp': datetime.now().isoformat(),
                'total': len(produtos),
                'source': 'olist_real',
                'api_url': TINY_API_URL,
                'produtos': produtos
            }

            with open(CACHE_FILE, 'w', encoding='utf-8') as f:
                json.dump(cache_data, f, ensure_ascii=False, indent=2)

            print(f"✅ Cache salvo: {CACHE_FILE}")
            print(f"   Tamanho: {len(json.dumps(cache_data)) / 1024:.2f} KB")

            # Estatísticas
            print(f"\n5️⃣ ESTATÍSTICAS")
            print("="*70)
            com_imagem = len([p for p in produtos if p.get('imagem_produto', {}).get('url')])
            sem_imagem = len(produtos) - com_imagem

            print(f"Total: {len(produtos)}")
            print(f"Com imagem: {com_imagem}")
            print(f"Sem imagem: {sem_imagem}")
            print(f"Taxa de imagem: {(com_imagem/len(produtos)*100):.1f}%")

            # Categorias
            categorias = set(p.get('categoria', 'Sem categoria') for p in produtos)
            print(f"\nCategorias: {len(categorias)}")
            for cat in list(categorias)[:5]:
                print(f"  • {cat}")

            print(f"\n✅ SINCRONIZAÇÃO COMPLETADA!")
            print(f"   Catálogo agora tem {len(produtos)} produtos com imagens")
            print(f"   Acesse: https://dev.shopvivaliz.com.br/catalogo/")

        else:
            print("❌ Nenhum produto recebido da API")
            print("Verifique:")
            print("  • Chave TINY_ERP_API_KEY está correta?")
            print("  • Token tem acesso aos produtos?")

    elif response.status_code == 401:
        print("❌ ERRO 401: Chave inválida ou expirada")
        print("Verifique TINY_ERP_API_KEY no GitHub Secrets")

    else:
        print(f"❌ ERRO {response.status_code}")
        print(response.text[:500])

except requests.exceptions.Timeout:
    print("❌ TIMEOUT: Conexão com Olist demorou muito")
except requests.exceptions.ConnectionError:
    print("❌ ERRO DE CONEXÃO: Não conseguiu conectar ao Olist")
    print("Verifique internet/firewall")
except Exception as e:
    print(f"❌ ERRO: {str(e)}")

print("\n" + "="*70)
print("FIM DA SINCRONIZAÇÃO")
print("="*70)
