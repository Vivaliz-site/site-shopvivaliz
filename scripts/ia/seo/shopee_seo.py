"""
Geração de SEO para Shopee.
Foco: palavras-chave de busca, título até 120 chars, descrição estruturada.
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


def generate(product: dict) -> dict:
    """
    Gera título SEO e descrição otimizados para Shopee.
    Retorna dict com 'title' e 'description'. Fallback se IA falhar.
    """
    title_orig = product.get("item_name") or product.get("title") or ""
    category = str(product.get("category_id") or product.get("category") or "")
    description_orig = (product.get("description") or "")[:500]
    price = _extract_price(product)

    prompt = f"""Você é especialista em SEO para Shopee Brasil. Crie título e descrição otimizados para o produto abaixo.

PRODUTO:
- Nome original: {title_orig}
- Categoria ID: {category}
- Preço: R$ {price:.2f}
- Descrição atual: {description_orig or "(sem descrição)"}

REGRAS SHOPEE:
- Título: máximo 120 caracteres, rico em palavras-chave de busca, inclua marca/modelo/material/uso
- Descrição: estruturada com emojis, bullets dos benefícios, especificações, palavras-chave naturais
- NÃO mencione concorrentes nem promessas falsas
- Idioma: português brasileiro

Retorne APENAS JSON:
{{"title": "...", "description": "..."}}"""

    try:
        resp = _openai().chat.completions.create(
            model="gpt-4o-mini",
            messages=[{"role": "user", "content": prompt}],
            response_format={"type": "json_object"},
            temperature=0.3,
            max_tokens=1200,
        )
        data = json.loads(resp.choices[0].message.content)
        title = (data.get("title") or title_orig)[:120]
        description = data.get("description") or description_orig
        return {"title": title, "description": description, "source": "ai"}
    except Exception:
        return {"title": title_orig[:120], "description": description_orig, "source": "fallback"}


def _extract_price(p: dict) -> float:
    price_info = p.get("price_info", [])
    if price_info:
        return float(price_info[0].get("original_price", 0))
    return float(p.get("price", 0) or 0)
