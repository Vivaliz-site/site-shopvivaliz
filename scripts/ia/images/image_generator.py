"""
Geração de 4 imagens IA por produto usando GPT-4o Vision (análise) + DALL-E 3 (geração).
Tipos: fundo_branco, angulo_45, lifestyle, close_up
"""
import base64
import json
import os
import time
from pathlib import Path

import openai
import requests

_client = None

IMAGE_TYPES = ["fundo_branco", "angulo_45", "lifestyle", "close_up"]

STORAGE_DIR = Path(__file__).parents[3] / "storage" / "ia_images"
STORAGE_DIR.mkdir(parents=True, exist_ok=True)
PROVIDER_HEALTH_FILE = Path(__file__).parents[3] / "storage" / "ai-provider-health.json"
PROVIDER_AUDIT_LOG = Path(__file__).parents[3] / "storage" / "ai-provider-audit.jsonl"

_PROMPTS = {
    "fundo_branco": "Product photography on pure white background, studio lighting, sharp focus, professional e-commerce style, no shadows",
    "angulo_45": "Product photography at 45-degree angle, clean light gray background, professional commercial photo, showing product depth",
    "lifestyle": "Product in real lifestyle context, natural environment, aspirational mood, warm natural lighting, person using or near the product",
    "close_up": "Extreme close-up product detail shot, macro photography, showing texture and quality, soft bokeh background, studio lighting",
}

PROVIDER_BASE_URLS = {
    "openai": "https://api.openai.com/v1",
    "openrouter": "https://openrouter.ai/api/v1",
    "groq": "https://api.groq.com/openai/v1",
    "google": "https://generativelanguage.googleapis.com/v1beta",
}


def _openai() -> openai.OpenAI:
    global _client
    if _client is None:
        _client = openai.OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    return _client


def _provider_pool() -> list[tuple[str, str]]:
    pool = [
        ("openai", os.getenv("OPENAI_API_KEY") or ""),
        ("google", os.getenv("GEMINI_API_KEY") or os.getenv("GOOGLE_IMAGEN_API_KEY") or os.getenv("GOOGLE_GEMINI_API_KEY") or ""),
        ("openrouter", os.getenv("OPENROUTER_API_KEY") or ""),
        ("groq", os.getenv("GROQ_API_KEY") or ""),
    ]
    return [(name, key.strip()) for name, key in pool if key and key.strip()]


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
    state[_normalize_provider(provider)] = time.time() + seconds
    _write_provider_health(state)


def _provider_available(provider: str) -> bool:
    state = _read_provider_health()
    expiry = state.get(_normalize_provider(provider))
    return not expiry or expiry <= time.time()


def _append_provider_audit(provider: str, image_type: str, status: str, message: str, sku: str = "") -> None:
    try:
        PROVIDER_AUDIT_LOG.parent.mkdir(parents=True, exist_ok=True)
        record = {
            "ts": time.time(),
            "provider": _normalize_provider(provider),
            "image_type": image_type,
            "status": status,
            "message": message[:500],
            "sku": sku,
        }
        with PROVIDER_AUDIT_LOG.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")
    except Exception:
        pass


def _groq_analyze(product_description: str, image_url: str | None = None) -> str:
    key = os.getenv("GROQ_API_KEY") or ""
    if not key:
        return product_description
    payload = {
        "model": os.getenv("GROQ_ANALYSIS_MODEL") or "llama-4-scout-17b-16e-instruct",
        "messages": [{
            "role": "user",
            "content": [
                *([{"type": "image_url", "image_url": {"url": image_url}}] if image_url else []),
                {"type": "text", "text": f"Refine this product description for image generation, preserving facts only: {product_description}"},
            ],
        }],
        "max_tokens": 200,
    }
    try:
        resp = requests.post(
            f"{PROVIDER_BASE_URLS['groq']}/chat/completions",
            headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json", "Accept": "application/json"},
            json=payload,
            timeout=120,
        )
        resp.raise_for_status()
        body = resp.json()
        text = ((body.get("choices") or [{}])[0].get("message") or {}).get("content") or ""
        return text.strip() or product_description
    except Exception:
        return product_description


def _normalize_provider(provider: str | None) -> str:
    provider = (provider or "").strip().lower()
    return {
        "gpt": "openai",
        "openai": "openai",
        "gemini": "google",
        "google": "google",
        "openrouter": "openrouter",
        "groq": "groq",
        "qrope": "groq",
    }.get(provider, provider)


def _analyze_product_image(image_url: str) -> str:
    """Usa GPT-4o Vision para descrever o produto na imagem."""
    try:
        resp = _openai().chat.completions.create(
            model=os.getenv("OPENAI_VISION_MODEL") or os.getenv("OPENAI_MODEL") or "gpt-4o-mini",
            messages=[{
                "role": "user",
                "content": [
                    {"type": "image_url", "image_url": {"url": image_url, "detail": os.getenv("OPENAI_VISION_DETAIL", "low")}},
                    {"type": "text", "text": "Describe this product in detail for image generation: product type, color, material, shape, size, any distinguishing features. Be specific and concise (max 100 words)."},
                ],
            }],
            max_tokens=200,
        )
        return resp.choices[0].message.content.strip()
    except Exception:
        return "a product"


def _analyze_product_image_google(image_url: str, api_key: str) -> str:
    try:
        response = requests.get(image_url, timeout=60)
        response.raise_for_status()
        image_bytes = response.content
        payload = {
            "contents": [{
                "parts": [
                    {"inline_data": {"mime_type": "image/jpeg", "data": base64.b64encode(image_bytes).decode("ascii")}},
                    {"text": "Describe this product in detail for image generation: product type, color, material, shape, size, any distinguishing features. Be specific and concise (max 100 words)."},
                ]
            }],
            "generationConfig": {"maxOutputTokens": 200},
        }
        model = os.getenv("GOOGLE_IMAGEN_MODEL") or "gemini-2.5-flash-image"
        url = f"{PROVIDER_BASE_URLS['google']}/models/{model}:generateContent?key={api_key}"
        resp = requests.post(url, json=payload, timeout=120)
        resp.raise_for_status()
        body = resp.json()
        parts = ((body.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
        text_parts = []
        for part in parts:
            text = part.get("text") if isinstance(part, dict) else None
            if isinstance(text, str) and text.strip():
                text_parts.append(text.strip())
        return "\n".join(text_parts).strip() or "a product"
    except Exception:
        return "a product"


def _provider_image_url(provider: str) -> str:
    provider = _normalize_provider(provider)
    if provider == "google":
        model = os.getenv("GOOGLE_IMAGEN_MODEL") or "gemini-2.5-flash-image"
        return f"{PROVIDER_BASE_URLS['google']}/models/{model}:generateContent"
    if provider == "openrouter":
        return f"{PROVIDER_BASE_URLS['openrouter']}/images"
    return f"{PROVIDER_BASE_URLS.get(provider, PROVIDER_BASE_URLS['openai'])}/images/generations"


def _provider_headers(provider: str, api_key: str) -> dict[str, str]:
    provider = _normalize_provider(provider)
    if provider == "google":
        return {"x-goog-api-key": api_key, "Content-Type": "application/json", "Accept": "application/json"}
    headers = {"Authorization": f"Bearer {api_key}", "Content-Type": "application/json", "Accept": "application/json"}
    if provider == "openrouter":
        headers["HTTP-Referer"] = os.getenv("OPENROUTER_HTTP_REFERER", "https://shopvivaliz.com.br")
        headers["X-Title"] = os.getenv("OPENROUTER_APP_TITLE", "ShopVivaliz")
    return headers


def _openrouter_image_model(api_key: str) -> str:
    configured = os.getenv("OPENROUTER_IMAGE_MODEL") or ""
    if configured.strip():
        return configured.strip()
    try:
        resp = requests.get(
            f"{PROVIDER_BASE_URLS['openrouter']}/images/models",
            headers=_provider_headers("openrouter", api_key),
            timeout=60,
        )
        resp.raise_for_status()
        body = resp.json()
        for item in body.get("data") or []:
            if not isinstance(item, dict):
                continue
            arch = item.get("architecture") or {}
            if arch.get("output_modalities") == ["image"]:
                model_id = item.get("id")
                if isinstance(model_id, str) and model_id.strip():
                    return model_id.strip()
    except Exception:
        pass
    return "qwen/qwen-image-3"


def _generate_one(provider: str, product_description: str, image_type: str, retry: int = 2) -> bytes | None:
    """Gera uma imagem via provider compatível e retorna os bytes."""
    base_prompt = _PROMPTS[image_type]
    full_prompt = f"{base_prompt}. Product: {product_description}. High quality, commercial photography."

    api_key = next((key for name, key in _provider_pool() if name == _normalize_provider(provider)), "")
    if not api_key:
        return None

    for attempt in range(1, retry + 2):
        try:
            if _normalize_provider(provider) == "google":
                payload = {
                    "contents": [{"parts": [{"text": full_prompt}]}],
                    "generationConfig": {"responseModalities": ["IMAGE"]},
                }
                model = os.getenv("GOOGLE_IMAGEN_MODEL") or "gemini-2.5-flash-image"
                url = f"{PROVIDER_BASE_URLS['google']}/models/{model}:generateContent?key={api_key}"
                resp = requests.post(url, json=payload, timeout=180)
                resp.raise_for_status()
                body = resp.json()
                parts = ((body.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
                for part in parts:
                    inline = part.get("inlineData") or part.get("inline_data") or {}
                    data = inline.get("data") if isinstance(inline, dict) else None
                    if isinstance(data, str) and data:
                        return base64.b64decode(data)
                raise AIProviderError("Gemini image response lacked binary image data")

            if _normalize_provider(provider) == "openrouter":
                payload = {
                    "model": _openrouter_image_model(api_key),
                    "prompt": full_prompt,
                    "n": 1,
                    "size": os.getenv("OPENROUTER_IMAGE_SIZE") or "1024x1024",
                    "quality": os.getenv("OPENROUTER_IMAGE_QUALITY") or "high",
                }
                resp = requests.post(_provider_image_url(provider), headers=_provider_headers(provider, api_key), json=payload, timeout=180)
                if resp.status_code != 200:
                    raise AIProviderError(f"{_normalize_provider(provider)} image generate failed ({resp.status_code}): {resp.text}")
                body = resp.json()
                data = body.get("data") or []
                if not data:
                    raise AIProviderError(f"{_normalize_provider(provider)} image generate returned no data")
                item = data[0]
                if item.get("b64_json"):
                    return base64.b64decode(item["b64_json"])
                if item.get("url"):
                    r = requests.get(item["url"], timeout=30)
                    r.raise_for_status()
                    return r.content
                if item.get("imageUrl"):
                    r = requests.get(item["imageUrl"], timeout=30)
                    r.raise_for_status()
                    return r.content
                raise AIProviderError(f"{_normalize_provider(provider)} image generate response lacked image content")

            url = _provider_image_url(provider)
            payload = {
                "model": os.getenv("OPENAI_IMAGE_MODEL") or os.getenv("OPENROUTER_IMAGE_MODEL") or os.getenv("GROQ_IMAGE_MODEL") or "gpt-image-1",
                "prompt": full_prompt,
                "size": "1024x1024",
                "n": 1,
            }
            resp = requests.post(url, headers=_provider_headers(provider, api_key), json=payload, timeout=180)
            if resp.status_code != 200:
                raise AIProviderError(f"{_normalize_provider(provider)} image generate failed ({resp.status_code}): {resp.text}")
            body = resp.json()
            data = body.get("data") or []
            if not data:
                raise AIProviderError(f"{_normalize_provider(provider)} image generate returned no data")
            item = data[0]
            if item.get("b64_json"):
                return base64.b64decode(item["b64_json"])
            if item.get("url"):
                r = requests.get(item["url"], timeout=30)
                r.raise_for_status()
                return r.content
            raise AIProviderError(f"{_normalize_provider(provider)} image generate response lacked image content")
        except Exception as exc:
            if attempt > retry:
                _append_provider_audit(provider, image_type, "fail", str(exc))
                break
            _append_provider_audit(provider, image_type, "retry", str(exc))
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
    if image_url and _normalize_provider(os.getenv("AI_PROVIDER")) == "google" and os.getenv("GEMINI_API_KEY"):
        product_description = _analyze_product_image_google(image_url, os.getenv("GEMINI_API_KEY", ""))
    elif image_url and os.getenv("GROQ_API_KEY"):
        product_description = _groq_analyze(product_description if (product_description := (product.get("item_name") or product.get("title") or "product")) else "product", image_url)
    else:
        product_description = (
            _analyze_product_image(image_url) if image_url
            else (product.get("item_name") or product.get("title") or "product")
        )

    results: dict[str, Path | None] = {}
    for img_type in IMAGE_TYPES:
        data = None
        providers = _provider_pool()
        preferred = _normalize_provider(os.getenv("AI_PROVIDER"))
        if preferred:
            providers = [(name, key) for name, key in providers if name == preferred] + [(name, key) for name, key in providers if name != preferred]
        for provider, _api_key in providers:
            if not _provider_available(provider):
                continue
            if provider == "groq":
                product_description = _groq_analyze(product_description, image_url)
                _append_provider_audit(provider, img_type, "analysis", product_description, sku)
                continue
            data = _generate_one(provider, product_description, img_type)
            if data:
                _append_provider_audit(provider, img_type, "ok", "generated", sku)
                break
            _append_provider_audit(provider, img_type, "cooldown", "generation_failed", sku)
            _mark_provider_cooldown(provider)
        results[img_type] = _save(data, sku, img_type) if data else None
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
