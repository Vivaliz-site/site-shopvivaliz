#!/usr/bin/env python3
"""
Sistema de Priorização com IA
Decide quais produtos vender primeiro (score 0-100)
"""

import json
from datetime import datetime
from typing import List, Dict

class ProductPrioritizer:
    def __init__(self):
        self.scores = {}

    def calculate_priority_score(self, product: Dict) -> int:
        """Calcula score de 0-100 baseado em múltiplos fatores"""
        score = 0

        # Fatores de pontuação
        factors = {
            'stock': self._score_stock(product.get('stock', 0)),
            'price': self._score_price(product.get('price', 0)),
            'category': self._score_category(product.get('category', '')),
            'margin': self._score_margin(product.get('margin', 0)),
            'demand': self._score_demand(product.get('demand_indicator', 0)),
            'trend': self._score_trend(product.get('trend', 0)),
            'images': self._score_images(product.get('images', [])),
            'description': self._score_description(product.get('description', '')),
        }

        # Calcular score ponderado
        weights = {
            'stock': 0.15,
            'price': 0.10,
            'category': 0.10,
            'margin': 0.15,
            'demand': 0.20,
            'trend': 0.15,
            'images': 0.10,
            'description': 0.05,
        }

        for factor, weight in weights.items():
            score += factors.get(factor, 0) * weight

        return int(score)

    def _score_stock(self, stock: int) -> float:
        """Score baseado em estoque"""
        if stock == 0:
            return 0
        elif stock < 10:
            return 50
        elif stock < 50:
            return 70
        elif stock < 100:
            return 85
        else:
            return 100

    def _score_price(self, price: float) -> float:
        """Score baseado em preço (faixa ótima)"""
        if price < 10:
            return 40
        elif price < 50:
            return 100
        elif price < 200:
            return 80
        else:
            return 60

    def _score_category(self, category: str) -> float:
        """Score baseado em categoria de alta demanda"""
        high_demand = ['eletrônicos', 'moda', 'beleza', 'casa']
        if any(cat in category.lower() for cat in high_demand):
            return 100
        return 60

    def _score_margin(self, margin: float) -> float:
        """Score baseado em margem de lucro"""
        if margin < 10:
            return 30
        elif margin < 20:
            return 70
        elif margin < 50:
            return 100
        else:
            return 80

    def _score_demand(self, demand: float) -> float:
        """Score baseado em indicador de demanda"""
        return min(demand * 10, 100)

    def _score_trend(self, trend: float) -> float:
        """Score baseado em tendência"""
        return min(trend, 100)

    def _score_images(self, images: List) -> float:
        """Score baseado em qualidade de imagens"""
        if len(images) == 0:
            return 0
        elif len(images) < 2:
            return 50
        elif len(images) < 4:
            return 80
        else:
            return 100

    def _score_description(self, description: str) -> float:
        """Score baseado em qualidade da descrição"""
        if not description:
            return 0
        elif len(description) < 50:
            return 30
        elif len(description) < 200:
            return 70
        else:
            return 100

    def prioritize_products(self, products: List[Dict]) -> List[Dict]:
        """Ordena produtos por prioridade"""
        for product in products:
            product['priority_score'] = self.calculate_priority_score(product)

        # Ordenar por score (descendente)
        sorted_products = sorted(products,
                                key=lambda x: x['priority_score'],
                                reverse=True)

        # Log
        self._log_prioritization(sorted_products)

        return sorted_products

    def _log_prioritization(self, products: List[Dict]):
        """Registra priorização em log"""
        log_entry = {
            'timestamp': datetime.now().isoformat(),
            'total_products': len(products),
            'top_10': [
                {
                    'id': p.get('id'),
                    'name': p.get('name'),
                    'score': p.get('priority_score')
                }
                for p in products[:10]
            ]
        }

        with open('logs/prioritization.log', 'a') as f:
            f.write(json.dumps(log_entry) + '\n')

# Exemplo de uso
if __name__ == '__main__':
    prioritizer = ProductPrioritizer()

    sample_products = [
        {
            'id': 1,
            'name': 'Produto A',
            'stock': 100,
            'price': 50,
            'category': 'Eletrônicos',
            'margin': 35,
            'demand_indicator': 8.5,
            'trend': 85,
            'images': ['img1.jpg', 'img2.jpg', 'img3.jpg'],
            'description': 'Descrição completa do produto A com muitos detalhes úteis'
        },
    ]

    prioritized = prioritizer.prioritize_products(sample_products)
    print(f"Produtos priorizados: {len(prioritized)}")
