#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Gerador de imagens com IA (OpenAI)
Cria 4 imagens REAIS por produto baseadas na imagem real
"""

import os
import sys
import json
import requests
from datetime import datetime
from typing import List, Dict
from pathlib import Path

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

class IAImageGenerator:
    def __init__(self):
        # Tentar múltiplos nomes de variáveis
        self.api_key = (
            os.getenv('OPENAI_API_KEY') or
            os.getenv('OPENAI_API_KEY_SK') or
            os.getenv('OPENAI_KEY') or
            os.getenv('OPENAI_SECRET') or
            ''
        )
        self.output_dir = 'storage/processed'

        if not self.api_key:
            print("[INFO] OPENAI_API_KEY nao configurada")
            print("       Tente estes nomes nos GitHub Secrets:")
            print("       - OPENAI_API_KEY")
            print("       - OPENAI_API_KEY_SK")
            print("       - OPENAI_KEY")
            print("       - OPENAI_SECRET")

    def generate_product_images(self, product: Dict) -> Dict:
        """Gera 4 imagens REAIS para um produto e salva em disco"""
        product_id = product.get('id', 'unknown')
        product_name = product.get('name', 'Produto')

        # Criar diretório para produto
        product_dir = os.path.join(self.output_dir, str(product_id))
        os.makedirs(product_dir, exist_ok=True)

        images = []

        prompts = [
            f"Imagem profissional e atraente do {product_name}. Fundo branco puro. Iluminação Studio. Alta qualidade. 4K",
            f"{product_name} em uso prático. Ambiente realista e natural. Luz natural. Alta qualidade",
            f"Detalhe closeup do {product_name}. Texturas e acabamentos visíveis. Macro photography. Profissional",
            f"{product_name} destaque com efeito visual atraente. Cores vibrantes. Marketing photo. Produção profissional"
        ]

        print(f"\n🎨 Gerando 4 imagens para: {product_id} ({product_name})")
        print(f"📁 Salvando em: {product_dir}")

        for i, prompt in enumerate(prompts, 1):
            try:
                print(f"  [{i}/4] Gerando imagem {i}...", end=" ", flush=True)

                # Gerar imagem REAL via OpenAI
                image_data = self._call_image_generation_real(prompt)

                if image_data:
                    # Salvar em disco
                    file_path = os.path.join(product_dir, f"{i}.jpg")
                    with open(file_path, 'wb') as f:
                        f.write(image_data)

                    print(f"✅ Salvo: {file_path}")

                    images.append({
                        'variant': i,
                        'local_file': file_path,
                        'prompt': prompt,
                        'generated_at': datetime.now().isoformat(),
                        'status': 'success',
                        'file_size': len(image_data)
                    })
                else:
                    print("❌ Falhou")
                    images.append({
                        'variant': i,
                        'local_file': None,
                        'prompt': prompt,
                        'error': 'Não conseguiu baixar imagem',
                        'status': 'failed'
                    })

            except Exception as e:
                print(f"❌ Erro: {str(e)}")
                images.append({
                    'variant': i,
                    'local_file': None,
                    'prompt': prompt,
                    'error': str(e),
                    'status': 'failed'
                })

        # Salvar metadata
        self._save_image_metadata(product_id, images)

        success_count = len([img for img in images if img['status'] == 'success'])
        print(f"\n✅ Resultado: {success_count}/4 imagens geradas com sucesso\n")

        return {
            'product_id': product_id,
            'product_name': product_name,
            'images': images,
            'total_generated': success_count,
            'quality_score': self._calculate_image_quality(images)
        }

    def _call_image_generation_real(self, prompt: str) -> bytes:
        """Chama API OpenAI REAL para gerar imagem e retorna bytes"""
        try:
            import openai

            if not self.api_key:
                print("❌ OPENAI_API_KEY não configurada")
                return None

            openai.api_key = self.api_key

            # Chamar API OpenAI REAL
            print(f"OpenAI...", end=" ", flush=True)
            response = openai.Image.create(
                prompt=prompt,
                n=1,
                size="1024x1024",
                quality="hd"
            )

            if not response or not response.get('data'):
                print("❌ Resposta vazia")
                return None

            image_url = response['data'][0]['url']

            # Baixar imagem da URL
            print(f"Download...", end=" ", flush=True)
            img_response = requests.get(image_url, timeout=30)

            if img_response.status_code == 200:
                return img_response.content
            else:
                print(f"❌ HTTP {img_response.status_code}")
                return None

        except Exception as e:
            print(f"❌ Erro OpenAI: {str(e)}")
            return None

    def detect_bad_images(self, images: List[str]) -> List[Dict]:
        """Detecta imagens com baixo CTR (ruim)"""
        bad_images = []

        for img in images:
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
        """Analisa qualidade real da imagem (0-1): resolucao, nitidez (deteccao
        de blur via variancia de bordas) e exposicao (brilho medio nem escuro
        nem estourado). Nao usa CV pago (sem credenciais pra isso) -- heuristica
        real com PIL, nao mais um valor fixo simulado (0.75 pra tudo).
        """
        try:
            from PIL import Image, ImageFilter
            import io

            if image_url.startswith('http://') or image_url.startswith('https://'):
                resp = requests.get(image_url, timeout=15)
                resp.raise_for_status()
                img = Image.open(io.BytesIO(resp.content))
            else:
                img = Image.open(image_url)

            img = img.convert('L')  # escala de cinza
            width, height = img.size

            # Resolucao: penaliza imagens muito pequenas (thumbnails/miniaturas
            # roubadas), satura o score em 800x800+.
            resolution_score = min(1.0, (width * height) / (800 * 800))

            # Nitidez: filtro de bordas + variancia dos pixels. Imagem borrada
            # tem bordas fracas e uniformes (variancia baixa); imagem nitida
            # tem bordas fortes e variadas (variancia alta).
            edges = img.filter(ImageFilter.FIND_EDGES)
            pixels = list(edges.getdata())
            mean = sum(pixels) / len(pixels)
            variance = sum((p - mean) ** 2 for p in pixels) / len(pixels)
            sharpness_score = min(1.0, variance / 2000.0)

            # Exposicao: brilho medio da imagem original deve ficar longe dos
            # extremos (0=preto, 255=estourado de branco).
            original_pixels = list(img.getdata())
            brightness = sum(original_pixels) / len(original_pixels)
            exposure_score = 1.0 - abs(brightness - 128) / 128.0

            return round(
                resolution_score * 0.3 + sharpness_score * 0.5 + exposure_score * 0.2,
                3
            )
        except Exception as e:
            print(f"[!] Falha ao analisar qualidade de {image_url}: {e}")
            return 0.0  # falha na analise = tratado como qualidade ruim, nao como "ok por padrao"

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
