"""
Pipeline principal ShopVivaliz — automação completa via Shopee + TikTok APIs.

Fluxo:
  1. Buscar produtos das APIs (Shopee + TikTok)
  2. Priorizar com IA (score 0-100)
  3. Para cada produto:
     a. Gerar SEO (Shopee → palavras-chave | TikTok → emocional)
     b. Gerar 4 imagens IA (fundo_branco, angulo_45, lifestyle, close_up)
     c. Validar imagens / regenerar ruins
     d. Publicar na Shopee (título + descrição + imagens)
     e. Publicar no TikTok (título + descrição + imagens)
  4. Análise de CTR → marcar produtos para próxima rodada
  5. Salvar logs

Regras: NUNCA altera preço. NUNCA trava em erro individual.
"""
import argparse
import os
import sys
import time
from pathlib import Path
from datetime import datetime, timezone

# Ajustar sys.path para importações relativas funcionarem
sys.path.insert(0, str(Path(__file__).parent))

from dotenv import load_dotenv
load_dotenv()

from utils import logger
from utils.shopee_client import ShopeeClient
from utils.tiktok_client import TikTokClient
from ia.priority.product_prioritizer import score_products
from ia.seo import shopee_seo, tiktok_seo
from ia.images.image_generator import generate_for_product
from ia.images.image_validator import validate_batch, needs_regeneration
from ia.abtest.ab_manager import register_session
from ia.analytics.ctr_monitor import products_needing_new_images
from integrations import shopee_ia as shopee_publisher
from integrations import tiktok_ia as tiktok_publisher


def parse_args():
    p = argparse.ArgumentParser(description="Pipeline IA ShopVivaliz")
    p.add_argument("--limit", type=int, default=0, help="Máximo de produtos a processar (0 = todos)")
    p.add_argument("--platform", choices=["shopee", "tiktok", "both"], default="both")
    p.add_argument("--skip-images", action="store_true", help="Pular geração de imagens")
    p.add_argument("--skip-seo", action="store_true", help="Pular geração de SEO")
    p.add_argument("--analytics-only", action="store_true", help="Apenas análise de CTR, sem publicação")
    p.add_argument("--dry-run", action="store_true", help="Executa tudo mas não publica")
    return p.parse_args()


def fetch_products(args, shopee: ShopeeClient, tiktok: TikTokClient) -> list[dict]:
    """Busca produtos de Shopee e/ou TikTok e os une em lista normalizada."""
    products = []

    if args.platform in ("shopee", "both"):
        logger.info("pipeline", "buscando produtos Shopee...")
        try:
            shopee_items = list(shopee.iter_all_products())
            logger.info("pipeline", f"{len(shopee_items)} produtos encontrados na Shopee")
            # Normalizar campos
            for item in shopee_items:
                item["_platform_primary"] = "shopee"
                item["_id"] = str(item.get("item_id") or item.get("id") or "")
            # Buscar detalhes em lote
            ids = [int(i["item_id"]) for i in shopee_items if i.get("item_id")]
            if ids:
                details = shopee.get_product_details(ids)
                detail_map = {str(d.get("item_id")): d for d in details}
                for item in shopee_items:
                    pid = str(item.get("item_id") or "")
                    if pid in detail_map:
                        item.update(detail_map[pid])
            products.extend(shopee_items)
        except Exception as e:
            logger.error("pipeline", f"falha ao buscar produtos Shopee: {e}")

    if args.platform in ("tiktok", "both"):
        logger.info("pipeline", "buscando produtos TikTok...")
        try:
            tiktok_items = list(tiktok.iter_all_products())
            logger.info("pipeline", f"{len(tiktok_items)} produtos encontrados no TikTok")
            for item in tiktok_items:
                item["_platform_primary"] = "tiktok"
                item["_id"] = str(item.get("id") or item.get("product_id") or "")
            products.extend(tiktok_items)
        except Exception as e:
            logger.error("pipeline", f"falha ao buscar produtos TikTok: {e}")

    return products


def run_analytics(shopee: ShopeeClient, tiktok: TikTokClient) -> set[str]:
    """Busca métricas e retorna IDs de produtos com CTR baixo."""
    shopee_metrics, tiktok_metrics = [], []
    try:
        shopee_metrics = shopee.get_product_metrics([])
    except Exception:
        pass
    try:
        tiktok_metrics = tiktok.get_product_metrics([])
    except Exception:
        pass
    return products_needing_new_images(shopee_metrics, tiktok_metrics)


def process_product(
    product: dict,
    args,
    shopee: ShopeeClient,
    tiktok: TikTokClient,
    force_regen: bool = False,
) -> dict:
    """Processa um único produto: SEO → imagens → publicação."""
    pid = product.get("_id") or str(product.get("item_id") or product.get("id") or "unknown")
    platform = product.get("_platform_primary", "both")
    result = {"product_id": pid, "shopee": None, "tiktok": None}

    logger.info("produto", f"processando (score={product.get('_priority_score', '?')})", pid)

    # ── SEO ───────────────────────────────────────────────────────────────────
    shopee_seo_data = {"title": product.get("item_name") or product.get("title") or "", "description": "", "source": "original"}
    tiktok_seo_data = {"title": product.get("title") or product.get("item_name") or "", "description": "", "source": "original"}

    if not args.skip_seo:
        if platform in ("shopee", "both"):
            try:
                shopee_seo_data = shopee_seo.generate(product)
                logger.ok("seo.shopee", f"gerado: {shopee_seo_data['title'][:50]}...", pid)
            except Exception as e:
                logger.warn("seo.shopee", f"falha, usando original: {e}", pid)

        if platform in ("tiktok", "both"):
            try:
                tiktok_seo_data = tiktok_seo.generate(product)
                logger.ok("seo.tiktok", f"gerado: {tiktok_seo_data['title'][:50]}...", pid)
            except Exception as e:
                logger.warn("seo.tiktok", f"falha, usando original: {e}", pid)

    # ── Imagens ───────────────────────────────────────────────────────────────
    images: dict = {}

    if not args.skip_images:
        try:
            images = generate_for_product(product)
            validation = validate_batch(images)
            bad_types = needs_regeneration(validation)

            if bad_types:
                logger.warn("imagens", f"tipos ruins: {bad_types}, regenerando...", pid)
                for bad in bad_types:
                    # Segunda tentativa para tipos ruins
                    from ia.images.image_generator import _generate_one, _save, _analyze_product_image, _get_first_image_url
                    image_url = _get_first_image_url(product)
                    desc = _analyze_product_image(image_url) if image_url else (product.get("item_name") or "product")
                    data = _generate_one(desc, bad)
                    if data:
                        images[bad] = _save(data, pid, bad)
                        logger.ok("imagens", f"tipo {bad} regenerado com sucesso", pid)
                    else:
                        logger.warn("imagens", f"tipo {bad} não regenerado, usando fallback", pid)

            good_count = sum(1 for ok, _ in validate_batch(images).values() if ok)
            logger.ok("imagens", f"{good_count}/4 imagens válidas", pid)
        except Exception as e:
            logger.error("imagens", f"falha na geração: {e}", pid)

    # ── Publicação ────────────────────────────────────────────────────────────
    if args.dry_run:
        logger.info("publish", "dry-run: pulando publicação", pid)
        result["shopee"] = {"status": "dry-run"}
        result["tiktok"] = {"status": "dry-run"}
        return result

    if platform in ("shopee", "both"):
        try:
            result["shopee"] = shopee_publisher.publish(product, shopee_seo_data, images, shopee)
        except Exception as e:
            result["shopee"] = {"status": "error", "error": str(e)}
            logger.error("publish.shopee", f"erro inesperado: {e}", pid)

    if platform in ("tiktok", "both"):
        try:
            result["tiktok"] = tiktok_publisher.publish(product, tiktok_seo_data, images, tiktok)
        except Exception as e:
            result["tiktok"] = {"status": "error", "error": str(e)}
            logger.error("publish.tiktok", f"erro inesperado: {e}", pid)

    # ── A/B Test ──────────────────────────────────────────────────────────────
    if images:
        register_session(pid, platform, "fundo_branco", "lifestyle")

    return result


def main():
    args = parse_args()
    start = datetime.now(timezone.utc)
    logger.info("pipeline", f"=== início {start.strftime('%Y-%m-%dT%H:%M:%SZ')} ===")
    logger.info("pipeline", f"plataforma={args.platform} | limit={args.limit or 'todos'} | dry-run={args.dry_run}")

    shopee = ShopeeClient()
    tiktok = TikTokClient()

    # ── Analytics primeiro (identifica produtos com CTR baixo) ────────────────
    logger.info("analytics", "verificando CTR dos produtos...")
    low_ctr_ids: set[str] = set()
    if not args.analytics_only:
        try:
            low_ctr_ids = run_analytics(shopee, tiktok)
            if low_ctr_ids:
                logger.warn("analytics", f"{len(low_ctr_ids)} produtos com CTR baixo marcados para regeneração")
        except Exception as e:
            logger.warn("analytics", f"falha na análise CTR (continuando): {e}")

    if args.analytics_only:
        logger.info("pipeline", "modo analytics-only: encerrando")
        return

    # ── Buscar produtos ───────────────────────────────────────────────────────
    products = fetch_products(args, shopee, tiktok)
    if not products:
        logger.warn("pipeline", "nenhum produto encontrado — verifique credenciais nos secrets")
        return

    # ── Priorizar ─────────────────────────────────────────────────────────────
    logger.info("priority", f"priorizando {len(products)} produtos com IA...")
    try:
        products = score_products(products)
        logger.ok("priority", f"ordenados por score: top={products[0].get('_priority_score', '?')}")
    except Exception as e:
        logger.warn("priority", f"falha na priorização (usando ordem original): {e}")

    if args.limit:
        products = products[: args.limit]
        logger.info("pipeline", f"limitado a {args.limit} produtos")

    # ── Processar cada produto ────────────────────────────────────────────────
    total = len(products)
    ok_count = error_count = 0

    for i, product in enumerate(products, 1):
        pid = product.get("_id") or str(product.get("item_id") or product.get("id") or "")
        force_regen = pid in low_ctr_ids
        if force_regen:
            logger.warn("pipeline", f"produto {i}/{total} com CTR baixo → forçando regeneração de imagem", pid)
        else:
            logger.info("pipeline", f"produto {i}/{total}", pid)

        try:
            result = process_product(product, args, shopee, tiktok, force_regen)
            shopee_ok = (result.get("shopee") or {}).get("status") in ("ok", "dry-run")
            tiktok_ok = (result.get("tiktok") or {}).get("status") in ("ok", "dry-run")
            if shopee_ok or tiktok_ok:
                ok_count += 1
            else:
                error_count += 1
        except Exception as e:
            error_count += 1
            logger.error("pipeline", f"erro não capturado no produto {i}: {e}", pid)

        # Respeitar rate limit entre produtos
        if i < total:
            time.sleep(1.5)

    # ── Resumo ────────────────────────────────────────────────────────────────
    elapsed = (datetime.now(timezone.utc) - start).total_seconds()
    logger.ok("pipeline", f"=== concluído em {elapsed:.0f}s | {ok_count} OK | {error_count} erros ===")


if __name__ == "__main__":
    main()
