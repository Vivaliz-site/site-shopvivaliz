"""
Integração TikTok Shop: publica SEO e imagens via TikTok Shop Open API.
NUNCA altera preço, estoque ou variantes.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[1]))

from utils import logger
from utils.tiktok_client import TikTokClient
from ia.abtest.ab_manager import get_winner_image_type


def publish(
    product: dict,
    seo: dict,
    images: dict[str, Path | None],
    client: TikTokClient | None = None,
) -> dict:
    """
    Atualiza título, descrição e imagens do produto no TikTok Shop.
    Retorna dict com status e detalhes.
    """
    client = client or TikTokClient()
    product_id = str(product.get("id") or product.get("product_id") or product.get("item_id") or "")
    pid = product_id

    result = {"product_id": pid, "platform": "tiktok", "status": "pending"}

    # ── Upload de imagens ──────────────────────────────────────────────────────
    image_urls: list[str] = []
    winner_type = get_winner_image_type(images, "tiktok")

    # Upload na ordem: winner primeiro (lifestyle preferido no TikTok)
    ordered_types = [winner_type] + [t for t in ["lifestyle", "fundo_branco", "angulo_45", "close_up"] if t != winner_type]

    for img_type in ordered_types:
        path = images.get(img_type)
        if not path or not path.exists():
            continue
        try:
            url = client.upload_image(str(path))
            image_urls.append(url)
            logger.ok("tiktok.upload", f"imagem {img_type} enviada → {url[:60]}...", pid)
        except Exception as e:
            logger.warn("tiktok.upload", f"falha ao enviar {img_type}: {e}", pid)

    if not image_urls:
        logger.warn("tiktok.publish", "nenhuma imagem enviada, atualizando apenas SEO", pid)

    # Montar descrição com hashtags TikTok
    description = seo.get("description") or ""
    hashtags = seo.get("hashtags") or []
    if hashtags:
        tags_str = " ".join(f"#{t.lstrip('#')}" for t in hashtags[:10])
        description = f"{description}\n\n{tags_str}".strip()

    # ── Atualizar produto ──────────────────────────────────────────────────────
    try:
        resp = client.update_product(
            product_id=product_id,
            title=seo.get("title"),
            description=description,
            image_urls=image_urls if image_urls else None,
        )
        result["status"] = "ok"
        result["image_urls"] = image_urls
        result["title"] = seo.get("title")
        result["seo_source"] = seo.get("source")
        logger.ok("tiktok.publish", f"produto atualizado com {len(image_urls)} imagens", pid)
    except Exception as e:
        result["status"] = "error"
        result["error"] = str(e)
        logger.error("tiktok.publish", f"falha ao atualizar: {e}", pid)

    return result
