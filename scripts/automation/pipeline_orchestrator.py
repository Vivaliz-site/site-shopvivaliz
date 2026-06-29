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

class PipelineOrchestrator:
    def __init__(self):
        self.prioritizer = ProductPrioritizer()
        self.seo_generator = SEOGenerator()
        self.image_generator = IAImageGenerator()
        self.ab_tester = ABTester()
        self.tracker = PerformanceTracker()

        self.processed_count = 0
        self.failed_count = 0
        self.start_time = datetime.now()

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

            for i, product in enumerate(prioritized[:50], 1):  # Limitar a 50 por execução
                print(f"\n[{i}] Processando: {product['name']} (Score: {product['priority_score']})")

                try:
                    result = self._process_single_product(product)
                    results.append(result)
                    self.processed_count += 1
                    print(f"    [OK] Concluído com sucesso")
                except Exception as e:
                    self.failed_count += 1
                    print(f"    [ERRO] {str(e)}")
                    results.append({
                        'product_id': product.get('id'),
                        'status': 'failed',
                        'error': str(e)
                    })

            # 4. Gerar relatório final
            print("\n" + "="*80)
            report = self._generate_final_report(results)
            print(report)
            print("="*80)

            return {
                'status': 'success',
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
        """Carrega produtos da planilha"""
        products = []

        # Simulado - em produção usaria openpyxl
        sample_products = [
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

        return sample_products

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

        result['status'] = 'success'
        return result

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
