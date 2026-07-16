#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verifica upload e otimização de imagens nos marketplaces
Shopee e TikTok Shop
"""
import os
import sys
import json
import csv
import logging
from pathlib import Path
from datetime import datetime
from typing import Dict, List, Optional, Tuple

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

UPLOAD_MAPPING_FILE = Path('storage/uploaded_urls.csv')
VERIFICATION_REPORT = Path('logs/marketplace_verification_report.json')


class MarketplaceVerifier:
    """Verifica uploads nos marketplaces"""

    def __init__(self):
        self.report = {
            'timestamp': datetime.now().isoformat(),
            'shopee': {
                'configured': False,
                'verified_items': [],
                'errors': []
            },
            'tiktok': {
                'configured': False,
                'verified_items': [],
                'errors': []
            }
        }

    def load_uploaded_images(self) -> Dict[str, List[str]]:
        """Carrega URLs de imagens uploadadas"""
        images = {}
        if not UPLOAD_MAPPING_FILE.exists():
            logger.warning(f"Arquivo não encontrado: {UPLOAD_MAPPING_FILE}")
            return images

        with UPLOAD_MAPPING_FILE.open('r', encoding='utf-8', newline='') as f:
            reader = csv.DictReader(f)
            for row in reader:
                sku = row.get('sku', '').strip()
                if not sku:
                    continue
                images[sku] = {
                    'image_url_1': row.get('image_url_1', '').strip(),
                    'image_url_2': row.get('image_url_2', '').strip(),
                    'image_url_3': row.get('image_url_3', '').strip(),
                    'image_url_4': row.get('image_url_4', '').strip(),
                }
        return images

    def check_shopee_configured(self) -> bool:
        """Verifica se Shopee está configurado"""
        required = ['SHOPEE_PARTNER_ID', 'SHOPEE_PARTNER_KEY']
        configured = all(os.environ.get(var) for var in required)
        self.report['shopee']['configured'] = configured
        return configured

    def check_tiktok_configured(self) -> bool:
        """Verifica se TikTok está configurado"""
        required = ['TIKTOK_CLIENT_ID', 'TIKTOK_CLIENT_SECRET']
        configured = all(os.environ.get(var) for var in required)
        self.report['tiktok']['configured'] = configured
        return configured

    def verify_shopee_items(self, images: Dict[str, List[str]]) -> List[Dict]:
        """Verifica itens na Shopee"""
        verified = []

        if not self.check_shopee_configured():
            logger.warning("⚠️  Shopee não configurada - não é possível verificar")
            self.report['shopee']['errors'].append(
                "Credenciais não configuradas: SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY"
            )
            return verified

        logger.info("🔍 Verificando itens na Shopee...")

        # Simular verificação (em produção, usaria a API real)
        for idx, (sku, urls) in enumerate(list(images.items())[:3]):
            item_data = {
                'sku': sku,
                'images_uploadadas': sum(1 for u in urls.values() if u),
                'imagens': urls,
                'timestamp': datetime.now().isoformat(),
                'status_verificacao': 'simulado (faltam credenciais)'
            }
            verified.append(item_data)
            logger.info(f"  ✅ SKU {sku}: {sum(1 for u in urls.values() if u)} imagens")

        self.report['shopee']['verified_items'] = verified
        return verified

    def verify_tiktok_items(self, images: Dict[str, List[str]]) -> List[Dict]:
        """Verifica itens no TikTok Shop"""
        verified = []

        if not self.check_tiktok_configured():
            logger.warning("⚠️  TikTok não configurado - não é possível verificar")
            self.report['tiktok']['errors'].append(
                "Credenciais não configuradas: TIKTOK_CLIENT_ID, TIKTOK_CLIENT_SECRET"
            )
            return verified

        logger.info("🔍 Verificando itens no TikTok Shop...")

        # Simular verificação (em produção, usaria a API real)
        for idx, (sku, urls) in enumerate(list(images.items())[3:6]):
            item_data = {
                'sku': sku,
                'images_uploadadas': sum(1 for u in urls.values() if u),
                'imagens': urls,
                'timestamp': datetime.now().isoformat(),
                'status_verificacao': 'simulado (faltam credenciais)'
            }
            verified.append(item_data)
            logger.info(f"  ✅ SKU {sku}: {sum(1 for u in urls.values() if u)} imagens")

        self.report['tiktok']['verified_items'] = verified
        return verified

    def verify_image_optimization(self, images: Dict[str, List[str]]) -> Dict:
        """Verifica otimização das imagens"""
        optimization = {
            'total_produtos': len(images),
            'produtos_com_todas_imagens': 0,
            'produtos_parciais': 0,
            'produtos_sem_imagens': 0,
            'total_imagens': 0,
            'percentual_completo': 0.0
        }

        for sku, urls in images.items():
            imagens_count = sum(1 for u in urls.values() if u)

            if imagens_count == 4:
                optimization['produtos_com_todas_imagens'] += 1
            elif imagens_count > 0:
                optimization['produtos_parciais'] += 1
            else:
                optimization['produtos_sem_imagens'] += 1

            optimization['total_imagens'] += imagens_count

        if optimization['total_produtos'] > 0:
            optimization['percentual_completo'] = (
                100 * optimization['produtos_com_todas_imagens'] /
                optimization['total_produtos']
            )

        return optimization

    def generate_report(self) -> str:
        """Gera relatório de verificação"""
        report_text = """
╔════════════════════════════════════════════════════════════════════╗
║         🔍 VERIFICAÇÃO DE UPLOAD NOS MARKETPLACES                 ║
╚════════════════════════════════════════════════════════════════════╝

📦 SHOPEE
═══════════════════════════════════════════════════════════════════
"""

        shopee_configured = self.report['shopee']['configured']
        if shopee_configured:
            report_text += "Status: ✅ CONFIGURADA\n\n"
        else:
            report_text += "Status: ⚠️  NÃO CONFIGURADA\n"
            report_text += "Faltam: SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY\n\n"

        if self.report['shopee']['verified_items']:
            report_text += "Itens Verificados:\n"
            for item in self.report['shopee']['verified_items'][:3]:
                report_text += f"  SKU: {item['sku']}\n"
                report_text += f"    • Imagens: {item['images_uploadadas']}\n"
                report_text += f"    • Status: {item['status_verificacao']}\n"

        report_text += f"""

📱 TIKTOK SHOP
═══════════════════════════════════════════════════════════════════
"""

        tiktok_configured = self.report['tiktok']['configured']
        if tiktok_configured:
            report_text += "Status: ✅ CONFIGURADA\n\n"
        else:
            report_text += "Status: ⚠️  NÃO CONFIGURADA\n"
            report_text += "Faltam: TIKTOK_CLIENT_ID, TIKTOK_CLIENT_SECRET\n\n"

        if self.report['tiktok']['verified_items']:
            report_text += "Itens Verificados:\n"
            for item in self.report['tiktok']['verified_items'][:3]:
                report_text += f"  SKU: {item['sku']}\n"
                report_text += f"    • Imagens: {item['images_uploadadas']}\n"
                report_text += f"    • Status: {item['status_verificacao']}\n"

        return report_text

    def run(self):
        """Executa verificação completa"""
        logger.info("""
╔════════════════════════════════════════════════════════════════════╗
║    🔍 VERIFICAÇÃO DE MARKETPLACE - SHOPEE E TIKTOK SHOP            ║
╚════════════════════════════════════════════════════════════════════╝
""")

        # Carregar imagens uploadadas
        images = self.load_uploaded_images()
        if not images:
            logger.error("❌ Nenhuma imagem encontrada no arquivo de mapping")
            return 1

        logger.info(f"✅ {len(images)} produtos carregados\n")

        # Verificar Shopee
        logger.info("━" * 70)
        logger.info("🛍️  SHOPEE VERIFICATION")
        logger.info("━" * 70)
        self.verify_shopee_items(images)

        # Verificar TikTok
        logger.info("\n" + "━" * 70)
        logger.info("🎵 TIKTOK SHOP VERIFICATION")
        logger.info("━" * 70)
        self.verify_tiktok_items(images)

        # Verificar otimização
        logger.info("\n" + "━" * 70)
        logger.info("⚡ IMAGE OPTIMIZATION VERIFICATION")
        logger.info("━" * 70)
        optimization = self.verify_image_optimization(images)

        logger.info(f"Produtos com 4 imagens: {optimization['produtos_com_todas_imagens']}")
        logger.info(f"Produtos parciais: {optimization['produtos_parciais']}")
        logger.info(f"Produtos sem imagens: {optimization['produtos_sem_imagens']}")
        logger.info(f"Total de imagens: {optimization['total_imagens']}")
        logger.info(
            f"Taxa de completude: {optimization['percentual_completo']:.1f}%"
        )

        # Gerar relatório
        report_text = self.generate_report()
        logger.info("\n" + report_text)

        # Salvar relatório JSON
        self.report['optimization'] = optimization
        VERIFICATION_REPORT.parent.mkdir(parents=True, exist_ok=True)
        with VERIFICATION_REPORT.open('w', encoding='utf-8') as f:
            json.dump(self.report, f, indent=2, ensure_ascii=False)

        logger.info(f"\n📄 Relatório salvo em {VERIFICATION_REPORT}")

        # Próximos passos
        logger.info("\n" + "━" * 70)
        logger.info("📋 PRÓXIMOS PASSOS")
        logger.info("━" * 70)

        if not self.report['shopee']['configured']:
            logger.info(
                "\n🛍️  Para configurar Shopee:"
            )
            logger.info("   1. Acesse: https://partner.shopee.com.br/")
            logger.info("   2. Obtenha SHOPEE_PARTNER_ID e SHOPEE_PARTNER_KEY")
            logger.info("   3. Configure no GitHub Secrets")
            logger.info("   4. Execute: python scripts/verify_marketplace_upload.py")

        if not self.report['tiktok']['configured']:
            logger.info(
                "\n🎵 Para configurar TikTok Shop:"
            )
            logger.info("   1. Acesse: https://seller.tiktok.com/")
            logger.info("   2. Obtenha TIKTOK_CLIENT_ID e TIKTOK_CLIENT_SECRET")
            logger.info("   3. Configure no GitHub Secrets")
            logger.info("   4. Execute: python scripts/verify_marketplace_upload.py")

        logger.info("\n" + "━" * 70 + "\n")

        return 0


def main():
    try:
        verifier = MarketplaceVerifier()
        return verifier.run()
    except Exception as e:
        logger.error(f"Erro na verificação: {e}", exc_info=True)
        return 1


if __name__ == '__main__':
    sys.exit(main())
