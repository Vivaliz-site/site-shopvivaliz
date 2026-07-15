"""
Geração de SEO para TikTok Shop.
Foco: copy emocional, storytelling curto, hashtags virais.
"""
import json
import os

import openai

_client = None


def _openai() -> openai.OpenAI:
    global _client
    if _client is None:
        _client = openai.OpenAI(api_key=os.environ["OPENAI_API_KEY"])
    return _client


def generate(product: dict) -> dict:
    """
    Gera título e descrição TikTok-style (emocional, curto, viral).
    Retorna dict com 'title', 'description', 'hashtags'. Fallback se IA falhar.
    """
    title_orig = product.get("title") or product.get("item_name") or ""
    category = str(product.get("category_id") or product.get("category") or "")
    description_orig = (product.get("description") or "")[:300]
    price = _extract_price(product)

    prompt = f"""Você é um especialista em copywriting para TikTok Shop Brasil. Crie conteúdo que gera conversão imediata.

PRODUTO:
- Nome: {title_orig}
- Categoria: {category}
- Preço: R$ {price:.2f}
- Descrição: {description_orig or "(sem descrição)"}

ESTILO TIKTOK:
- Título: até 255 chars, linguagem casual/emocional, cria desejo/urgência
- Descrição: máximo 3 frases impactantes, fala com o cliente diretamente (você), CTA claro
- Hashtags: 8-12 hashtags mix (produto + tendência + lifestyle), sem #

Retorne APENAS JSON:
{{"title": "...", "description": "...", "hashtags": ["hashtag1", "hashtag2"]}}"""

    try:
        resp = _openai().chat.completions.create(
            model="gpt-4o-mini",
            messages=[{"role": "user", "content": prompt}],
            response_format={"type": "json_object"},
            temperature=0.7,
            max_tokens=800,
        )
        data = json.loads(resp.choices[0].message.content)
        return {
            "title": (data.get("title") or title_orig)[:255],
            "description": data.get("description") or description_orig,
            "hashtags": data.get("hashtags") or [],
            "source": "ai",
        }
    except Exception:
        return {
            "title": title_orig[:255],
            "description": description_orig,
            "hashtags": [],
            "source": "fallback",
        }


def _extract_price(p: dict) -> float:
    price_info = p.get("price_info", [])
    if price_info:
        return float(price_info[0].get("original_price", 0))
    return float(p.get("price", 0) or 0)
