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
        attributes = self._extract_attributes(product)

        # Construir título com keywords
        keywords = self._get_category_keywords(category, attributes)
        optimized_title = self._build_shopee_title(title, keywords, attributes)

        # Construir descrição com SEO
        optimized_description = self._build_shopee_description(
            description, keywords, product, attributes
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
        attributes = self._extract_attributes(product)

        # Título emocional para TikTok
        emotional_title = self._build_tiktok_title(title, attributes)

        # Descrição com apelo emocional
        emotional_description = self._build_tiktok_description(
            description, product, attributes
        )

        return {
            'title': emotional_title[:150],
            'description': emotional_description[:2200],
            'hashtags': ['#shopvivaliz', '#qualidade', '#recomendo', '#promoção'],
            'quality_score': self._calculate_seo_score(emotional_title, emotional_description)
        }

    def _extract_attributes(self, product: Dict) -> Dict:
        attributes = {}
        for key, value in product.items():
            if key in {'id', 'name', 'category', 'description', 'images', 'priority_score'}:
                continue
            if value in (None, '', [], {}):
                continue
            attributes[str(key)] = value
        return attributes

    def _get_category_keywords(self, category: str, attributes: Dict | None = None) -> list:
        """Retorna keywords para categoria"""
        category_lower = category.lower()
        for cat, keywords in self.shopee_keywords.items():
            if cat in category_lower:
                return keywords
        if attributes:
            attr_keywords = []
            for key, value in list(attributes.items())[:3]:
                attr_keywords.append(str(value).lower())
            if attr_keywords:
                return ['novo', 'qualidade'] + attr_keywords[:3]
        return ['novo', 'qualidade', 'pronto']

    def _build_shopee_title(self, title: str, keywords: list, attributes: Dict | None = None) -> str:
        """Constrói título otimizado para Shopee"""
        if not title:
            return "Produto de qualidade"

        # Adicionar principais keywords no título
        optimized = f"{title} - {keywords[0]}"

        if attributes:
            extras = []
            for key in ['material', 'cor', 'tamanho', 'modelo', 'tipo']:
                if key in attributes and attributes[key]:
                    extras.append(str(attributes[key]))
            if extras:
                optimized = f"{optimized} | {' '.join(extras[:2])}"

        if len(optimized) < 100 and len(keywords) > 1:
            optimized += f" | {keywords[1]}"

        return optimized

    def _build_shopee_description(self, desc: str, keywords: list, product: Dict, attributes: Dict | None = None) -> str:
        """Constrói descrição com keywords para Shopee"""
        parts = []

        # Adicionar keywords no início
        parts.append(f"PRODUTO: {', '.join(keywords[:3])}\n")

        # Descrição original
        if desc:
            parts.append(f"DETALHES:\n{desc}\n")

        if attributes:
            parts.append("\nATRIBUTOS:\n")
            for key, value in list(attributes.items())[:8]:
                parts.append(f"- {key}: {value}\n")

        # Informações importantes
        if product.get('price'):
            parts.append(f"\nPRECO: R$ {product['price']}\n")

        if product.get('stock'):
            parts.append(f"ESTOQUE: {product['stock']} unidades\n")

        parts.append("\nBENEFICIOS:\n- Qualidade garantida\n- Pronto para envio")

        return "".join(parts)

    def _build_tiktok_title(self, title: str, attributes: Dict | None = None) -> str:
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

        tail = ""
        if attributes:
            highlight = []
            for key in ['material', 'cor', 'tamanho', 'tipo']:
                if key in attributes and attributes[key]:
                    highlight.append(str(attributes[key]))
            if highlight:
                tail = f" | {' '.join(highlight[:2])}"
        return f"{emotional_words[0]} {title}{tail} - Vem conferir!"

    def _build_tiktok_description(self, desc: str, product: Dict, attributes: Dict | None = None) -> str:
        """Constrói descrição emocional para TikTok"""
        parts = [
            "Confira este produto incrível! 🎁\n",
        ]

        if desc:
            parts.append(f"{desc}\n")

        if attributes:
            parts.append("\nDESTAQUES:\n")
            for key, value in list(attributes.items())[:5]:
                parts.append(f"• {value}\n")

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
