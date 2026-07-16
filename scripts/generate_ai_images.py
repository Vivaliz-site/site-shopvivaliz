#!/usr/bin/env python3
import base64
import io
import logging
import os
import shutil
import sys
from pathlib import Path
from typing import Optional

import requests
from PIL import Image

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

RAW_ROOT = Path('storage/raw')
PROCESSED_ROOT = Path('storage/processed')
VARIANT_COUNT = 4

PROMPTS = {
    1: 'a clean white-background hero shot of the exact product in the image, preserving its shape, color, and proportions, with no new product features added',
    2: 'a lifestyle scene showing the product in realistic use, preserving its shape, color, and proportions, with no new product features added',
    3: 'a slight rotation and zoom variation of the product photo, preserving its shape, color, and proportions, with no new product features added',
    4: 'a close-up detail highlight of the product or its texture, preserving its shape, color, and proportions, with no new product features added',
}


class AIProviderError(Exception):
    pass


def get_env_variable(name: str, alt_names: Optional[list[str]] = None) -> Optional[str]:
    value = os.environ.get(name)
    if value:
        return value.strip()
    if alt_names:
        for alt in alt_names:
            value = os.environ.get(alt)
            if value:
                logger.info(f'Using environment variable {alt} for {name}')
                return value.strip()
    return None


def load_ai_settings() -> tuple[Optional[str], Optional[str]]:
    provider = get_env_variable('AI_PROVIDER')
    api_key = get_env_variable('AI_API_KEY')
    if provider:
        provider = provider.strip().lower()
    if api_key:
        api_key = api_key.strip()
    return provider, api_key


def get_variant_prompt(variant: int) -> str:
    return PROMPTS.get(variant, PROMPTS[1])


def safe_copy_source(source_path: Path, destination_path: Path) -> bool:
    try:
        destination_path.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source_path, destination_path)
        logger.info(f'Copied original image to fallback path: {destination_path}')
        return True
    except Exception as exc:
        logger.error(f'Failed to copy fallback image to {destination_path}: {exc}')
        return False


def save_image_bytes(image_bytes: bytes, output_path: Path) -> bool:
    try:
        output_path.parent.mkdir(parents=True, exist_ok=True)
        with Image.open(io.BytesIO(image_bytes)) as image:
            image = image.convert('RGB')
            image.save(output_path, format='JPEG', quality=92, optimize=True)
        return True
    except Exception as exc:
        logger.error(f'Failed to save AI image to {output_path}: {exc}')
        return False


def download_image_url(image_url: str, output_path: Path) -> bool:
    try:
        response = requests.get(image_url, timeout=60)
        response.raise_for_status()
        output_path.parent.mkdir(parents=True, exist_ok=True)
        with open(output_path, 'wb') as image_file:
            image_file.write(response.content)
        return True
    except Exception as exc:
        logger.error(f'Failed to download image URL {image_url}: {exc}')
        return False


def openai_image_edit(source_path: Path, prompt: str, api_key: str) -> bytes:
    endpoint = 'https://api.openai.com/v1/images/edits'
    with source_path.open('rb') as image_file:
        files = {
            'image': ('image.jpg', image_file, 'image/jpeg'),
        }
        data = {
            'model': 'gpt-image-1',
            'prompt': prompt,
            'size': '1024x1024',
            'n': '1',
        }
        headers = {
            'Authorization': f'Bearer {api_key}',
        }
        response = requests.post(endpoint, headers=headers, data=data, files=files, timeout=120)
    if response.status_code != 200:
        raise AIProviderError(f'OpenAI image edit failed ({response.status_code}): {response.text}')

    body = response.json()
    if not isinstance(body, dict) or 'data' not in body or not body['data']:
        raise AIProviderError('OpenAI image edit returned no data')

    image_item = body['data'][0]
    if 'b64_json' in image_item and image_item['b64_json']:
        return base64.b64decode(image_item['b64_json'])
    if 'url' in image_item and image_item['url']:
        temp_path = Path('.cache_ai_image_download.jpg')
        if download_image_url(image_item['url'], temp_path):
            try:
                return temp_path.read_bytes()
            finally:
                try:
                    temp_path.unlink()
                except OSError:
                    pass
        raise AIProviderError('OpenAI returned image URL but download failed')

    raise AIProviderError('OpenAI image edit response lacked image content')


def render_ai_variant(source_path: Path, destination_path: Path, variant: int, provider: Optional[str], api_key: Optional[str]) -> bool:
    prompt = get_variant_prompt(variant)
    if provider != 'openai' or not api_key:
        logger.warning('AI_PROVIDER is not set to a supported provider or API key is missing; using original image fallback')
        return safe_copy_source(source_path, destination_path)

    try:
        logger.info(f'  Generating variant {variant}: {prompt}')
        image_bytes = openai_image_edit(source_path, prompt, api_key)
        destination_path.parent.mkdir(parents=True, exist_ok=True)
        with open(destination_path, 'wb') as f:
            f.write(image_bytes)
        logger.info(f'    Saved AI variant {variant} at {destination_path}')
        return True
    except Exception as exc:
        logger.warning(f'AI generation failed for {source_path.name} variant {variant}: {exc}')
        logger.warning('Falling back to original image for this variant')
        return safe_copy_source(source_path, destination_path)


def main(argv=None) -> int:
    provider, api_key = load_ai_settings()
    if not provider or not api_key:
        logger.warning('AI_PROVIDER or AI_API_KEY not set; pipeline will copy original images as fallback')

    if not RAW_ROOT.exists():
        logger.error(f'Raw image directory not found: {RAW_ROOT}')
        return 1

    total_skus = 0
    total_variants = 0
    failures = 0

    for sku_dir in sorted(RAW_ROOT.iterdir()):
        if not sku_dir.is_dir():
            continue
        source_image = sku_dir / '1.jpg'
        if not source_image.exists():
            logger.warning(f'Skipping SKU {sku_dir.name}: missing source image {source_image}')
            continue

        total_skus += 1
        output_dir = PROCESSED_ROOT / sku_dir.name
        output_dir.mkdir(parents=True, exist_ok=True)
        logger.info(f'Processing SKU {sku_dir.name}')

        for variant in range(1, VARIANT_COUNT + 1):
            destination = output_dir / f'{variant}.jpg'
            success = render_ai_variant(source_image, destination, variant, provider, api_key)
            total_variants += 1
            if not success:
                failures += 1

    logger.info(f'AI image generation completed: SKUs={total_skus}, variants attempted={total_variants}, failures={failures}')
    return 0 if failures == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
