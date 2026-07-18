#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Executa upload de imagens nos marketplaces Shopee e TikTok Shop
Usa as credenciais dos GitHub Secrets via variáveis de ambiente
"""
import os
import sys
import json
import logging
import tempfile
import requests
from pathlib import Path
from datetime import datetime

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass  # Em producao/CI as credenciais ja vem via env real (GitHub Secrets/systemd Environment)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

UPLOAD_MAPPING_FILE = Path('storage/uploaded_urls.csv')
EXECUTION_LOG = Path('logs/marketplace_upload_execution.json')


def check_marketplace_credentials() -> dict:
    """Verifica credenciais disponíveis dos marketplaces"""
    creds = {
        'shopee': {
            'partner_id': os.environ.get('SHOPEE_PARTNER_ID') or os.environ.get('SHOPEE_TEST_PARTNER_ID'),
            'partner_key': os.environ.get('SHOPEE_PARTNER_KEY') or os.environ.get('SHOPEE_TEST_PARTNER_KEY'),
            'configured': False
        },
        'tiktok': {
            'client_id': os.environ.get('TIKTOK_CLIENT_ID') or os.environ.get('TIKTOK_APP_KEY'),
            'client_secret': os.environ.get('TIKTOK_CLIENT_SECRET') or os.environ.get('TIKTOK_APP_SECRET'),
            'configured': False
        }
    }

    creds['shopee']['configured'] = bool(
        creds['shopee']['partner_id'] and creds['shopee']['partner_key']
    )

    creds['tiktok']['configured'] = bool(
        creds['tiktok']['client_id'] and creds['tiktok']['client_secret']
    )

    return creds


def upload_to_shopee(creds: dict) -> bool:
    """Faz upload real para Shopee: sobe cada imagem via upload_image_by_url
    e vincula ao produto (por SKU) via update_product. Usa scripts/utils/shopee_client.py,
    o cliente real ja usado por outras automacoes deste repo -- antes esta funcao so
    lia o CSV e fingia sucesso sem nunca chamar a API da Shopee de fato.
    """
    logger.info("\n" + "=" * 70)
    logger.info("🛍️  UPLOAD SHOPEE")
    logger.info("=" * 70)

    if not creds['shopee']['configured']:
        logger.warning("⚠️  Shopee não configurada (credenciais faltando)")
        return False

    try:
        sys.path.insert(0, str(Path(__file__).parent / 'utils'))
        from shopee_client import ShopeeClient

        partner_id = creds['shopee']['partner_id']
        logger.info(f"✅ Credenciais encontradas:")
        logger.info(f"   • SHOPEE_PARTNER_ID: {partner_id}")

        client = ShopeeClient()

        logger.info("📦 Mapeando SKU -> item_id da loja...")
        item_ids = [item.get('item_id') for item in client.iter_all_products() if item.get('item_id')]
        details = client.get_product_details(item_ids)
        sku_to_item_id = {
            str(d.get('item_sku') or '').strip(): d.get('item_id')
            for d in details if d.get('item_sku')
        }
        logger.info(f"   {len(sku_to_item_id)} SKUs mapeados na loja Shopee")

        import csv
        uploaded_products = 0
        uploaded_images = 0
        skipped_no_match = 0
        failed_products = []

        with UPLOAD_MAPPING_FILE.open('r', encoding='utf-8', newline='') as f:
            reader = csv.DictReader(f)
            for row in reader:
                sku = str(row.get('sku') or '').strip()
                item_id = sku_to_item_id.get(sku)
                if not item_id:
                    skipped_no_match += 1
                    continue

                image_urls = [row.get(f'image_url_{i}') for i in range(1, 5)]
                image_urls = [u.strip() for u in image_urls if u and u.strip()]
                if not image_urls:
                    continue

                try:
                    # upload_image_by_url() chama /media_space/upload_image_by_url,
                    # endpoint que nao existe de fato na API v2 da Shopee (confirmado
                    # 404 "error_not_found" em teste real) -- a API so aceita upload
                    # de arquivo binario. Baixa a imagem e sobe pelo metodo real.
                    image_ids = []
                    for url in image_urls:
                        resp = requests.get(url, timeout=30)
                        resp.raise_for_status()
                        suffix = Path(url).suffix or '.jpg'
                        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
                            tmp.write(resp.content)
                            tmp_path = tmp.name
                        try:
                            image_ids.append(client.upload_image(tmp_path))
                        finally:
                            os.unlink(tmp_path)
                    client.update_product(int(item_id), image_ids=image_ids)
                    uploaded_products += 1
                    uploaded_images += len(image_ids)
                    logger.info(f"   ✅ {sku} (item_id={item_id}): {len(image_ids)} imagens enviadas")
                except Exception as e:
                    failed_products.append(sku)
                    logger.warning(f"   ❌ {sku}: falhou -- {e}")

        logger.info(f"\n📊 RESULTADO SHOPEE")
        logger.info(f"✅ {uploaded_products} produtos atualizados com sucesso")
        logger.info(f"✅ {uploaded_images} imagens enviadas de verdade")
        if skipped_no_match:
            logger.info(f"⚠️  {skipped_no_match} SKUs do CSV nao encontrados na loja Shopee (pulados)")
        if failed_products:
            logger.warning(f"❌ {len(failed_products)} produtos falharam: {', '.join(failed_products[:10])}")

        return len(failed_products) == 0

    except Exception as e:
        logger.error(f"❌ Erro no upload Shopee: {e}")
        return False


def upload_to_tiktok(creds: dict) -> bool:
    """Faz upload real para TikTok Shop: vincula as URLs de imagem (ja publicas em
    dev.shopvivaliz.com.br) direto via update_product -- a API do TikTok Shop aceita
    main_images por URL, sem precisar de um passo de upload separado. Usa
    scripts/utils/tiktok_client.py. Antes esta funcao so lia o CSV e fingia sucesso
    sem nunca chamar a API do TikTok.
    """
    logger.info("\n" + "=" * 70)
    logger.info("🎵 UPLOAD TIKTOK SHOP")
    logger.info("=" * 70)

    if not creds['tiktok']['configured']:
        logger.warning("⚠️  TikTok não configurado (credenciais faltando)")
        return False

    try:
        sys.path.insert(0, str(Path(__file__).parent / 'utils'))
        from tiktok_client import TikTokClient

        client_id = creds['tiktok']['client_id']
        logger.info(f"✅ Credenciais encontradas:")
        logger.info(f"   • TIKTOK_CLIENT_ID: {client_id}")

        client = TikTokClient()

        logger.info("📦 Mapeando SKU -> product_id da loja...")
        sku_to_product_id = {}
        for product in client.iter_all_products():
            product_id = product.get('id') or product.get('product_id')
            if not product_id:
                continue
            skus = product.get('skus') or []
            if not skus:
                try:
                    detail = client.get_product_detail(str(product_id))
                    skus = detail.get('skus') or []
                except Exception:
                    skus = []
            for sku_entry in skus:
                seller_sku = str(sku_entry.get('seller_sku') or '').strip()
                if seller_sku:
                    sku_to_product_id[seller_sku] = product_id
        logger.info(f"   {len(sku_to_product_id)} SKUs mapeados na loja TikTok")

        import csv
        uploaded_products = 0
        uploaded_images = 0
        skipped_no_match = 0
        failed_products = []

        with UPLOAD_MAPPING_FILE.open('r', encoding='utf-8', newline='') as f:
            reader = csv.DictReader(f)
            for row in reader:
                sku = str(row.get('sku') or '').strip()
                product_id = sku_to_product_id.get(sku)
                if not product_id:
                    skipped_no_match += 1
                    continue

                image_urls = [row.get(f'image_url_{i}') for i in range(1, 5)]
                image_urls = [u.strip() for u in image_urls if u and u.strip()]
                if not image_urls:
                    continue

                try:
                    client.update_product(str(product_id), image_urls=image_urls)
                    uploaded_products += 1
                    uploaded_images += len(image_urls)
                    logger.info(f"   ✅ {sku} (product_id={product_id}): {len(image_urls)} imagens vinculadas")
                except Exception as e:
                    failed_products.append(sku)
                    logger.warning(f"   ❌ {sku}: falhou -- {e}")

        logger.info(f"\n📊 RESULTADO TIKTOK SHOP")
        logger.info(f"✅ {uploaded_products} produtos atualizados com sucesso")
        logger.info(f"✅ {uploaded_images} imagens vinculadas de verdade")
        if skipped_no_match:
            logger.info(f"⚠️  {skipped_no_match} SKUs do CSV nao encontrados na loja TikTok (pulados)")
        if failed_products:
            logger.warning(f"❌ {len(failed_products)} produtos falharam: {', '.join(failed_products[:10])}")

        return len(failed_products) == 0

    except Exception as e:
        logger.error(f"❌ Erro no upload TikTok: {e}")
        return False


def main():
    logger.info("""
╔════════════════════════════════════════════════════════════════╗
║         🚀 EXECUÇÃO DE UPLOAD NOS MARKETPLACES                ║
║              Usando Credenciais dos GitHub Secrets            ║
╚════════════════════════════════════════════════════════════════╝
""")

    # Verificar credenciais
    logger.info("🔍 Verificando credenciais dos GitHub Secrets...\n")
    creds = check_marketplace_credentials()

    execution_log = {
        'timestamp': datetime.now().isoformat(),
        'shopee': {},
        'tiktok': {},
        'summary': {}
    }

    # Shopee
    shopee_success = upload_to_shopee(creds)
    execution_log['shopee'] = {
        'configured': creds['shopee']['configured'],
        'success': shopee_success,
        'timestamp': datetime.now().isoformat()
    }

    # TikTok
    tiktok_success = upload_to_tiktok(creds)
    execution_log['tiktok'] = {
        'configured': creds['tiktok']['configured'],
        'success': tiktok_success,
        'timestamp': datetime.now().isoformat()
    }

    # Resumo
    logger.info("\n" + "=" * 70)
    logger.info("📊 RESUMO FINAL")
    logger.info("=" * 70)

    if creds['shopee']['configured']:
        status = "✅ Concluído" if shopee_success else "❌ Falhou"
        logger.info(f"🛍️  Shopee: {status}")
    else:
        logger.warning("⚠️  Shopee: Não configurado")

    if creds['tiktok']['configured']:
        status = "✅ Concluído" if tiktok_success else "❌ Falhou"
        logger.info(f"🎵 TikTok: {status}")
    else:
        logger.warning("⚠️  TikTok: Não configurado")

    execution_log['summary'] = {
        'shopee_configured': creds['shopee']['configured'],
        'tiktok_configured': creds['tiktok']['configured'],
        'shopee_success': shopee_success,
        'tiktok_success': tiktok_success,
        'overall_success': (shopee_success or not creds['shopee']['configured']) and \
                          (tiktok_success or not creds['tiktok']['configured'])
    }

    # Salvar log
    EXECUTION_LOG.parent.mkdir(parents=True, exist_ok=True)
    with EXECUTION_LOG.open('w', encoding='utf-8') as f:
        json.dump(execution_log, f, indent=2, ensure_ascii=False)

    logger.info(f"\n📄 Execution log: {EXECUTION_LOG}")
    logger.info("\n" + "=" * 70 + "\n")

    return 0 if execution_log['summary']['overall_success'] else 1


if __name__ == '__main__':
    sys.exit(main())
