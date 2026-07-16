"""
Integração Shopee: publica SEO e imagens via Shopee Partner API.
NUNCA altera preço, estoque ou logística.
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[1]))

from utils import logger
from utils.shopee_client import ShopeeClient
from ia.abtest.ab_manager import get_winner_image_type


def publish(
    product: dict,
    seo: dict,
    images: dict[str, Path | None],
    client: ShopeeClient | None = None,
) -> dict:
    """
    Atualiza título, descrição e imagens do produto na Shopee.
    Retorna dict com status e detalhes.
    """
    client = client or ShopeeClient()
    item_id = product.get("item_id") or product.get("id")
    pid = str(item_id)

    result = {"product_id": pid, "platform": "shopee", "status": "pending"}

    # ── Upload de imagens ──────────────────────────────────────────────────────
    image_ids: list[str] = []
    winner_type = get_winner_image_type(images, "shopee")

    # Upload na ordem: winner primeiro, depois demais tipos
    ordered_types = [winner_type] + [t for t in ["fundo_branco", "angulo_45", "lifestyle", "close_up"] if t != winner_type]

    for img_type in ordered_types:
        path = images.get(img_type)
        if not path or not path.exists():
            continue
        try:
            image_id = client.upload_image(str(path))
            image_ids.append(image_id)
            logger.ok("shopee.upload", f"imagem {img_type} enviada → {image_id}", pid)
        except Exception as e:
            logger.warn("shopee.upload", f"falha ao enviar {img_type}: {e}", pid)

    if not image_ids:
        logger.warn("shopee.publish", "nenhuma imagem enviada, atualizando apenas SEO", pid)

    # ── Atualizar produto ──────────────────────────────────────────────────────
    try:
        resp = client.update_product(
            item_id=int(item_id),
            title=seo.get("title"),
            description=seo.get("description"),
            image_ids=image_ids if image_ids else None,
        )
        result["status"] = "ok"
        result["image_ids"] = image_ids
        result["title"] = seo.get("title")
        result["seo_source"] = seo.get("source")
        logger.ok("shopee.publish", f"produto atualizado com {len(image_ids)} imagens", pid)
    except Exception as e:
        result["status"] = "error"
        result["error"] = str(e)
        logger.error("shopee.publish", f"falha ao atualizar: {e}", pid)

    return result
