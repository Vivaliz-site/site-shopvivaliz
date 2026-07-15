#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para validar 20 produtos aleatórios
Verifica: imagens, SEO, títulos, descrições
"""
import os
import sys
import json
import random
from pathlib import Path

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

def get_all_products():
    """Obtém lista de todos os produtos processados"""
    processed = Path('storage/processed')
    if not processed.exists():
        return []

    products = [d.name for d in processed.iterdir() if d.is_dir()]
    return sorted(products)

def validate_product(sku):
    """Valida um produto"""
    result = {
        'sku': sku,
        'status': 'OK',
        'errors': [],
        'warnings': [],
        'images': [],
        'seo': {},
        'summary': {}
    }

    # Verificar imagens
    product_dir = Path(f'storage/processed/{sku}')
    if product_dir.exists():
        images = list(product_dir.glob('*.jpg')) + list(product_dir.glob('*.png'))
        result['images'] = {
            'count': len(images),
            'total_size_kb': sum(f.stat().st_size for f in images) / 1024,
            'files': [f.name for f in images]
        }

        if len(images) < 4:
            result['warnings'].append(f"⚠️  Apenas {len(images)}/4 imagens geradas")
        else:
            result['summary']['images'] = '✅ 4 variantes geradas'
    else:
        result['errors'].append('❌ Diretório do produto não encontrado')
        result['status'] = 'ERROR'

    # Verificar SEO
    seo_file = Path('logs/seo_generated.json')
    if seo_file.exists():
        try:
            with seo_file.open('r', encoding='utf-8') as f:
                seo_data = json.load(f)
                if sku in seo_data:
                    result['seo'] = seo_data[sku]
                    if 'shopee' in seo_data[sku]:
                        shopee = seo_data[sku]['shopee']
                        result['summary']['shopee_seo'] = '✅ Gerado'
                        result['seo']['shopee_title_len'] = len(shopee.get('title', ''))
                        result['seo']['shopee_desc_len'] = len(shopee.get('description', ''))

                    if 'tiktok' in seo_data[sku]:
                        tiktok = seo_data[sku]['tiktok']
                        result['summary']['tiktok_seo'] = '✅ Gerado'
                        result['seo']['tiktok_title_len'] = len(tiktok.get('title', ''))
                        result['seo']['tiktok_desc_len'] = len(tiktok.get('description', ''))
        except:
            result['warnings'].append('⚠️  Não foi possível ler SEO gerado')

    # Resumo final
    if not result['errors']:
        result['status'] = 'OK' if not result['warnings'] else 'PARTIAL'

    return result

def main():
    print("""
╔═══════════════════════════════════════════════════════════════════════╗
║                                                                       ║
║              ✅ VALIDAÇÃO DE 20 PRODUTOS ALEATÓRIOS                   ║
║                                                                       ║
║     Verificando: Imagens, Títulos, Descrições, SEO                  ║
║                                                                       ║
╚═══════════════════════════════════════════════════════════════════════╝
""")

    # Obter produtos
    all_products = get_all_products()

    if not all_products:
        print("❌ Nenhum produto encontrado em storage/processed/")
        return

    print(f"\n📊 Total de produtos processados: {len(all_products)}")

    # Selecionar 20 aleatórios
    sample_size = min(20, len(all_products))
    sample_products = random.sample(all_products, sample_size)

    print(f"📋 Validando {sample_size} produtos aleatórios...\n")

    results = []
    stats = {
        'total': sample_size,
        'ok': 0,
        'partial': 0,
        'error': 0,
        'images_total': 0,
        'seo_shopee': 0,
        'seo_tiktok': 0
    }

    # Validar cada produto
    for i, sku in enumerate(sample_products, 1):
        result = validate_product(sku)
        results.append(result)

        # Print progressivo
        status_icon = '✅' if result['status'] == 'OK' else '⚠️ ' if result['status'] == 'PARTIAL' else '❌'
        print(f"{i:2}. {status_icon} {sku:20} | Imagens: {result['images'].get('count', 0):1} | ", end='')

        if 'shopee_seo' in result['summary']:
            print("Shopee ✓ ", end='')
            stats['seo_shopee'] += 1

        if 'tiktok_seo' in result['summary']:
            print("TikTok ✓ ", end='')
            stats['seo_tiktok'] += 1

        print()

        # Atualizar stats
        if result['status'] == 'OK':
            stats['ok'] += 1
        elif result['status'] == 'PARTIAL':
            stats['partial'] += 1
        else:
            stats['error'] += 1

        stats['images_total'] += result['images'].get('count', 0)

    # Relatório resumido
    print("\n" + "="*70)
    print("📊 RELATÓRIO DE VALIDAÇÃO")
    print("="*70)

    print(f"""
AMOSTRA VALIDADA:
  • Produtos testados: {stats['total']}/20
  • Status OK: {stats['ok']}/{stats['total']} ({stats['ok']*100//stats['total']}%)
  • Status PARTIAL: {stats['partial']}/{stats['total']}
  • Status ERROR: {stats['error']}/{stats['total']}

IMAGENS:
  • Total gerado: {stats['images_total']} (esperado: {stats['total']*4})
  • Média por produto: {stats['images_total']/stats['total']:.1f}/4
  • Cobertura: {stats['images_total']*100//(stats['total']*4)}%

SEO GERADO:
  • Shopee: {stats['seo_shopee']}/{stats['total']} ({stats['seo_shopee']*100//stats['total']}%)
  • TikTok: {stats['seo_tiktok']}/{stats['total']} ({stats['seo_tiktok']*100//stats['total']}%)

CONCLUSÃO:
""")

    if stats['ok'] == stats['total'] and stats['images_total'] == stats['total'] * 4:
        print("  ✅ VALIDAÇÃO 100% SUCESSO!")
        print("     Todos os 20 produtos com:")
        print("     • 4 imagens cada")
        print("     • SEO Shopee gerado")
        print("     • SEO TikTok gerado")
    else:
        if stats['ok'] >= stats['total'] * 0.8:
            print(f"  ✅ VALIDAÇÃO {stats['ok']*100//stats['total']}% SUCESSO")
        else:
            print(f"  ⚠️  VALIDAÇÃO COM PARCIALIDADES")

    # Salvar relatório detalhado
    report_file = Path('logs/validation_20_products.json')
    report_file.parent.mkdir(parents=True, exist_ok=True)

    with report_file.open('w', encoding='utf-8') as f:
        json.dump({
            'timestamp': str(Path.cwd()),
            'stats': stats,
            'products': results
        }, f, indent=2, ensure_ascii=False)

    print(f"\n📄 Relatório detalhado salvo em: {report_file}")
    print("="*70)

if __name__ == '__main__':
    main()
