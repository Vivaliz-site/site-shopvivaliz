"""
Geração de 4 imagens IA por produto usando GPT-4o Vision (análise) + DALL-E 3 (geração).
Tipos: fundo_branco, angulo_45, lifestyle, close_up
"""
import base64
import json
import os
import time
from pathlib import Path
from urllib.request import urlretrieve

import openai
import requests

_client = None

IMAGE_TYPES = ["fundo_branco", "angulo_45", "lifestyle", "close_up"]

STORAGE_DIR = Path(__file__).parents[3] / "storage" / "ia_images"
STORAGE_DIR.mkdir(parents=True, exist_ok=True)

_PROMPTS = {
    "fundo_branco": "Product photography on pure white background, studio lighting, sharp focus, professional e-commerce style, no shadows",
    "angulo_45": "Product photography at 45-degree angle, clean light gray background, professional commercial photo, showing product depth",
    "lifestyle": "Product in real lifestyle context, natural environment, aspirational mood, warm natural lighting, person using or near the product",
    "close_up": "Extreme close-up product detail shot, macro photography, showing texture and quality, soft bokeh background, studio lighting",
}


def _openai() -> openai.OpenAI:
    global _client
    if _client is None:
        _client = openai.OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    return _client


def _analyze_product_image(image_url: str) -> str:
    """Usa GPT-4o Vision para descrever o produto na imagem."""
    try:
        resp = _openai().chat.completions.create(
            model="gpt-4o",
            messages=[{
                "role": "user",
                "content": [
                    {"type": "image_url", "image_url": {"url": image_url, "detail": "high"}},
                    {"type": "text", "text": "Describe this product in detail for image generation: product type, color, material, shape, size, any distinguishing features. Be specific and concise (max 100 words)."},
                ],
            }],
            max_tokens=200,
        )
        return resp.choices[0].message.content.strip()
    except Exception:
        return "a product"


def _generate_one(product_description: str, image_type: str, retry: int = 2) -> bytes | None:
    """Gera uma imagem DALL-E 3 e retorna os bytes."""
    base_prompt = _PROMPTS[image_type]
    full_prompt = f"{base_prompt}. Product: {product_description}. High quality, commercial photography."

    for attempt in range(1, retry + 2):
        try:
            resp = _openai().images.generate(
                model="dall-e-3",
                prompt=full_prompt,
                size="1024x1024",
                quality="standard",
                n=1,
                response_format="url",
            )
            url = resp.data[0].url
            r = requests.get(url, timeout=30)
            r.raise_for_status()
            return r.content
        except Exception as e:
            if attempt > retry:
                return None
            time.sleep(3 * attempt)
    return None


def _save(data: bytes, sku: str, image_type: str) -> Path:
    """Salva imagem em storage/ia_images/<sku>/<tipo>.jpg"""
    sku_dir = STORAGE_DIR / sku
    sku_dir.mkdir(parents=True, exist_ok=True)
    path = sku_dir / f"{image_type}.jpg"
    path.write_bytes(data)
    return path


def generate_for_product(product: dict) -> dict[str, Path | None]:
    """
    Gera as 4 imagens IA para o produto.
    Retorna dict {image_type: Path | None}.
    """
    sku = str(
        product.get("item_id")
        or product.get("id")
        or product.get("product_id")
        or "unknown"
    )

    # Pegar primeira imagem do produto para análise
    image_url = _get_first_image_url(product)
    product_description = (
        _analyze_product_image(image_url) if image_url
        else (product.get("item_name") or product.get("title") or "product")
    )

    results: dict[str, Path | None] = {}
    for img_type in IMAGE_TYPES:
        data = _generate_one(product_description, img_type)
        if data:
            results[img_type] = _save(data, sku, img_type)
        else:
            results[img_type] = None
        time.sleep(1.2)  # respeitar rate limit DALL-E

    return results


def _get_first_image_url(product: dict) -> str | None:
    """Extrai a primeira URL de imagem do produto (Shopee ou TikTok format)."""
    # Shopee format
    img = product.get("image", {})
    if img:
        urls = img.get("image_url_list") or []
        if urls:
            return urls[0]

    # TikTok format
    main_images = product.get("main_images") or []
    if main_images:
        return main_images[0].get("url")

    return None
