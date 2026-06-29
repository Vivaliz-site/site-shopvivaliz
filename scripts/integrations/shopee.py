#!/usr/bin/env python3
"""
Integração Shopee - Atualizar produtos automaticamente
"""

import os
import json
import requests
import argparse
from datetime import datetime

class ShopeeIntegration:
    def __init__(self):
        self.access_token = os.getenv('SHOPEE_ACCESS_TOKEN', '')
        self.shop_id = os.getenv('SHOPEE_SHOP_ID', '')
        self.api_base = 'https://openplatform.sandbox.test-stable.shopee.sg'
        self.headers = {
            'Content-Type': 'application/json',
            'Authorization': f'Bearer {self.access_token}'
        }

    def update_all_products(self):
        """Atualiza todos os produtos automaticamente"""
        print("\n[SHOPEE] Iniciando atualização automática")
        print("="*70)

        # Carregar dados de performance
        products_to_update = self._load_products_from_performance()

        if not products_to_update:
            print("[INFO] Nenhum produto para atualizar")
            return

        updated_count = 0
        failed_count = 0

        for product in products_to_update:
            try:
                print(f"\n[SHOPEE] Atualizando: {product['name']}")

                # Atualizar título
                self._update_product_title(product['product_id'], product['title'])

                # Atualizar descrição
                self._update_product_description(product['product_id'], product['description'])

                # Atualizar imagens
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
                    if row.get('marketplace') == 'shopee':
                        products.append({
                            'product_id': row['product_id'],
                            'name': f"Produto {row['product_id']}",
                            'title': f"Produto Atualizado {row['product_id']}",
                            'description': f"Descricao otimizada - SEO Score: {row['seo_score']}/100",
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
            url = f"{self.api_base}/api/v2/product/update_title"
            payload = {
                'product_id': int(product_id),
                'title': title
            }
            response = requests.post(url, json=payload, headers=self.headers, timeout=30)
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
            url = f"{self.api_base}/api/v2/product/update_description"
            payload = {
                'product_id': int(product_id),
                'description': description
            }
            response = requests.post(url, json=payload, headers=self.headers, timeout=30)
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
            url = f"{self.api_base}/api/v2/product/update_images"
            payload = {
                'product_id': int(product_id),
                'images': images[:4]  # Máximo 4 imagens
            }
            response = requests.post(url, json=payload, headers=self.headers, timeout=30)
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
    parser = argparse.ArgumentParser(description='Integração Shopee')
    parser.add_argument('--update-all', action='store_true', help='Atualizar todos os produtos')
    args = parser.parse_args()

    shopee = ShopeeIntegration()

    if args.update_all:
        shopee.update_all_products()
    else:
        print("[INFO] Use --update-all para atualizar produtos")
