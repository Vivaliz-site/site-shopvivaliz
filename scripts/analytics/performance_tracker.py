#!/usr/bin/env python3
"""
Rastreador de performance e aprendizado
Analisa dados para melhoria contínua
"""

import csv
import json
import os
from datetime import datetime
from typing import Dict, List

class PerformanceTracker:
    def __init__(self, log_file='logs/performance.csv'):
        self.log_file = log_file
        self._init_log_file()

    def _init_log_file(self):
        """Inicializa arquivo de log se não existir"""
        os.makedirs(os.path.dirname(self.log_file) or '.', exist_ok=True)
        try:
            with open(self.log_file, 'r') as f:
                pass
        except FileNotFoundError:
            with open(self.log_file, 'w') as f:
                writer = csv.DictWriter(f, fieldnames=[
                    'timestamp', 'product_id', 'marketplace', 'seo_score',
                    'image_score', 'ctr', 'conversion_rate', 'impressions', 'sales'
                ])
                writer.writeheader()

    def log_product_performance(self, product_id: str, data: Dict):
        """Registra performance de um produto"""
        entry = {
            'timestamp': datetime.now().isoformat(),
            'product_id': product_id,
            'marketplace': data.get('marketplace', 'unknown'),
            'seo_score': data.get('seo_score', 0),
            'image_score': data.get('image_score', 0),
            'ctr': data.get('ctr', 0),
            'conversion_rate': data.get('conversion_rate', 0),
            'impressions': data.get('impressions', 0),
            'sales': data.get('sales', 0),
        }

        with open(self.log_file, 'a') as f:
            writer = csv.DictWriter(f, fieldnames=entry.keys())
            writer.writerow(entry)

    def get_seo_insights(self) -> Dict:
        """Analisa insights de SEO"""
        shopee_scores = []
        tiktok_scores = []

        with open(self.log_file, 'r') as f:
            reader = csv.DictReader(f)
            for row in reader:
                if row['marketplace'] == 'shopee':
                    shopee_scores.append(float(row['seo_score'] or 0))
                elif row['marketplace'] == 'tiktok':
                    tiktok_scores.append(float(row['seo_score'] or 0))

        return {
            'shopee': {
                'avg_score': sum(shopee_scores) / len(shopee_scores) if shopee_scores else 0,
                'count': len(shopee_scores),
                'trend': 'improving' if self._is_trending_up(shopee_scores) else 'stable'
            },
            'tiktok': {
                'avg_score': sum(tiktok_scores) / len(tiktok_scores) if tiktok_scores else 0,
                'count': len(tiktok_scores),
                'trend': 'improving' if self._is_trending_up(tiktok_scores) else 'stable'
            }
        }

    def get_image_insights(self) -> Dict:
        """Analisa insights de imagens"""
        image_scores = []
        top_performing = []

        try:
            with open(self.log_file, 'r') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    if row.get('image_score'):
                        score = float(row['image_score'])
                        image_scores.append(score)

                        if float(row.get('ctr') or 0) > 0.12:
                            top_performing.append({
                                'product_id': row['product_id'],
                                'ctr': float(row['ctr']),
                                'image_score': score
                            })
        except:
            pass

        avg_score = sum(image_scores) / len(image_scores) if image_scores else 0

        # Gerar recomendacoes localmente
        improvements = []
        if avg_score < 60:
            improvements = ['Aumentar resolucao', 'Melhorar iluminacao']
        elif avg_score < 80:
            improvements = ['Optimizar cores']

        return {
            'avg_image_score': avg_score,
            'top_performing_images': sorted(top_performing, key=lambda x: x['ctr'], reverse=True)[:10],
            'improvement_areas': improvements
        }

    def get_conversion_insights(self) -> Dict:
        """Analisa insights de conversão"""
        conversions = []
        high_performers = []

        with open(self.log_file, 'r') as f:
            reader = csv.DictReader(f)
            for row in reader:
                rate = float(row['conversion_rate'] or 0)
                conversions.append(rate)

                if rate > 0.05:
                    high_performers.append({
                        'product_id': row['product_id'],
                        'conversion_rate': rate,
                        'marketplace': row['marketplace']
                    })

        return {
            'avg_conversion_rate': sum(conversions) / len(conversions) if conversions else 0,
            'high_performers': high_performers,
            'by_marketplace': self._conversion_by_marketplace()
        }

    def _is_trending_up(self, scores: List[float]) -> bool:
        """Verifica se scores estão melhorando"""
        if len(scores) < 2:
            return False

        recent = scores[-10:]
        older = scores[:-10]

        recent_avg = sum(recent) / len(recent)
        older_avg = sum(older) / len(older) if older else 0

        return recent_avg > older_avg

    def _get_improvement_areas(self) -> List[str]:
        """Identifica áreas de melhoria"""
        insights = self.get_image_insights()
        avg_score = insights['avg_image_score']

        if avg_score < 60:
            return [
                'Qualidade de imagens precisa melhorar',
                'Aumentar resolução e iluminação',
                'Testar diferentes ângulos'
            ]
        elif avg_score < 80:
            return ['Optimizar cores e contraste']

        return ['Qualidade de imagens está ótima']

    def _conversion_by_marketplace(self) -> Dict:
        """Calcula conversion rate por marketplace"""
        marketplaces = {'shopee': [], 'tiktok': []}

        with open(self.log_file, 'r') as f:
            reader = csv.DictReader(f)
            for row in reader:
                rate = float(row['conversion_rate'] or 0)
                mp = row['marketplace']
                if mp in marketplaces:
                    marketplaces[mp].append(rate)

        return {
            'shopee': sum(marketplaces['shopee']) / len(marketplaces['shopee']) if marketplaces['shopee'] else 0,
            'tiktok': sum(marketplaces['tiktok']) / len(marketplaces['tiktok']) if marketplaces['tiktok'] else 0,
        }

    def generate_report(self) -> Dict:
        """Gera relatório completo de performance"""
        return {
            'timestamp': datetime.now().isoformat(),
            'seo': self.get_seo_insights(),
            'images': self.get_image_insights(),
            'conversions': self.get_conversion_insights(),
            'recommendations': self._generate_recommendations()
        }

    def _generate_recommendations(self) -> List[str]:
        """Gera recomendações baseado em análise"""
        recommendations = []

        seo = self.get_seo_insights()
        if seo['shopee']['avg_score'] < 70:
            recommendations.append('Melhorar SEO para Shopee: adicionar mais keywords')

        if seo['tiktok']['avg_score'] < 70:
            recommendations.append('Melhorar SEO para TikTok: aumentar apelo emocional')

        conversions = self.get_conversion_insights()
        if conversions['avg_conversion_rate'] < 0.03:
            recommendations.append('Testar novas imagens para aumentar conversão')

        return recommendations
