#!/usr/bin/env python3
"""
Gerador de imagens com IA (OpenAI)
Cria 4 imagens por produto baseadas na imagem real
"""

import os
import json
from datetime import datetime
from typing import List, Dict

class IAImageGenerator:
    def __init__(self):
        self.api_key = os.getenv('OPENAI_API_KEY', '')
        self.output_dir = 'storage/ia_images'

    def generate_product_images(self, product: Dict) -> Dict:
        """Gera 4 imagens para um produto"""
        product_id = product.get('id', 'unknown')
        product_name = product.get('name', 'Produto')

        images = []

        prompts = [
            f"Imagem profissional e atraente do {product_name}. Fundo branco. Iluminacao Studio",
            f"{product_name} em uso prático. Ambiente realista. Alta qualidade",
            f"Detalhe closeup do {product_name}. Texturas e acabamentos. Profissional",
            f"{product_name} destaque com efeito visual. Cores vibrantes. Marketing"
        ]

        for i, prompt in enumerate(prompts):
            try:
                image_url = self._call_image_generation(prompt)
                images.append({
                    'variant': i + 1,
                    'url': image_url,
                    'prompt': prompt,
                    'generated_at': datetime.now().isoformat(),
                    'status': 'success'
                })
            except Exception as e:
                images.append({
                    'variant': i + 1,
                    'url': None,
                    'prompt': prompt,
                    'error': str(e),
                    'status': 'failed'
                })

        # Salvar metadata
        self._save_image_metadata(product_id, images)

        return {
            'product_id': product_id,
            'product_name': product_name,
            'images': images,
            'total_generated': len([img for img in images if img['status'] == 'success']),
            'quality_score': self._calculate_image_quality(images)
        }

    def _call_image_generation(self, prompt: str) -> str:
        """Chama API OpenAI para gerar imagem"""
        # Simulado - em produção usaria openai.Image.create()
        return f"https://storage.shopvivaliz.com/ai_generated/{hash(prompt)}.jpg"

    def detect_bad_images(self, images: List[str]) -> List[Dict]:
        """Detecta imagens com baixo CTR (ruim)"""
        bad_images = []

        for img in images:
            # Simulado - em produção analisaria métricas reais
            quality_score = self._analyze_image_quality(img)

            if quality_score < 0.6:
                bad_images.append({
                    'image': img,
                    'quality_score': quality_score,
                    'reason': 'Low quality or low CTR',
                    'needs_regeneration': True
                })

        return bad_images

    def _analyze_image_quality(self, image_url: str) -> float:
        """Analisa qualidade da imagem (0-1)"""
        # Simulado - em produção usaria Computer Vision
        return 0.75

    def select_best_image(self, images: List[Dict]) -> Dict:
        """Seleciona melhor imagem baseado em A/B test"""
        if not images:
            return None

        # Ordenar por score
        scored_images = sorted(
            images,
            key=lambda x: x.get('ab_test_ctr', 0),
            reverse=True
        )

        best = scored_images[0]
        best['selected'] = True
        best['selected_at'] = datetime.now().isoformat()

        return best

    def _calculate_image_quality(self, images: List[Dict]) -> float:
        """Calcula score de qualidade das imagens"""
        successful = [img for img in images if img['status'] == 'success']

        if not successful:
            return 0

        return len(successful) / len(images)

    def _save_image_metadata(self, product_id: str, images: List[Dict]):
        """Salva metadata das imagens geradas"""
        metadata_file = os.path.join(self.output_dir, f'{product_id}_metadata.json')

        os.makedirs(self.output_dir, exist_ok=True)

        with open(metadata_file, 'w') as f:
            json.dump({
                'product_id': product_id,
                'images': images,
                'created_at': datetime.now().isoformat()
            }, f, indent=2)
