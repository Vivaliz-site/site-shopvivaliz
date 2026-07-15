#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Gerador de SEO Inteligente por Marketplace
Shopee: Foco em palavras-chave
TikTok: Foco em emoção e conversão
"""
import os
import sys
import json
import logging
from pathlib import Path
from typing import Dict, Tuple

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

SEO_LOG = Path('logs/seo_generated.json')


class SEOGenerator:
    """Gerador de SEO otimizado por marketplace"""

    def __init__(self):
        self.seo_data = {}

    def generate_shopee_seo(self, product: Dict) -> Dict:
        """Gera SEO otimizado para Shopee (foco em palavras-chave)"""
        sku = product.get('sku', 'unknown')

        # Extração de dados
        name = product.get('name', '').strip()
        category = product.get('category', '').lower()
        attributes = product.get('attributes', {})
        price = product.get('price', 0)

        # Título otimizado para Shopee (até 150 chars)
        keywords = []
        if attributes.get('color'):
            keywords.append(attributes['color'])
        if attributes.get('size'):
            keywords.append(f"Tamanho {attributes['size']}")
        if attributes.get('material'):
            keywords.append(attributes['material'])

        title = f"{name} {' '.join(keywords)}"[:150]

        # Descrição com palavras-chave (até 5000 chars)
        description_parts = [
            f"✅ {name}",
            f"📦 Categoria: {category}",
            "",
            "🎯 CARACTERÍSTICAS:",
        ]

        for attr_key, attr_value in attributes.items():
            if attr_value:
                description_parts.append(f"  • {attr_key.title()}: {attr_value}")

        description_parts.extend([
            "",
            "💰 GARANTIA DE QUALIDADE",
            "✅ Produto Original",
            "✅ Entrega Rápida",
            "✅ Melhor Preço",
            "",
            "📍 Frete Grátis em Compras Acima de R$50",
        ])

        description = "\n".join(description_parts)

        return {
            'sku': sku,
            'platform': 'shopee',
            'title': title,
            'description': description,
            'keywords': keywords,
            'seo_score': self._calculate_seo_score(title, description)
        }

    def generate_tiktok_seo(self, product: Dict) -> Dict:
        """Gera SEO otimizado para TikTok (foco em emoção e conversão)"""
        sku = product.get('sku', 'unknown')

        name = product.get('name', '').strip()
        category = product.get('category', '').lower()
        attributes = product.get('attributes', {})

        # Título emocional para TikTok (até 100 chars)
        emotional_words = {
            'eletrônicos': '🚀 INOVAÇÃO',
            'moda': '✨ ESTILO',
            'beleza': '💄 BELEZA',
            'casa': '🏠 CONFORTO',
            'esportes': '⚽ PERFORMANCE',
        }

        emotion = emotional_words.get(category, '⭐ INCRÍVEL')
        title = f"{emotion} {name}"[:100]

        # Descrição persuasiva (até 2200 chars)
        description_parts = [
            f"🎉 {name.upper()}!",
            f"💯 O melhor do mercado",
            "",
            "Por que você vai amar:",
        ]

        if attributes.get('quality'):
            description_parts.append(f"  ✨ Qualidade: {attributes['quality']}")
        if attributes.get('style'):
            description_parts.append(f"  🎨 Estilo: {attributes['style']}")

        description_parts.extend([
            "",
            "⚡ OFERTA LIMITADA!",
            "🛒 Clique Agora e Aproveite",
            "🚚 Entrega Rápida",
            "💳 Parcelamento Disponível",
        ])

        description = "\n".join(description_parts)

        # Hashtags para TikTok
        hashtags = ['#' + category, '#estilo', '#qualidade', '#oferta', '#tiktokshop']

        return {
            'sku': sku,
            'platform': 'tiktok',
            'title': title,
            'description': description,
            'hashtags': hashtags,
            'seo_score': self._calculate_seo_score(title, description, hashtags)
        }

    def _calculate_seo_score(self, title: str, description: str, hashtags: list = None) -> float:
        """Calcula score de SEO (0-100)"""
        score = 0.0

        # Título (30 pontos)
        if len(title) >= 40 and len(title) <= 150:
            score += 30
        elif len(title) > 0:
            score += 15

        # Descrição (50 pontos)
        if len(description) >= 200:
            score += 50
        elif len(description) >= 100:
            score += 25

        # Hashtags (20 pontos)
        if hashtags:
            score += min(20, len(hashtags) * 4)

        return min(100.0, score)

    def process_products(self, products: list) -> Dict:
        """Processa todos os produtos"""
        logger.info(f"Gerando SEO para {len(products)} produtos...")

        seo_results = {
            'timestamp': str(Path.cwd()),
            'shopee': [],
            'tiktok': []
        }

        for product in products:
            try:
                shopee_seo = self.generate_shopee_seo(product)
                tiktok_seo = self.generate_tiktok_seo(product)

                seo_results['shopee'].append(shopee_seo)
                seo_results['tiktok'].append(tiktok_seo)

                self.seo_data[product.get('sku')] = {
                    'shopee': shopee_seo,
                    'tiktok': tiktok_seo
                }

            except Exception as e:
                logger.warning(f"Erro gerando SEO para {product.get('sku')}: {e}")

        logger.info(f"✅ SEO gerado para {len(seo_results['shopee'])} produtos")
        return seo_results

    def save_seo(self):
        """Salva SEO em log"""
        SEO_LOG.parent.mkdir(parents=True, exist_ok=True)
        with SEO_LOG.open('w', encoding='utf-8') as f:
            json.dump(self.seo_data, f, indent=2, ensure_ascii=False)


def main() -> int:
    """Main entry point"""
    try:
        # Simular dados de produtos
        products = [
            {
                'sku': 'JVAQAC44',
                'name': 'Assento Almofadado',
                'category': 'casa',
                'attributes': {
                    'color': 'Preto',
                    'size': 'Único',
                    'material': 'Espuma Alta Densidade',
                    'quality': 'Premium'
                },
                'price': 89.90
            }
        ]

        generator = SEOGenerator()
        seo_results = generator.process_products(products)
        generator.save_seo()

        logger.info("✅ Geração de SEO concluída")
        return 0

    except Exception as e:
        logger.error(f"❌ Erro: {e}")
        return 1


if __name__ == '__main__':
    sys.exit(main())
