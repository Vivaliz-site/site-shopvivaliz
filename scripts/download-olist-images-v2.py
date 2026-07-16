#!/usr/bin/env python3
"""
ShopVivaliz - Download e Mapeamento de Imagens Olist/Tiny

Script que:
1. Faz login no ERP Olist
2. Consulta imagens via endpoint obterDadosProdutoParaGerenciadorImagens
3. Baixa imagens (srcReal ou src)
4. Gera CSV de auditoria (olist_imagens_baixadas.csv)
5. Gera CSV de mapeamento para upload (mapa_upload_shopvivaliz.csv)
"""

import os
import sys
import json
import csv
import requests
import hashlib
import logging
from pathlib import Path
from datetime import datetime
from urllib.parse import urljoin
import time

# Configuração de logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

class OlistImageDownloader:
    def __init__(self, email, password, output_dir="downloads_olist_imagens"):
        self.email = email
        self.password = password
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(exist_ok=True)

        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        })

        self.erp_base = "https://erp.olist.com"
        self.auth_logged_in = False

        self.audit_rows = []
        self.mapping_rows = []

    def login(self):
        """Autentica no ERP Olist"""
        logger.info(f"Autenticando como: {self.email}")

        login_url = f"{self.erp_base}/autenticacao/sessao"

        try:
            resp = self.session.post(
                login_url,
                data={
                    'usuario': self.email,
                    'senha': self.password
                },
                timeout=30,
                allow_redirects=True
            )

            if resp.status_code == 200 and 'dashboard' in resp.text.lower():
                logger.info("✓ Login bem-sucedido")
                self.auth_logged_in = True
                return True
            else:
                logger.error(f"✗ Login falhou. Status: {resp.status_code}")
                return False

        except Exception as e:
            logger.error(f"Erro ao fazer login: {e}")
            return False

    def get_product_images(self, product_id):
        """
        Consulta endpoint que alimenta o painel Gerenciar imagens
        Endpoint: /services/produtos.server/2/Produto%5CGerenciadorImagens/obterDadosProdutoParaGerenciadorImagens
        """

        endpoint = (
            f"{self.erp_base}/services/produtos.server/2/"
            f"Produto%5CGerenciadorImagens/"
            f"obterDadosProdutoParaGerenciadorImagens"
        )

        try:
            resp = self.session.post(
                endpoint,
                json={'id': product_id},
                timeout=30
            )

            if resp.status_code == 200:
                data = resp.json()
                images = data.get('imagens', []) or data.get('imagensInternas', [])
                return images
            else:
                logger.warning(f"Endpoint retornou {resp.status_code} para produto {product_id}")
                return []

        except Exception as e:
            logger.warning(f"Erro ao consultar imagens do produto {product_id}: {e}")
            return []

    def download_image(self, url, sku, product_id, position):
        """Baixa uma imagem e salva localmente"""

        if not url or not isinstance(url, str):
            return None, None, 0, None

        url = url.strip()
        if not url.startswith('http'):
            url = urljoin(self.erp_base, url)

        folder = self.output_dir / f"{sku}_{product_id}"
        folder.mkdir(exist_ok=True)

        try:
            resp = self.session.get(url, timeout=30, stream=True)

            if resp.status_code != 200:
                logger.warning(f"Status {resp.status_code} ao baixar: {url}")
                return url, None, resp.status_code, None

            content = resp.content
            file_hash = hashlib.sha256(content).hexdigest()[:8]

            ext = Path(url.split('?')[0]).suffix or '.jpg'
            filename = f"image_{position}_{file_hash}{ext}"
            filepath = folder / filename

            with open(filepath, 'wb') as f:
                f.write(content)

            logger.info(f"✓ Baixado: {filename} ({len(content)} bytes)")
            return url, str(filepath), resp.status_code, file_hash

        except Exception as e:
            logger.warning(f"Erro ao baixar {url}: {e}")
            return url, None, 0, None

    def calculate_file_hash(self, filepath):
        """Calcula hash SHA256 do arquivo"""
        try:
            with open(filepath, 'rb') as f:
                return hashlib.sha256(f.read()).hexdigest()
        except:
            return ""

    def process_products(self, csv_file):
        """Processa lista de produtos do CSV"""

        if not Path(csv_file).exists():
            logger.error(f"Arquivo não encontrado: {csv_file}")
            return False

        logger.info(f"Carregando produtos de: {csv_file}")

        products = []
        try:
            with open(csv_file, 'r', encoding='utf-8') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    products.append(row)
        except Exception as e:
            logger.error(f"Erro ao ler CSV: {e}")
            return False

        logger.info(f"Total de produtos a processar: {len(products)}")

        stats = {
            'total': len(products),
            'com_imagem': 0,
            'sem_imagem': 0,
            'total_imagens': 0,
            'erros': 0
        }

        for idx, product in enumerate(products, 1):
            sku = (product.get('sku') or '').strip()
            product_id = (product.get('olist_product_id') or '').strip()
            product_name = (product.get('nome_produto') or f'Produto {idx}').strip()

            if not product_id:
                logger.warning(f"Produto {idx}: ID não fornecido")
                stats['erros'] += 1
                continue

            logger.info(f"\n[{idx}/{len(products)}] Processando: {sku} | ID: {product_id}")

            # Consulta imagens
            images = self.get_product_images(product_id)

            if not images:
                logger.info(f"  → Nenhuma imagem encontrada")
                stats['sem_imagem'] += 1

                self.audit_rows.append({
                    'sku': sku,
                    'olist_product_id': product_id,
                    'nome_produto': product_name,
                    'image_position': '',
                    'image_file_name': '',
                    'image_download_url': '',
                    'local_path': '',
                    'file_size_bytes': '',
                    'http_status': '',
                    'status': 'no_images',
                    'error_message': '',
                    'raw_image_json': ''
                })
                continue

            stats['com_imagem'] += 1
            stats['total_imagens'] += len(images)

            # Processa cada imagem
            for pos, img in enumerate(images, 1):
                if not isinstance(img, dict):
                    continue

                url = img.get('srcReal') or img.get('src') or ''

                source_url, local_path, http_status, file_hash = self.download_image(
                    url, sku, product_id, pos
                )

                file_size = 0
                if local_path:
                    file_size = Path(local_path).stat().st_size

                # CSV Auditoria
                self.audit_rows.append({
                    'sku': sku,
                    'olist_product_id': product_id,
                    'nome_produto': product_name,
                    'image_position': pos,
                    'image_file_name': Path(local_path).name if local_path else '',
                    'image_download_url': source_url,
                    'local_path': local_path or '',
                    'file_size_bytes': file_size,
                    'http_status': http_status,
                    'status': 'ok' if local_path else 'error',
                    'error_message': '' if local_path else f'Download failed (HTTP {http_status})',
                    'raw_image_json': json.dumps(img, ensure_ascii=False)
                })

                # CSV Mapeamento
                if local_path:
                    site_upload_path = f"/public_html/dev/uploads/olist/{sku}/{Path(local_path).name}"
                    site_public_url = f"https://dev.shopvivaliz.com.br/uploads/olist/{sku}/{Path(local_path).name}"
                    full_hash = self.calculate_file_hash(local_path)

                    self.mapping_rows.append({
                        'sku': sku,
                        'olist_product_id': product_id,
                        'product_name': product_name,
                        'image_position': pos,
                        'is_primary': 1 if pos == 1 else 0,
                        'source_api_url': source_url,
                        'local_file': local_path,
                        'site_upload_path': site_upload_path,
                        'site_public_url': site_public_url,
                        'file_hash': full_hash,
                        'upload_status': 'pending',
                        'catalog_link_status': 'pending'
                    })

            time.sleep(0.5)  # Rate limiting

        # Salva CSVs
        self.save_audit_csv()
        self.save_mapping_csv()

        # Relatório final
        logger.info("\n" + "="*60)
        logger.info("RESUMO FINAL")
        logger.info("="*60)
        logger.info(f"Produtos processados: {stats['total']}")
        logger.info(f"Produtos com imagem: {stats['com_imagem']}")
        logger.info(f"Produtos sem imagem: {stats['sem_imagem']}")
        logger.info(f"Total de imagens baixadas: {stats['total_imagens']}")
        logger.info(f"Erros: {stats['erros']}")
        logger.info(f"\nArquivos salvos em: {self.output_dir.absolute()}")
        logger.info(f"  - olist_imagens_baixadas.csv")
        logger.info(f"  - mapa_upload_shopvivaliz.csv")

        return True

    def save_audit_csv(self):
        """Salva CSV de auditoria"""
        filepath = self.output_dir / 'olist_imagens_baixadas.csv'

        fieldnames = [
            'sku', 'olist_product_id', 'nome_produto', 'image_position',
            'image_file_name', 'image_download_url', 'local_path',
            'file_size_bytes', 'http_status', 'status', 'error_message',
            'raw_image_json'
        ]

        with open(filepath, 'w', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(self.audit_rows)

        logger.info(f"✓ Auditoria salva: {filepath}")

    def save_mapping_csv(self):
        """Salva CSV de mapeamento para upload"""
        filepath = self.output_dir / 'mapa_upload_shopvivaliz.csv'

        fieldnames = [
            'sku', 'olist_product_id', 'product_name', 'image_position',
            'is_primary', 'source_api_url', 'local_file', 'site_upload_path',
            'site_public_url', 'file_hash', 'upload_status', 'catalog_link_status'
        ]

        with open(filepath, 'w', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(self.mapping_rows)

        logger.info(f"✓ Mapeamento salvo: {filepath}")


def main():
    # Credenciais do ambiente (GitHub Secrets)
    email = os.getenv('OLIST_EMAIL') or os.getenv('OLIST_USER') or ''
    password = os.getenv('OLIST_PASSWORD') or ''

    csv_input = sys.argv[1] if len(sys.argv) > 1 else 'produtos_ids.csv'
    output_dir = sys.argv[2] if len(sys.argv) > 2 else 'downloads_olist_imagens'

    if not email or not password:
        logger.error("Credenciais não encontradas em variáveis de ambiente")
        logger.error("Configure OLIST_EMAIL e OLIST_PASSWORD")
        sys.exit(1)

    downloader = OlistImageDownloader(email, password, output_dir)

    if not downloader.login():
        sys.exit(1)

    if not downloader.process_products(csv_input):
        sys.exit(1)

    logger.info("\n✓ Processamento concluído com sucesso!")


if __name__ == '__main__':
    main()
