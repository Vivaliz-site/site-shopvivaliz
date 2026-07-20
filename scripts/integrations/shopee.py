#!/usr/bin/env python3
"""
Integração Shopee - Atualizar produtos automaticamente
"""

import os
import sys
import json
import argparse
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'utils'))


class ShopeeIntegration:
    def __init__(self):
        # A implementacao anterior fazia chamadas HTTP direto pra endpoints
        # que nao existem na API real da Shopee (/api/v2/product/update_title,
        # update_description, update_images -- o endpoint real e um unico
        # /product/update_item), sem nunca assinar a requisicao (a API da
        # Shopee exige HMAC-SHA256 em toda chamada, ver docs/SHOPEE-OPEN-API-V2.md).
        # Todo "except" engolia o erro e imprimia "[ENVIADO] ... (simulado)"
        # como se fosse sucesso, mesmo com credenciais reais e erro de verdade.
        # Delega pro cliente real e ja testado em scripts/utils/shopee_client.py.
        self._client = None
        self._client_error = None
        try:
            from shopee_client import ShopeeClient
            self._client = ShopeeClient()
        except Exception as exc:
            self._client_error = str(exc)

    def update_all_products(self):
        """Atualiza todos os produtos automaticamente"""
        print("\n[SHOPEE] Iniciando atualização automática")
        print("=" * 70)

        if self._client is None:
            print(f"[ERRO] Cliente Shopee nao inicializado: {self._client_error}")
            print("[ERRO] Nenhuma atualizacao foi feita (sem simulacao de sucesso).")
            return

        products_to_update = self._load_products_from_api()
        if not products_to_update:
            products_to_update = self._load_products_from_performance()

        if not products_to_update:
            print("[INFO] Nenhum produto para atualizar")
            return

        updated_count = 0
        failed_count = 0

        for product in products_to_update:
            product_id = product.get('product_id')
            try:
                print(f"\n[SHOPEE] Atualizando: {product['name']}")
                self._client.update_product(
                    int(product_id),
                    title=product.get('title') or None,
                    description=product.get('description') or None,
                )
                images = product.get('images') or []
                if images:
                    image_ids = []
                    for image_ref in images[:4]:
                        # Aceita tanto URL local do produto (baixa e sobe)
                        # quanto path local ja existente em disco.
                        if str(image_ref).startswith('http'):
                            image_ids.append(self._upload_from_url(image_ref))
                        else:
                            image_ids.append(self._client.upload_image(image_ref))
                    self._client.update_product(int(product_id), image_ids=image_ids)
                print(f"  [OK] Produto {product_id} atualizado")
                updated_count += 1
            except Exception as e:
                print(f"  [ERRO] {product_id}: {e}")
                failed_count += 1

        print("\n" + "=" * 70)
        print(f"Resultados: {updated_count} atualizados, {failed_count} falhados")

    def _upload_from_url(self, url: str) -> str:
        import requests
        import tempfile

        resp = requests.get(url, timeout=30)
        resp.raise_for_status()
        suffix = Path(url).suffix or '.jpg'
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(resp.content)
            tmp_path = tmp.name
        try:
            return self._client.upload_image(tmp_path)
        finally:
            os.unlink(tmp_path)

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
        except Exception:
            return []

    def _load_products_from_api(self):
        """Carrega produtos diretamente de uma API quando disponível."""
        products_api_url = os.getenv('SHOPVIVALIZ_PRODUCTS_API_URL', '')
        if not products_api_url:
            return []

        try:
            import requests
            response = requests.get(products_api_url, timeout=30)
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
                    'title': row.get('title') or row.get('seo_title') or f"Produto Atualizado {row.get('product_id')}",
                    'description': row.get('description') or row.get('seo_description') or '',
                    'images': row.get('images') or [],
                })
            return products
        except Exception as exc:
            print(f"[AVISO] Shopee API de produtos indisponível: {exc}")
            return []

    def _load_images_for_product(self, product_id):
        """Carrega imagens geradas pela IA"""
        try:
            metadata_file = f'storage/ia_images/{product_id}_metadata.json'
            with open(metadata_file) as f:
                data = json.load(f)
                return [img['url'] for img in data.get('images', [])]
        except Exception:
            return []


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
