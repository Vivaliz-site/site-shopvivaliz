#!/usr/bin/env python3
"""
Sistema de A/B Testing automático
Testa imagens e escolhe melhor automaticamente
"""

import json
import random
from datetime import datetime, timedelta
from typing import Dict, List

class ABTester:
    def __init__(self):
        self.tests = {}

    def create_ab_test(self, product_id: str, images: List[Dict]) -> Dict:
        """Cria novo A/B test para imagens"""
        test_id = f"test_{product_id}_{int(random.random() * 10000)}"

        test = {
            'test_id': test_id,
            'product_id': product_id,
            'images': images,
            'created_at': datetime.now().isoformat(),
            'status': 'running',
            'results': None
        }

        # Inicializar estatísticas para cada variante
        for img in images:
            img['impressions'] = 0
            img['clicks'] = 0
            img['conversions'] = 0
            img['ctr'] = 0
            img['conversion_rate'] = 0

        self.tests[test_id] = test
        return test

    def record_impression(self, test_id: str, image_variant: int):
        """Registra visualização de imagem"""
        if test_id in self.tests:
            test = self.tests[test_id]
            if image_variant < len(test['images']):
                test['images'][image_variant]['impressions'] += 1

    def record_click(self, test_id: str, image_variant: int):
        """Registra clique em imagem"""
        if test_id in self.tests:
            test = self.tests[test_id]
            if image_variant < len(test['images']):
                test['images'][image_variant]['clicks'] += 1
                self._update_metrics(test_id, image_variant)

    def record_conversion(self, test_id: str, image_variant: int):
        """Registra conversão (venda)"""
        if test_id in self.tests:
            test = self.tests[test_id]
            if image_variant < len(test['images']):
                test['images'][image_variant]['conversions'] += 1
                self._update_metrics(test_id, image_variant)

    def _update_metrics(self, test_id: str, variant: int):
        """Atualiza métricas CTR e conversion rate"""
        test = self.tests[test_id]
        img = test['images'][variant]

        if img['impressions'] > 0:
            img['ctr'] = img['clicks'] / img['impressions']
            img['conversion_rate'] = img['conversions'] / img['impressions']

    def should_finish_test(self, test_id: str) -> bool:
        """Verifica se teste pode terminar (significância estatística)"""
        if test_id not in self.tests:
            return False

        test = self.tests[test_id]
        created = datetime.fromisoformat(test['created_at'])

        # Mínimo 7 dias e 100 impressões
        if (datetime.now() - created) < timedelta(days=7):
            return False

        total_impressions = sum(img['impressions'] for img in test['images'])
        if total_impressions < 100:
            return False

        return self._has_statistical_significance(test['images'])

    def _has_statistical_significance(self, images: List[Dict]) -> bool:
        """Verifica significância estatística entre variantes"""
        # Teste chi-quadrado simplificado
        if len(images) < 2:
            return True

        best = max(images, key=lambda x: x['ctr'])
        worst = min(images, key=lambda x: x['ctr'])

        # Diferença mínima de 15% no CTR
        if best['impressions'] > 50 and worst['impressions'] > 50:
            difference = abs(best['ctr'] - worst['ctr'])
            return difference > 0.15

        return False

    def get_winner(self, test_id: str) -> Dict:
        """Retorna imagem vencedora (melhor CTR)"""
        if test_id not in self.tests:
            return None

        test = self.tests[test_id]
        winner = max(test['images'], key=lambda x: x['ctr'])

        test['status'] = 'finished'
        test['results'] = {
            'winner': winner,
            'metrics': {
                img['variant']: {
                    'ctr': img['ctr'],
                    'conversions': img['conversions']
                }
                for img in test['images']
            },
            'finished_at': datetime.now().isoformat()
        }

        return winner

    def save_results(self, test_id: str, filepath: str = 'logs/ab_tests.jsonl'):
        """Salva resultados do teste"""
        if test_id in self.tests:
            test = self.tests[test_id]

            with open(filepath, 'a') as f:
                f.write(json.dumps(test) + '\n')

    def simulate_test_data(self, test_id: str, num_impressions: int = 500):
        """Simula dados de teste para desenvolvimento"""
        if test_id not in self.tests:
            return

        test = self.tests[test_id]

        # Simular diferentes performance entre variantes
        performances = [0.08, 0.12, 0.10, 0.09]  # CTRs diferentes

        for img, perf in zip(test['images'], performances):
            img['impressions'] = num_impressions
            img['clicks'] = int(num_impressions * perf)
            img['conversions'] = int(img['clicks'] * 0.3)  # 30% conversion rate
            img['ctr'] = img['clicks'] / num_impressions
            img['conversion_rate'] = img['conversions'] / num_impressions
