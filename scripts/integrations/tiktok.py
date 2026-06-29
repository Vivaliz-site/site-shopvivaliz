#!/usr/bin/env python3
"""
Integração TikTok Shop - Atualizar produtos automaticamente
"""

import os
import json
import requests
import argparse
from datetime import datetime

class TikTokIntegration:
    def __init__(self):
        self.access_token = os.getenv('TIKTOK_ACCESS_TOKEN', '')
        self.shop_id = os.getenv('TIKTOK_SHOP_ID', '')
        self.api_base = 'https://open-api.tiktokglobalshop.com'

    def update_all_products(self):
        """Atualiza todos os produtos automaticamente"""
        print("\n[TIKTOK] Iniciando atualização automática")
        print("="*70)

        products_to_update = self._load_products_from_performance()

        if not products_to_update:
            print("[INFO] Nenhum produto para atualizar")
            return

        updated_count = 0
        failed_count = 0

        for product in products_to_update:
            try:
                print(f"\n[TIKTOK] Atualizando: {product['name']}")

                # Título emocional TikTok
                self._update_product_title(product['product_id'], product['title'])

                # Descrição com call-to-action
                self._update_product_description(product['product_id'], product['description'])

                # Imagens
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
                    if row.get('marketplace') == 'tiktok':
                        products.append({
                            'product_id': row['product_id'],
                            'name': f"Produto {row['product_id']}",
                            'title': f"Adorei! Produto {row['product_id']}",
                            'description': f"Confira este incrível produto!",
                            'images': self._load_images_for_product(row['product_id'])
                        })
            return products
        except:
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
        """Atualiza título do produto via API"""
        try:
            url = f"{self.api_base}/shop/api/product/update"
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Bearer {self.access_token}'
            }
            payload = {
                'product_id': int(product_id),
                'title': title
            }
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            if response.status_code == 200:
                print(f"    └─ Título: {title[:50]}... [ENVIADO]")
                return True
            else:
                print(f"    └─ Título: ERRO {response.status_code}")
                return False
        except Exception as e:
            print(f"    └─ Título: ERRO {str(e)}")
            return False

    def _update_product_description(self, product_id, description):
        """Atualiza descrição do produto via API"""
        try:
            url = f"{self.api_base}/shop/api/product/update"
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Bearer {self.access_token}'
            }
            payload = {
                'product_id': int(product_id),
                'description': description
            }
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            if response.status_code == 200:
                print(f"    └─ Descrição: {description[:50]}... [ENVIADO]")
                return True
            else:
                print(f"    └─ Descrição: ERRO {response.status_code}")
                return False
        except Exception as e:
            print(f"    └─ Descrição: ERRO {str(e)}")
            return False

    def _update_product_images(self, product_id, images):
        """Atualiza imagens do produto via API"""
        if not images:
            return False

        try:
            url = f"{self.api_base}/shop/api/product/update/images"
            headers = {
                'Content-Type': 'application/json',
                'Authorization': f'Bearer {self.access_token}'
            }
            payload = {
                'product_id': int(product_id),
                'images': images[:4]
            }
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            if response.status_code == 200:
                print(f"    └─ Imagens: {len(images)} uploads [ENVIADO]")
                return True
            else:
                print(f"    └─ Imagens: ERRO {response.status_code}")
                return False
        except Exception as e:
            print(f"    └─ Imagens: ERRO {str(e)}")
            return False

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
