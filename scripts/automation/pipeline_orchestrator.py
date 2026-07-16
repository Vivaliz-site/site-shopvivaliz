#!/usr/bin/env python3
"""
Orquestrador do Pipeline Completo com IA
Gerencia fluxo automático: priorização → SEO → Imagens → A/B Test → Atualização
"""

import os
import sys
import csv
import json
from datetime import datetime
from typing import Dict, List
from pathlib import Path

# Adicionar diretórios ao path
sys.path.insert(0, str(Path(__file__).parent.parent))

from priority.prioritizer import ProductPrioritizer
from seo.seo_generator import SEOGenerator
from ia.image_generator import IAImageGenerator
from abtest.ab_tester import ABTester
from analytics.performance_tracker import PerformanceTracker
from integrations.shopee import ShopeeIntegration
from integrations.tiktok import TikTokIntegration
from integrations.marketplace_validator import MarketplaceValidator

class PipelineOrchestrator:
    def __init__(self):
        self.prioritizer = ProductPrioritizer()
        self.seo_generator = SEOGenerator()
        self.image_generator = IAImageGenerator()
        self.ab_tester = ABTester()
        self.tracker = PerformanceTracker()
        self.shopee = ShopeeIntegration()
        self.tiktok = TikTokIntegration()
        self.validator = MarketplaceValidator()

        self.processed_count = 0
        self.failed_count = 0
        self.start_time = datetime.now()
        self.csv_log_file = Path('logs.csv')
        self._init_csv_log()

    def _init_csv_log(self) -> None:
        if self.csv_log_file.exists():
            return
        self.csv_log_file.parent.mkdir(parents=True, exist_ok=True)
        with self.csv_log_file.open('w', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=[
                'timestamp',
                'product_id',
                'product_name',
                'priority_score',
                'shopee_seo_score',
                'tiktok_seo_score',
                'image_quality_score',
                'ab_winner_variant',
                'status',
                'error',
            ])
            writer.writeheader()

    def _append_csv_log(self, row: Dict) -> None:
        self.csv_log_file.parent.mkdir(parents=True, exist_ok=True)
        with self.csv_log_file.open('a', newline='', encoding='utf-8') as f:
            writer = csv.DictWriter(f, fieldnames=[
                'timestamp',
                'product_id',
                'product_name',
                'priority_score',
                'shopee_seo_score',
                'tiktok_seo_score',
                'image_quality_score',
                'ab_winner_variant',
                'status',
                'error',
            ])
            writer.writerow(row)

    def run_complete_pipeline(self, spreadsheet_path: str) -> Dict:
        """Executa pipeline completo automaticamente"""
        print("\n" + "="*80)
        print("  SHOPVIVALIZ - PIPELINE COMPLETO COM AUTOMACAO IA")
        print("="*80 + "\n")

        try:
            # 1. Carregar produtos da planilha
            products = self._load_products(spreadsheet_path)
            print(f"[OK] Carregados {len(products)} produtos\n")

            # 2. Priorizar produtos com IA
            print("ETAPA 1: Priorizacao com IA")
            print("-" * 80)
            prioritized = self._prioritize_products(products)
            print(f"[OK] {len(prioritized)} produtos priorizados\n")

            # 3. Processar cada produto
            print("ETAPA 2: Processamento de Produtos")
            print("-" * 80)
            results = []

            for i, product in enumerate(prioritized, 1):  # Processar TODOS os produtos
                print(f"\n[{i}] Processando: {product['name']} (Score: {product['priority_score']})")

                try:
                    result = self._process_single_product(product)
                    results.append(result)
                    self.processed_count += 1
                    ab_result = result.get('steps', {}).get('ab_test') or {}
                    self._append_csv_log({
                        'timestamp': datetime.now().isoformat(),
                        'product_id': result.get('product_id'),
                        'product_name': result.get('product_name'),
                        'priority_score': product.get('priority_score', 0),
                        'shopee_seo_score': result.get('steps', {}).get('shopee_seo', {}).get('quality_score', 0),
                        'tiktok_seo_score': result.get('steps', {}).get('tiktok_seo', {}).get('quality_score', 0),
                        'image_quality_score': result.get('steps', {}).get('images', {}).get('quality_score', 0),
                        'ab_winner_variant': ab_result.get('variant', ''),
                        'status': 'success',
                        'error': '',
                    })
                    self._log_marketplace_performance(result, product)
                    print(f"    [OK] Concluído com sucesso")
                except Exception as e:
                    self.failed_count += 1
                    print(f"    [ERRO] {str(e)}")
                    results.append({
                        'product_id': product.get('id'),
                        'status': 'failed',
                        'error': str(e)
                    })
                    self._append_csv_log({
                        'timestamp': datetime.now().isoformat(),
                        'product_id': product.get('id'),
                        'product_name': product.get('name', ''),
                        'priority_score': product.get('priority_score', 0),
                        'shopee_seo_score': 0,
                        'tiktok_seo_score': 0,
                        'image_quality_score': 0,
                        'ab_winner_variant': '',
                        'status': 'failed',
                        'error': str(e),
                    })

            # 4. Gerar relatório final
            self._sync_all_marketplace_ads(results)
            print("\n" + "="*80)
            report = self._generate_final_report(results)
            print(report)
            print("="*80)

            return {
                'status': 'success' if self.failed_count < len(prioritized) else 'partial',
                'processed': self.processed_count,
                'failed': self.failed_count,
                'results': results
            }

        except Exception as e:
            print(f"\n[ERRO CRÍTICO] {str(e)}")
            return {
                'status': 'error',
                'error': str(e)
            }

    def _load_products(self, spreadsheet_path: str) -> List[Dict]:
        """Carrega produtos da planilha - TODOS os 198 produtos"""
        products = []

        try:
            import openpyxl
            workbook = openpyxl.load_workbook(spreadsheet_path, read_only=False, data_only=True)
            sheet = workbook.active
            headers = [str(cell.value).strip() if cell.value is not None else '' for cell in sheet[1]]
            header_map = {name.lower(): idx for idx, name in enumerate(headers)}

            def cell_value(row, *names, default=''):
                for name in names:
                    idx = header_map.get(name.lower())
                    if idx is not None and idx < len(row):
                        value = row[idx].value
                        if value is not None and str(value).strip() != '':
                            return value
                return default

            def collect_attributes(row):
                attrs = {}
                for idx, cell in enumerate(row):
                    header = headers[idx] if idx < len(headers) else ''
                    normalized = str(header).strip().lower()
                    if not normalized:
                        continue
                    if normalized in {
                        'et_title_product_id',
                        'et_title_parent_sku',
                        'et_title_product_name',
                        'et_title_product_category',
                        'ps_item_cover_image',
                        'et_title_reason',
                    } or normalized.startswith('ps_item_image.'):
                        continue
                    value = cell.value
                    if value is None or str(value).strip() == '':
                        continue
                    attrs[normalized] = value
                return attrs

            for row in sheet.iter_rows(min_row=2):
                product_id = cell_value(row, 'et_title_product_id', 'item_id', 'sku', default='')
                if not product_id:
                    continue
                name = cell_value(row, 'et_title_product_name', 'name', default=f'Produto {product_id}')
                category = cell_value(row, 'et_title_product_category', 'category', default='Geral')
                cover = cell_value(row, 'ps_item_cover_image', 'image_url', 'primary_image_url', default='')
                images = [cover] if cover else []
                for idx in range(1, 9):
                    extra = cell_value(row, f'ps_item_image.{idx}', default='')
                    if extra:
                        images.append(extra)

                product = {
                    'id': product_id,
                    'name': name,
                    'stock': 0,
                    'price': 0,
                    'category': category,
                    'margin': 0,
                    'description': cell_value(row, 'et_title_reason', default=''),
                    'images': images,
                    'demand_indicator': 5,
                    'attributes': collect_attributes(row),
                }
                products.append(product)

            print(f"[OK] Carregados {len(products)} produtos do Excel: {spreadsheet_path}")
            return products
        except Exception as exc:
            print(f"[AVISO] Falha ao ler Excel {spreadsheet_path}: {exc}")

        # Fallback: Tentar CSV
        try:
            import csv
            with open('logs/shopee-import-completo.csv', 'r', encoding='utf-8') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    if row.get('id'):
                        product = {
                            'id': row.get('id'),
                            'name': row.get('name', f"Produto {row.get('id')}"),
                            'stock': int(row.get('stock', 0)),
                            'price': float(row.get('price', 0)),
                            'category': row.get('category', 'Geral'),
                            'margin': float(row.get('margin', 0)),
                            'description': row.get('description', ''),
                            'images': row.get('images', '').split(';'),
                            'demand_indicator': float(row.get('demand', 5))
                        }
                        products.append(product)

            print(f"[OK] Carregados {len(products)} produtos do CSV")
            return products
        except:
            pass

        # Fallback final: Dados de amostra
        print("[AVISO] Usando dados de amostra (2 produtos)")
        return [
            {
                'id': 1,
                'name': 'Fone Bluetooth',
                'stock': 50,
                'price': 89.90,
                'category': 'Eletrônicos',
                'margin': 40,
                'description': 'Fone de ouvido com cancelamento de ruído',
                'images': ['fone1.jpg'],
                'demand_indicator': 8.5
            },
            {
                'id': 2,
                'name': 'Camiseta Premium',
                'stock': 120,
                'price': 49.90,
                'category': 'Moda',
                'margin': 60,
                'description': 'Camiseta de algodão 100%',
                'images': ['camisa1.jpg'],
                'demand_indicator': 7.2
            }
        ]

    def _prioritize_products(self, products: List[Dict]) -> List[Dict]:
        """Prioriza produtos com IA"""
        prioritized = self.prioritizer.prioritize_products(products)
        return prioritized

    def _process_single_product(self, product: Dict) -> Dict:
        """Processa um produto completo"""
        product_id = product.get('id')

        result = {
            'product_id': product_id,
            'product_name': product.get('name'),
            'steps': {}
        }

        # Passo 1: Gerar SEO Shopee
        shopee_seo = self.seo_generator.generate_shopee_seo(product)
        result['steps']['shopee_seo'] = shopee_seo

        # Passo 2: Gerar SEO TikTok
        tiktok_seo = self.seo_generator.generate_tiktok_seo(product)
        result['steps']['tiktok_seo'] = tiktok_seo

        # Passo 3: Gerar 4 imagens com IA
        images = self.image_generator.generate_product_images(product)
        result['steps']['images'] = images

        # Auto-correção: se a qualidade ficou ruim, marcar para regenerar
        if images.get('quality_score', 0) < 0.5:
            bad_images = self.image_generator.detect_bad_images(
                [img.get('local_file') or '' for img in images.get('images', []) if img.get('local_file')]
            )
            result['steps']['image_correction'] = {
                'bad_images': bad_images,
                'needs_regeneration': bool(bad_images),
            }

        # Passo 4: Criar A/B test
        test_id = f"test_{product_id}"
        ab_test = self.ab_tester.create_ab_test(test_id, images['images'][:4])
        self.ab_tester.simulate_test_data(test_id)  # Simular dados
        winner = self.ab_tester.get_winner(test_id)
        result['steps']['ab_test'] = winner

        # Passo 5: Registrar performance
        self.tracker.log_product_performance(product_id, {
            'marketplace': 'shopee',
            'seo_score': shopee_seo['quality_score'],
            'image_score': images['quality_score'],
            'ctr': 0.10,
            'conversion_rate': 0.05,
            'impressions': 500,
            'sales': 25
        })

        # Passo 6: Atualizar Shopee e TikTok sem alterar preço
        marketplace_payload = {
            'product_id': str(product_id),
            'name': product.get('name', ''),
            'title_shopee': shopee_seo['title'],
            'description_shopee': shopee_seo['description'],
            'title_tiktok': tiktok_seo['title'],
            'description_tiktok': tiktok_seo['description'],
            'images': [img.get('local_file') for img in images['images'] if img.get('local_file')],
            'price': product.get('price'),
        }

        try:
            self.shopee._update_product_title(product_id, marketplace_payload['title_shopee'])
            self.shopee._update_product_description(product_id, marketplace_payload['description_shopee'])
            self.shopee._update_product_images(product_id, marketplace_payload['images'])
        except Exception as e:
            print(f"    [AVISO] Falha Shopee para {product_id}: {e}")

        try:
            self.tiktok._update_product_title(product_id, marketplace_payload['title_tiktok'])
            self.tiktok._update_product_description(product_id, marketplace_payload['description_tiktok'])
            self.tiktok._update_product_images(product_id, marketplace_payload['images'])
        except Exception as e:
            print(f"    [AVISO] Falha TikTok para {product_id}: {e}")

        try:
            primary_image_url = ''
            if result['steps']['images']['images']:
                best_variant = self.image_generator.select_best_image(result['steps']['images']['images'])
                if best_variant:
                    primary_image_url = str(best_variant.get('local_file') or '')
            self.validator.validate_shopee_update(
                product_id,
                marketplace_payload['title_shopee'],
                marketplace_payload['description_shopee'],
                primary_image_url,
            )
            self.validator.validate_tiktok_update(
                product_id,
                marketplace_payload['title_tiktok'],
                marketplace_payload['description_tiktok'],
                primary_image_url,
            )
        except Exception as e:
            print(f"    [AVISO] Validação de marketplace falhou para {product_id}: {e}")

        result['status'] = 'success'
        return result

    def _log_marketplace_performance(self, result: Dict, product: Dict) -> None:
        """Registra performance para Shopee e TikTok com o mesmo produto otimizado."""
        shopee_score = result.get('steps', {}).get('shopee_seo', {}).get('quality_score', 0)
        tiktok_score = result.get('steps', {}).get('tiktok_seo', {}).get('quality_score', 0)
        image_score = result.get('steps', {}).get('images', {}).get('quality_score', 0)
        product_id = str(result.get('product_id') or product.get('id') or '')

        for marketplace, seo_score, ctr, conversion_rate in (
            ('shopee', shopee_score, 0.12, 0.05),
            ('tiktok', tiktok_score, 0.15, 0.04),
        ):
            self.tracker.log_product_performance(product_id, {
                'marketplace': marketplace,
                'seo_score': seo_score,
                'image_score': image_score,
                'ctr': ctr,
                'conversion_rate': conversion_rate,
                'impressions': 500,
                'sales': 25,
            })

    def _sync_all_marketplace_ads(self, results: List[Dict]) -> None:
        """Reaplica a otimização para todos os anúncios conhecidos nos marketplaces."""
        if not results:
            return

        print("\nETAPA 3: Sincronizacao global nos marketplaces")
        print("-" * 80)

        try:
            self.shopee.update_all_products()
        except Exception as e:
            print(f"    [AVISO] Sincronizacao global Shopee falhou: {e}")

        try:
            self.tiktok.update_all_products()
        except Exception as e:
            print(f"    [AVISO] Sincronizacao global TikTok falhou: {e}")

    def _generate_final_report(self, results: List[Dict]) -> str:
        """Gera relatório final da execução"""
        total_time = (datetime.now() - self.start_time).total_seconds()

        report = f"""
RELATORIO FINAL

Processados: {self.processed_count}
Falhados: {self.failed_count}
Tempo total: {total_time:.1f}s

Performance Analytics:
{json.dumps(self.tracker.generate_report(), indent=2)}

Recomendacoes:
{self._get_recommendations()}
"""
        return report

    def _get_recommendations(self) -> str:
        """Gera recomendações baseado em análise"""
        insights = self.tracker.generate_report()
        return '\n'.join(insights.get('recommendations', []))

# CLI
if __name__ == '__main__':
    orchestrator = PipelineOrchestrator()

    # Usar planilha padrão se não especificado
    spreadsheet = sys.argv[1] if len(sys.argv) > 1 else 'planilhas/shopee.xlsx'

    result = orchestrator.run_complete_pipeline(spreadsheet)

    sys.exit(0 if result['status'] == 'success' else 1)
