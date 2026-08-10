#!/usr/bin/env python3
import base64
import io
import json
import logging
import os
import shutil
import sys
import time
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
PROVIDER_HEALTH_FILE = Path("storage/ai-provider-health.json")
PROVIDER_AUDIT_LOG = Path("storage/ai-provider-audit.jsonl")

PROMPTS = {
    1: 'a clean white-background hero shot of the exact product in the image, preserving its shape, color, and proportions, with no new product features added',
    2: 'a lifestyle scene showing the product in realistic use, preserving its shape, color, and proportions, with no new product features added',
    3: 'a slight rotation and zoom variation of the product photo, preserving its shape, color, and proportions, with no new product features added',
    4: 'a close-up detail highlight of the product or its texture, preserving its shape, color, and proportions, with no new product features added',
}


class AIProviderError(Exception):
    pass


PROVIDER_BASE_URLS = {
    "openai": "https://api.openai.com/v1",
    "openrouter": "https://openrouter.ai/api/v1",
    "groq": "https://api.groq.com/openai/v1",
    "google": "https://generativelanguage.googleapis.com/v1beta",
}


def normalize_provider(value: Optional[str]) -> str:
    provider = (value or "").strip().lower()
    return {
        "gpt": "openai",
        "openai": "openai",
        "gemini": "google",
        "google": "google",
        "claude": "claude",
        "openrouter": "openrouter",
        "groq": "groq",
        "qrope": "groq",
    }.get(provider, provider)


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
    provider = normalize_provider(get_env_variable('AI_PROVIDER'))
    api_key = get_env_variable('AI_API_KEY') or get_env_variable('OPENAI_API_KEY')
    if api_key:
        api_key = api_key.strip()
    return provider or None, api_key


def load_provider_pool() -> list[tuple[str, str]]:
    pool = [
        ("openai", get_env_variable("OPENAI_API_KEY", ["OPENAI_API_KEYS"])),
        ("google", get_env_variable("GEMINI_API_KEY", ["GOOGLE_IMAGEN_API_KEY", "GOOGLE_GEMINI_API_KEY", "GOOGLE_IMAGEN_API_KEYS"])),
        ("openrouter", get_env_variable("OPENROUTER_API_KEY", ["OPENROUTER_API_KEYS"])),
        ("groq", get_env_variable("GROQ_API_KEY", ["GROQ_API_KEYS"])),
    ]
    return [(normalize_provider(name), key) for name, key in pool if key]


def _read_provider_health() -> dict[str, float]:
    try:
        if not PROVIDER_HEALTH_FILE.exists():
            return {}
        raw = PROVIDER_HEALTH_FILE.read_text(encoding="utf-8")
        data = json.loads(raw)
        if not isinstance(data, dict):
            return {}
        return {str(k): float(v) for k, v in data.items()}
    except Exception:
        return {}


def _write_provider_health(state: dict[str, float]) -> None:
    try:
        PROVIDER_HEALTH_FILE.parent.mkdir(parents=True, exist_ok=True)
        PROVIDER_HEALTH_FILE.write_text(json.dumps(state, indent=2, sort_keys=True), encoding="utf-8")
    except Exception:
        pass


def _mark_provider_cooldown(provider: str, seconds: int = 900) -> None:
    state = _read_provider_health()
    state[normalize_provider(provider)] = time.time() + seconds
    _write_provider_health(state)


def _provider_available(provider: str) -> bool:
    state = _read_provider_health()
    expiry = state.get(normalize_provider(provider))
    return not expiry or expiry <= time.time()


def _append_provider_audit(provider: str, variant: int, status: str, message: str, sku: str = "") -> None:
    try:
        PROVIDER_AUDIT_LOG.parent.mkdir(parents=True, exist_ok=True)
        record = {
            "ts": time.time(),
            "provider": normalize_provider(provider),
            "variant": variant,
            "status": status,
            "message": message[:500],
            "sku": sku,
        }
        with PROVIDER_AUDIT_LOG.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")
    except Exception:
        pass


def analyze_with_groq(image_url: str, api_key: str) -> str:
    try:
        payload = {
            "model": os.getenv("GROQ_ANALYSIS_MODEL") or "llama-4-scout-17b-16e-instruct",
            "messages": [
                {
                    "role": "user",
                    "content": [
                        {"type": "image_url", "image_url": {"url": image_url}},
                        {"type": "text", "text": "Describe this product in detail for image generation: product type, color, material, shape, size, and distinguishing features. Be specific and concise (max 100 words)."},
                    ],
                }
            ],
            "max_tokens": 200,
        }
        response = requests.post(
            f"{PROVIDER_BASE_URLS['groq']}/chat/completions",
            headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
            json=payload,
            timeout=120,
        )
        response.raise_for_status()
        body = response.json()
        text = ((body.get("choices") or [{}])[0].get("message") or {}).get("content") or ""
        return text.strip() or "a product"
    except Exception:
        return "a product"


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


def _image_edit_endpoint(provider: str) -> str:
    provider = normalize_provider(provider)
    if provider == "google":
        model = os.getenv("GOOGLE_IMAGEN_MODEL") or "gemini-2.5-flash-image"
        return f"{PROVIDER_BASE_URLS['google']}/models/{model}:generateContent"
    if provider == "openrouter":
        return f"{PROVIDER_BASE_URLS['openrouter']}/images"
    return PROVIDER_BASE_URLS.get(provider, PROVIDER_BASE_URLS["openai"]) + "/images/edits"


def _provider_headers(provider: str, api_key: str) -> dict[str, str]:
    provider = normalize_provider(provider)
    headers = {
        "Authorization": f"Bearer {api_key}",
    }
    if provider == "google":
        headers = {"x-goog-api-key": api_key}
    if provider == "openrouter":
        headers["HTTP-Referer"] = os.getenv("OPENROUTER_HTTP_REFERER", "https://shopvivaliz.com.br")
        headers["X-Title"] = os.getenv("OPENROUTER_APP_TITLE", "ShopVivaliz")
    return headers


def _openrouter_image_model(api_key: str) -> str:
    configured = os.getenv("OPENROUTER_IMAGE_MODEL") or ""
    if configured.strip():
        return configured.strip()
    try:
        response = requests.get(
            f"{PROVIDER_BASE_URLS['openrouter']}/images/models",
            headers=_provider_headers("openrouter", api_key),
            timeout=60,
        )
        response.raise_for_status()
        body = response.json()
        models = body.get("data") if isinstance(body, dict) else []
        for item in models or []:
            if not isinstance(item, dict):
                continue
            if item.get("architecture", {}).get("output_modalities") == ["image"]:
                model_id = item.get("id")
                if isinstance(model_id, str) and model_id.strip():
                    return model_id.strip()
    except Exception:
        pass
    return "qwen/qwen-image-3"


def image_edit(provider: str, source_path: Path, prompt: str, api_key: str) -> bytes:
    provider = normalize_provider(provider)
    if provider == "google":
        with source_path.open("rb") as image_file:
            payload = {
                "contents": [{
                    "parts": [
                        {"inline_data": {"mime_type": "image/jpeg", "data": base64.b64encode(image_file.read()).decode("ascii")}},
                        {"text": prompt},
                    ]
                }],
                "generationConfig": {"responseModalities": ["IMAGE"]},
            }
        endpoint = _image_edit_endpoint(provider)
        response = requests.post(endpoint, headers={"x-goog-api-key": api_key, "Content-Type": "application/json", "Accept": "application/json"}, json=payload, timeout=180)
        if response.status_code != 200:
            raise AIProviderError(f'Gemini image edit failed ({response.status_code}): {response.text}')
        body = response.json()
        parts = (((body or {}).get('candidates') or [{}])[0].get('content') or {}).get('parts') or []
        for part in parts:
            inline = part.get('inlineData') or part.get('inline_data') or {}
            data = inline.get('data')
        if data:
            return base64.b64decode(data)
        raise AIProviderError('Gemini image edit response lacked image content')

    if provider == "openrouter":
        with source_path.open("rb") as image_file:
            image_b64 = base64.b64encode(image_file.read()).decode("ascii")
        model = _openrouter_image_model(api_key)
        payload = {
            "model": model,
            "prompt": prompt,
            "n": 1,
            "size": os.getenv("OPENROUTER_IMAGE_SIZE") or "1024x1024",
            "quality": os.getenv("OPENROUTER_IMAGE_QUALITY") or "high",
            "input_references": [
                {
                    "type": "image_url",
                    "image_url": {
                        "url": f"data:image/jpeg;base64,{image_b64}",
                    },
                }
            ],
        }
        response = requests.post(
            _image_edit_endpoint(provider),
            headers=_provider_headers(provider, api_key),
            json=payload,
            timeout=180,
        )
        if response.status_code != 200:
            raise AIProviderError(f'OpenRouter image generation failed ({response.status_code}): {response.text}')
        body = response.json()
        if not isinstance(body, dict) or 'data' not in body or not body['data']:
            if isinstance(body, dict) and body.get("imageUrl"):
                if download_image_url(str(body["imageUrl"]), Path('.cache_ai_image_download.jpg')):
                    try:
                        return Path('.cache_ai_image_download.jpg').read_bytes()
                    finally:
                        try:
                            Path('.cache_ai_image_download.jpg').unlink()
                        except OSError:
                            pass
            raise AIProviderError('OpenRouter image generation returned no data')
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
        if 'imageUrl' in image_item and image_item['imageUrl']:
            temp_path = Path('.cache_ai_image_download.jpg')
            if download_image_url(image_item['imageUrl'], temp_path):
                try:
                    return temp_path.read_bytes()
                finally:
                    try:
                        temp_path.unlink()
                    except OSError:
                        pass
        raise AIProviderError('OpenRouter image generation response lacked image content')

    endpoint = _image_edit_endpoint(provider)
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
            **_provider_headers(provider, api_key),
        }
        response = requests.post(endpoint, headers=headers, data=data, files=files, timeout=120)
    if response.status_code != 200:
        raise AIProviderError(f'{provider.title()} image edit failed ({response.status_code}): {response.text}')

    body = response.json()
    if not isinstance(body, dict) or 'data' not in body or not body['data']:
        raise AIProviderError(f'{provider.title()} image edit returned no data')

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
        raise AIProviderError(f'{provider.title()} returned image URL but download failed')

    raise AIProviderError(f'{provider.title()} image edit response lacked image content')


def render_with_provider(provider: str, source_path: Path, destination_path: Path, variant: int, api_key: str) -> bool:
    prompt = get_variant_prompt(variant)
    try:
        logger.info(f'  Generating variant {variant} with {provider}: {prompt}')
        image_bytes = image_edit(provider, source_path, prompt, api_key)
        destination_path.parent.mkdir(parents=True, exist_ok=True)
        with open(destination_path, 'wb') as f:
            f.write(image_bytes)
        logger.info(f'    Saved {provider} variant {variant} at {destination_path}')
        _append_provider_audit(provider, variant, "ok", f"saved:{destination_path}", destination_path.parent.name)
        return True
    except Exception as exc:
        logger.warning(f'{provider} generation failed for {source_path.name} variant {variant}: {exc}')
        _append_provider_audit(provider, variant, "fail", str(exc), destination_path.parent.name)
        return False


def render_ai_variant(source_path: Path, destination_path: Path, variant: int, provider: Optional[str], api_key: Optional[str]) -> bool:
    prompt = get_variant_prompt(variant)
    provider_pool = []
    if provider and api_key:
        provider_pool.append((provider, api_key))
    for candidate_provider, candidate_key in load_provider_pool():
        if all(candidate_provider != existing[0] for existing in provider_pool):
            provider_pool.append((candidate_provider, candidate_key))

    for candidate_provider, candidate_key in provider_pool:
        if not _provider_available(candidate_provider):
            logger.info(f'Skipping {candidate_provider} for variant {variant}: provider in cooldown')
            _append_provider_audit(candidate_provider, variant, "skip", "cooldown", destination_path.parent.name)
            continue
        if candidate_provider == "groq":
            # Groq atua como analisador/otimizador textual; a geração final
            # permanece com provedores image-capable.
            try:
                refined = analyze_with_groq("file://" + str(source_path), candidate_key)
                logger.info(f'Groq analysis for variant {variant}: {refined[:120]}')
                _append_provider_audit(candidate_provider, variant, "analysis", refined[:220], destination_path.parent.name)
                continue
            except Exception as exc:
                logger.warning(f'Groq analysis failed for {source_path.name} variant {variant}: {exc}')
                _append_provider_audit(candidate_provider, variant, "analysis_fail", str(exc), destination_path.parent.name)
                continue
        if render_with_provider(candidate_provider, source_path, destination_path, variant, candidate_key):
            return True
        _mark_provider_cooldown(candidate_provider)

    logger.warning('All AI providers failed; falling back to original image for this variant')
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
