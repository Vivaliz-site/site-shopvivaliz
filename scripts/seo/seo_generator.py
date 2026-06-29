#!/usr/bin/env python3
"""
Gerador de SEO automático para Shopee e TikTok
"""

import json
from typing import Dict

class SEOGenerator:
    def __init__(self):
        self.shopee_keywords = self._load_keywords()

    def _load_keywords(self) -> Dict:
        """Carrega keywords para diferentes categorias"""
        return {
            'eletrônicos': ['original', 'novo', 'garantia', '12 meses', 'nota fiscal'],
            'moda': ['qualidade', 'confortável', 'moderno', 'tendência', 'exclusivo'],
            'beleza': ['natural', 'seguro', 'dermatologista', 'anti-envelhecimento'],
            'casa': ['decorativo', 'funcional', 'moderno', 'durável', 'design'],
        }

    def generate_shopee_seo(self, product: Dict) -> Dict:
        """Gera SEO otimizado para Shopee (keywords)"""
        title = product.get('name', '')
        category = product.get('category', '')
        description = product.get('description', '')

        # Construir título com keywords
        keywords = self._get_category_keywords(category)
        optimized_title = self._build_shopee_title(title, keywords)

        # Construir descrição com SEO
        optimized_description = self._build_shopee_description(
            description, keywords, product
        )

        return {
            'title': optimized_title[:120],  # Limite Shopee
            'description': optimized_description[:500],
            'keywords': keywords[:5],
            'quality_score': self._calculate_seo_score(optimized_title, optimized_description)
        }

    def generate_tiktok_seo(self, product: Dict) -> Dict:
        """Gera SEO emocional para TikTok (conversão)"""
        title = product.get('name', '')
        description = product.get('description', '')

        # Título emocional para TikTok
        emotional_title = self._build_tiktok_title(title)

        # Descrição com apelo emocional
        emotional_description = self._build_tiktok_description(
            description, product
        )

        return {
            'title': emotional_title[:150],
            'description': emotional_description[:2200],
            'hashtags': ['#shopvivaliz', '#qualidade', '#recomendo', '#promoção'],
            'quality_score': self._calculate_seo_score(emotional_title, emotional_description)
        }

    def _get_category_keywords(self, category: str) -> list:
        """Retorna keywords para categoria"""
        category_lower = category.lower()
        for cat, keywords in self.shopee_keywords.items():
            if cat in category_lower:
                return keywords
        return ['novo', 'qualidade', 'pronto']

    def _build_shopee_title(self, title: str, keywords: list) -> str:
        """Constrói título otimizado para Shopee"""
        if not title:
            return "Produto de qualidade"

        # Adicionar principais keywords no título
        optimized = f"{title} - {keywords[0]}"

        if len(optimized) < 100:
            optimized += f" | {keywords[1]}"

        return optimized

    def _build_shopee_description(self, desc: str, keywords: list, product: Dict) -> str:
        """Constrói descrição com keywords para Shopee"""
        parts = []

        # Adicionar keywords no início
        parts.append(f"PRODUTO: {', '.join(keywords[:3])}\n")

        # Descrição original
        if desc:
            parts.append(f"DETALHES:\n{desc}\n")

        # Informações importantes
        if product.get('price'):
            parts.append(f"\nPRECO: R$ {product['price']}\n")

        if product.get('stock'):
            parts.append(f"ESTOQUE: {product['stock']} unidades\n")

        parts.append("\nBENEFICIOS:\n- Qualidade garantida\n- Pronto para envio")

        return "".join(parts)

    def _build_tiktok_title(self, title: str) -> str:
        """Constrói título emocional para TikTok"""
        emotional_words = [
            "Adorei!",
            "Imperdível!",
            "Surpreendente!",
            "Recomendo!",
            "Perfeito!"
        ]

        if not title:
            return "Produto incrível aqui!"

        return f"{emotional_words[0]} {title} - Vem conferir!"

    def _build_tiktok_description(self, desc: str, product: Dict) -> str:
        """Constrói descrição emocional para TikTok"""
        parts = [
            "Confira este produto incrível! 🎁\n",
        ]

        if desc:
            parts.append(f"{desc}\n")

        # Call to action emocional
        parts.append(
            "\nPOR QUE VOCE VAI AMAR:\n"
            "✓ Qualidade premium\n"
            "✓ Melhor preco do mercado\n"
            "✓ Entrega rapida\n"
            "✓ Satisfacao garantida\n"
        )

        if product.get('stock') and product['stock'] < 20:
            parts.append(f"\n⚡ URGENTE: Apenas {product['stock']} em estoque! Garanta o seu!")

        return "".join(parts)

    def _calculate_seo_score(self, title: str, description: str) -> int:
        """Calcula score SEO (0-100)"""
        score = 0

        # Score do título
        if 20 <= len(title) <= 120:
            score += 30
        elif len(title) > 0:
            score += 15

        # Score da descrição
        if len(description) > 100:
            score += 40
        elif len(description) > 50:
            score += 20

        # Keywords
        keyword_count = description.lower().count('qualidade') + \
                       description.lower().count('produto') + \
                       description.lower().count('pronta')
        score += min(keyword_count * 10, 30)

        return min(score, 100)
