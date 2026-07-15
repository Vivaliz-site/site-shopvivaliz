"""
Priorização de produtos por IA.
Score 0-100 baseado em potencial de conversão vs estado atual do anúncio.
"""
import json
import os
from typing import Any

import openai

_client = None


def _openai() -> openai.OpenAI:
    global _client
    if _client is None:
        _client = openai.OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    return _client


def _build_prompt(products: list[dict]) -> str:
    items = []
    for p in products:
        items.append({
            "id": p.get("item_id") or p.get("id") or p.get("product_id"),
            "title": (p.get("item_name") or p.get("title") or "")[:80],
            "category": p.get("category_id") or p.get("category", ""),
            "price": _extract_price(p),
            "images_count": _count_images(p),
            "has_description": bool((p.get("description") or "").strip()),
        })
    return f"""Você é um especialista em e-commerce. Avalie os produtos abaixo e retorne um JSON com score de prioridade de 0 a 100 para cada um.

Critérios de MAIOR prioridade (score alto):
- Produto sem imagens ou com poucas imagens (impacto imediato na conversão)
- Título vago ou genérico (SEO fraco)
- Categoria com alta margem (eletrônicos, beleza, moda)
- Preço competitivo (produto acessível vende mais com boa imagem)

Critérios de MENOR prioridade (score baixo):
- Produto já com 5+ imagens de qualidade
- Descrição completa e detalhada
- Categorias de baixa margem

Produtos:
{json.dumps(items, ensure_ascii=False, indent=2)}

Retorne APENAS JSON válido neste formato:
{{"scores": [{{"id": "...", "score": 85, "reason": "sem imagens"}}]}}"""


def _extract_price(p: dict) -> float:
    price_info = p.get("price_info", [])
    if price_info:
        return float(price_info[0].get("original_price", 0))
    return float(p.get("price", 0) or 0)


def _count_images(p: dict) -> int:
    img = p.get("image", {})
    if img:
        return len(img.get("image_url_list") or img.get("image_id_list") or [])
    main_imgs = p.get("main_images") or []
    return len(main_imgs)


def score_products(products: list[dict]) -> list[dict]:
    """
    Retorna os produtos ordenados por score decrescente (mais urgente primeiro).
    Fallback determinístico se IA falhar.
    """
    if not products:
        return []

    try:
        resp = _openai().chat.completions.create(
            model="gpt-4o-mini",
            messages=[{"role": "user", "content": _build_prompt(products)}],
            response_format={"type": "json_object"},
            temperature=0,
            max_tokens=2000,
        )
        result = json.loads(resp.choices[0].message.content)
        score_map = {str(s["id"]): s["score"] for s in result.get("scores", [])}
    except Exception:
        score_map = {}

    def _fallback_score(p: dict) -> int:
        imgs = _count_images(p)
        has_desc = bool((p.get("description") or "").strip())
        score = 50
        if imgs == 0:
            score += 40
        elif imgs < 3:
            score += 20
        if not has_desc:
            score += 10
        return min(score, 100)

    for p in products:
        pid = str(p.get("item_id") or p.get("id") or p.get("product_id") or "")
        p["_priority_score"] = score_map.get(pid) or _fallback_score(p)

    return sorted(products, key=lambda x: x["_priority_score"], reverse=True)
