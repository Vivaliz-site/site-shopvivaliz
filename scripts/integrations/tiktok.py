#!/usr/bin/env python3
"""
Integração TikTok Shop - Atualizar produtos automaticamente
"""

import os
import sys
import json
import requests
import argparse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from utils.tiktok_client import TikTokClient  # noqa: E402


class TikTokIntegration:
    def __init__(self):
        self.products_api_url = os.getenv('SHOPVIVALIZ_PRODUCTS_API_URL', '')
        try:
            self._client = TikTokClient()
        except KeyError as exc:
            self._client = None
            self._missing_env = str(exc).strip("'\"")

    def update_all_products(self):
        """Atualiza todos os produtos automaticamente"""
        print("\n[TIKTOK] Iniciando atualizacao automatica")
        print("="*70)

        products_to_update = self._load_products_from_api()
        if not products_to_update:
            products_to_update = self._load_products_from_performance()

        if not products_to_update:
            print("[INFO] Nenhum produto para atualizar")
            return

        updated_count = 0
        failed_count = 0

        for product in products_to_update:
            try:
                print(f"\n[TIKTOK] Atualizando: Produto {product['product_id']}")

                self._update_product_title(product['product_id'], product['title'])
                self._update_product_description(product['product_id'], product['description'])
                self._update_product_images(product['product_id'], product['images'])

                print(f"  [OK] Produto {product['product_id']} atualizado")
                updated_count += 1

            except Exception as e:
                print(f"  [ERRO] {str(e)}")
                failed_count += 1

        print("\n" + "="*70)
        print(f"Resultados: {updated_count} atualizados, {failed_count} falhados")

    def _load_products_from_performance(self):
        """Carrega produtos do CSV de performance"""
        try:
            import csv
            products = []
            with open('logs/performance.csv', 'r') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    if row.get('product_id'):
                        products.append({
                            'product_id': row['product_id'],
                            'name': f"Produto {row['product_id']}",
                            'title': f"Adorei! Produto {row['product_id']}",
                            'description': f"Confira este incrivel produto!",
                            'images': self._load_images_for_product(row['product_id'])
                        })
            return products
        except:
            return []

    def _load_products_from_api(self):
        """Carrega produtos diretamente de uma API quando disponível."""
        if not self.products_api_url:
            return []

        try:
            response = requests.get(self.products_api_url, timeout=30)
            response.raise_for_status()
            payload = response.json()

            items = payload.get('products') if isinstance(payload, dict) else payload
            products = []
            for row in items or []:
                if not row.get('product_id'):
                    continue
                products.append({
                    'product_id': str(row.get('product_id')),
                    'name': row.get('name', f"Produto {row.get('product_id')}"),
                    'title': row.get('title') or row.get('seo_title') or f"Adorei! Produto {row.get('product_id')}",
                    'description': row.get('description') or row.get('seo_description') or '',
                    'images': row.get('images') or [],
                })
            return products
        except Exception as exc:
            print(f"[AVISO] TikTok API de produtos indisponível: {exc}")
            return []

    def _load_images_for_product(self, product_id):
        """Carrega imagens geradas pela IA"""
        try:
            metadata_file = f'storage/ia_images/{product_id}_metadata.json'
            with open(metadata_file) as f:
                data = json.load(f)
                return [img['url'] for img in data.get('images', [])]
        except:
            return []

    def _update_product_title(self, product_id, title):
        """Atualiza titulo do produto via TikTokClient. Levanta excecao em
        falha real (nao mascara erro como sucesso) -- o chamador conta
        falhas."""
        if self._client is None:
            print(f"    [SIMULADO] Titulo: {product_id} (sem {self._missing_env})")
            return True
        self._client.update_product(str(product_id), title=title)
        print(f"    [ENVIADO] Titulo atualizado")
        return True

    def _update_product_description(self, product_id, description):
        """Atualiza descricao do produto via TikTokClient. Levanta excecao
        em falha real."""
        if self._client is None:
            print(f"    [SIMULADO] Descricao: {product_id} (sem {self._missing_env})")
            return True
        self._client.update_product(str(product_id), description=description)
        print(f"    [ENVIADO] Descricao atualizada")
        return True

    def _update_product_images(self, product_id, images):
        """Atualiza imagens do produto via TikTokClient. Levanta excecao
        em falha real."""
        if not images:
            return False

        if self._client is None:
            print(f"    [SIMULADO] Imagens: {len(images)} (sem {self._missing_env})")
            return True

        self._client.update_product(str(product_id), image_urls=images[:4])
        print(f"    [ENVIADO] Imagens: {len(images)} uploads")
        return True

# CLI
if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Integração TikTok')
    parser.add_argument('--update-all', action='store_true', help='Atualizar todos os produtos')
    args = parser.parse_args()

    tiktok = TikTokIntegration()

    if args.update_all:
        tiktok.update_all_products()
    else:
        print("[INFO] Use --update-all para atualizar produtos")
