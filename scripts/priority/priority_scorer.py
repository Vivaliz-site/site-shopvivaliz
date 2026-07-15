#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Sistema de Priorização Inteligente com IA
Calcula score (0-100) para cada produto
Ordena antes do pipeline
"""
import os
import sys
import json
import logging
from pathlib import Path
from typing import Dict, List
from datetime import datetime

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

PRIORITY_LOG = Path('logs/priority_scores.json')


class PriorityScorer:
    """Sistema de priorização com IA"""

    def __init__(self):
        self.scores = {}

    def calculate_score(self, product: Dict) -> float:
        """Calcula score 0-100 para um produto"""
        score = 0.0

        # Fator 1: Categoria (25 pontos)
        category_weights = {
            'eletrônicos': 25,
            'moda': 20,
            'beleza': 22,
            'casa': 18,
            'esportes': 20,
            'default': 15
        }
        category = str(product.get('category', 'default')).lower()
        for cat_key, weight in category_weights.items():
            if cat_key in category:
                score += weight
                break
        else:
            score += category_weights['default']

        # Fator 2: Quantidade de atributos (15 pontos)
        attributes = product.get('attributes', {})
        attr_count = len([a for a in attributes.values() if a])
        score += min(15, attr_count * 3)

        # Fator 3: Preço (20 pontos)
        # Produtos de preço médio-alto são mais prioritários
        try:
            price = float(product.get('price', 0))
            if price > 0:
                if 100 <= price <= 1000:
                    score += 20
                elif 1000 < price <= 5000:
                    score += 18
                elif price > 5000:
                    score += 15
                else:
                    score += 10
        except:
            score += 5

        # Fator 4: Imagens existentes (15 pontos)
        images = product.get('images', [])
        image_count = len([i for i in images if i])
        score += min(15, image_count * 3)

        # Fator 5: Descrição (10 pontos)
        description = product.get('description', '')
        if description and len(description) > 50:
            score += 10
        elif description:
            score += 5

        # Fator 6: Histórico de vendas (15 pontos) - se disponível
        sales = product.get('sales_count', 0)
        if sales > 0:
            score += min(15, sales / 10)

        return min(100.0, score)

    def prioritize_products(self, products: List[Dict]) -> List[Dict]:
        """Ordena produtos por prioridade"""
        logger.info(f"Calculando scores para {len(products)} produtos...")

        for product in products:
            score = self.calculate_score(product)
            product['priority_score'] = score
            self.scores[product.get('sku', 'unknown')] = score

        # Ordenar por score decrescente
        sorted_products = sorted(products, key=lambda p: p.get('priority_score', 0), reverse=True)

        logger.info(f"✅ {len(sorted_products)} produtos priorizados")

        # Top 5
        logger.info("\n🏆 TOP 5 PRODUTOS (MAIOR PRIORIDADE):")
        for i, p in enumerate(sorted_products[:5], 1):
            logger.info(f"  {i}. {p.get('sku')} - Score: {p.get('priority_score'):.1f}")

        return sorted_products

    def save_scores(self):
        """Salva scores em log"""
        PRIORITY_LOG.parent.mkdir(parents=True, exist_ok=True)
        with PRIORITY_LOG.open('w', encoding='utf-8') as f:
            json.dump({
                'timestamp': datetime.now().isoformat(),
                'scores': self.scores
            }, f, indent=2, ensure_ascii=False)

        logger.info(f"📄 Scores salvos em {PRIORITY_LOG}")
