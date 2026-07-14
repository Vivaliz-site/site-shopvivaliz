#!/usr/bin/env python3
"""
Daemon: Sincroniza produtos ERP AO VIVO a cada 5 minutos
Mantém storage/products-cache-ativos.json sempre atualizado
"""

import json
import time
import urllib.request
from pathlib import Path
from datetime import datetime

def get_token():
    """Carregar token de .env"""
    env_file = Path(".env")
    for line in env_file.read_text().split('\n'):
        if line.startswith('OLIST_ACCESS_TOKEN='):
            return line.split('=', 1)[1].strip()
    return None

def fetch_products_active():
    """Buscar TODOS os produtos ATIVOS do ERP"""
    token = get_token()
    if not token:
        print("[!] Token não encontrado!")
        return []

    all_products = []
    offset = 0
    limit = 100

    while True:
        url = f"https://api.tiny.com.br/public-api/v3/produtos?limit={limit}&offset={offset}"

        try:
            req = urllib.request.Request(url)
            req.add_header('Authorization', f'Bearer {token}')

            with urllib.request.urlopen(req, timeout=30) as response:
                data = json.loads(response.read())
        except Exception as e:
            print(f"[!] Erro na busca: {e}")
            break

        if 'itens' not in data or not data['itens']:
            break

        # Filtrar APENAS ativos (situacao == 'A')
        for item in data['itens']:
            if item.get('situacao') == 'A':
                all_products.append(item)

        print(f"[+] Offset {offset}: {len([i for i in data['itens'] if i.get('situacao') == 'A'])} ativos")

        if len(data['itens']) < limit:
            break

        offset += limit
        time.sleep(1)  # Respeitar rate limit

    return all_products

def save_products(products):
    """Salvar em arquivo JSON"""
    output = {
        'total': len(products),
        'timestamp': datetime.now().isoformat(),
        'itens': products
    }

    output_file = Path('storage/products-cache-ativos.json')
    output_file.parent.mkdir(parents=True, exist_ok=True)

    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(output, f, ensure_ascii=False, indent=2)

    return output_file

def main():
    """Loop principal"""
    print("[*] Daemon de Sincronização de Produtos")
    print("[*] Intervalo: 5 minutos")
    print("[*] Pressione Ctrl+C para parar\n")

    iteration = 0
    while True:
        iteration += 1
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

        print(f"\n[{timestamp}] [Iteração {iteration}] Sincronizando...")

        products = fetch_products_active()

        if products:
            output_file = save_products(products)
            print(f"[+] {len(products)} produtos ativos salvos em {output_file}")
        else:
            print("[!] Nenhum produto encontrado")

        print(f"[*] Aguardando 5 minutos até próxima sincronização...")
        time.sleep(300)  # 5 minutos

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n[*] Daemon parado")
