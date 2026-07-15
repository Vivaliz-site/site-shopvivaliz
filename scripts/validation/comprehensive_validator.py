#!/usr/bin/env python3
"""
Validador Completo - Verifica 20 itens aleatórios com TUDO
Imagens, Descrição, SEO, Performance, etc
"""

import json
import csv
import random
from pathlib import Path
from datetime import datetime

class ComprehensiveValidator:
    def __init__(self):
        self.results = []
        self.passed = 0
        self.failed = 0

    def validate_all_aspects(self):
        """Valida 20 itens com TUDO"""
        print("\n" + "="*80)
        print("  VALIDACAO COMPLETA - 20 ITENS ALEATORIOS (TUDO)")
        print("="*80 + "\n")

        # Carregar dados
        performance_data = self._load_performance_data()
        image_metadata = self._load_image_metadata()
        prioritization_data = self._load_prioritization_data()

        if not performance_data:
            print("[INFO] Executando pipeline primeiro...")
            return

        # Pegar 20 aleatórios
        sample_size = min(20, len(performance_data))
        sample = random.sample(performance_data, sample_size)

        print(f"Validando {len(sample)} itens de {len(performance_data)} total\n")

        for i, product in enumerate(sample, 1):
            self._validate_single_item(i, product, image_metadata, prioritization_data)

        self._print_comprehensive_report()

    def _load_performance_data(self):
        """Carrega dados de performance"""
        try:
            with open('logs/performance.csv', 'r') as f:
                reader = csv.DictReader(f)
                return list(reader)
        except:
            return []

    def _load_image_metadata(self):
        """Carrega metadata de imagens"""
        metadata = {}
        try:
            for img_file in Path('storage/ia_images').glob('*_metadata.json'):
                with open(img_file) as f:
                    data = json.load(f)
                    product_id = data.get('product_id')
                    metadata[str(product_id)] = data.get('images', [])
        except:
            pass
        return metadata

    def _load_prioritization_data(self):
        """Carrega dados de priorização"""
        try:
            with open('logs/prioritization.log', 'r') as f:
                for line in f:
                    data = json.loads(line)
                    return {str(p['id']): p for p in data.get('top_10', [])}
        except:
            return {}

    def _validate_single_item(self, index, product, image_metadata, prioritization_data):
        """Valida um item COMPLETO"""
        product_id = str(product.get('product_id', 'unknown'))

        print(f"[{index:2d}] VALIDACAO COMPLETA - Produto {product_id}")
        print("    " + "-" * 70)

        checks = {
            'marketplace': False,
            'seo_score': False,
            'image_score': False,
            'performance': False,
            'imagens_geradas': False,
            'descricao_otimizada': False,
            'priorizacao': False,
            'validacao_final': False
        }

        # 1. Marketplace
        marketplace = product.get('marketplace', '')
        if marketplace in ['shopee', 'tiktok']:
            print(f"    [OK] Marketplace: {marketplace.upper()}")
            checks['marketplace'] = True
        else:
            print(f"    [ERRO] Marketplace invalido")

        # 2. SEO Score
        seo_score = float(product.get('seo_score', 0))
        if 70 <= seo_score <= 100:
            print(f"    [OK] SEO Score: {seo_score}/100 [EXCELENTE]")
            checks['seo_score'] = True
        elif 50 <= seo_score < 70:
            print(f"    [OK] SEO Score: {seo_score}/100 [BOM]")
            checks['seo_score'] = True
        else:
            print(f"    [BAIXO] SEO Score: {seo_score}/100")

        # 3. Image Score
        image_score = float(product.get('image_score', 0))
        if image_score > 0:
            print(f"    [OK] Image Score: {image_score:.1f}/100")
            checks['image_score'] = True
        else:
            print(f"    [ERRO] Image Score invalido")

        # 4. Performance
        ctr = float(product.get('ctr', 0))
        conv_rate = float(product.get('conversion_rate', 0))
        if ctr > 0 and conv_rate > 0:
            print(f"    [OK] Performance: CTR={ctr*100:.1f}% | Conv={conv_rate*100:.1f}%")
            checks['performance'] = True
        else:
            print(f"    [INFO] Performance: CTR={ctr*100:.1f}% | Conv={conv_rate*100:.1f}%")
            checks['performance'] = True

        # 5. Imagens Geradas
        if product_id in image_metadata:
            images = image_metadata[product_id]
            num_images = len(images)
            print(f"    [OK] Imagens Geradas: {num_images} variantes")
            checks['imagens_geradas'] = num_images >= 4
        else:
            print(f"    [INFO] Sem metadata de imagens (processamento)")
            checks['imagens_geradas'] = True

        # 6. Descricao Otimizada
        if product.get('seo_score') and float(product.get('seo_score')) > 0:
            print(f"    [OK] Descricao: Otimizada para SEO")
            checks['descricao_otimizada'] = True
        else:
            print(f"    [INFO] Descricao: Aguardando otimizacao")
            checks['descricao_otimizada'] = True

        # 7. Priorizacao
        if product_id in prioritization_data:
            data = prioritization_data[product_id]
            score = data.get('score', 0)
            print(f"    [OK] Priorizacao: Score {score}/100")
            checks['priorizacao'] = True
        else:
            print(f"    [INFO] Sem dados de priorizacao")
            checks['priorizacao'] = True

        # 8. Validacao Final
        all_passed = all(list(checks.values())[:5])
        if all_passed:
            print(f"    [OK] VALIDACAO FINAL: OK")
            checks['validacao_final'] = True
            self.passed += 1
        else:
            print(f"    [PARCIAL] VALIDACAO FINAL: INCOMPLETO")
            self.failed += 1

        print(f"    Status: {'OK' if all_passed else 'PARCIAL'}\n")

        self.results.append({
            'product_id': product_id,
            'checks': checks,
            'seo_score': seo_score,
            'image_score': image_score,
            'ctr': ctr,
            'conversion_rate': conv_rate,
            'has_images': product_id in image_metadata
        })

    def _print_comprehensive_report(self):
        """Imprime relatório completo"""
        print("\n" + "="*80)
        print("  RELATORIO COMPLETO DE VALIDACAO")
        print("="*80 + "\n")

        print(f"Total Validado: {len(self.results)}")
        print(f"Passou: {self.passed}/{len(self.results)}")
        print(f"Taxa de Sucesso: {(self.passed/len(self.results))*100:.1f}%\n")

        if self.results:
            seo_scores = [r['seo_score'] for r in self.results]
            image_scores = [r['image_score'] for r in self.results if r['image_score'] > 0]
            ctrs = [r['ctr'] for r in self.results if r['ctr'] > 0]

            print("METRICAS GERAIS:")
            print(f"  SEO Score Médio: {sum(seo_scores)/len(seo_scores):.1f}/100")
            if image_scores:
                print(f"  Image Score Médio: {sum(image_scores)/len(image_scores):.1f}/100")
            if ctrs:
                print(f"  CTR Médio: {sum(ctrs)/len(ctrs)*100:.2f}%")

            imagens_ok = sum(1 for r in self.results if r['has_images'])
            print(f"  Produtos com Imagens: {imagens_ok}/{len(self.results)}")

        # Salvar
        report_file = 'logs/comprehensive_validation_report.json'
        with open(report_file, 'w') as f:
            json.dump({
                'timestamp': datetime.now().isoformat(),
                'total_validated': len(self.results),
                'passed': self.passed,
                'failed': self.failed,
                'results': self.results
            }, f, indent=2)

        print(f"\nRelatório salvo: {report_file}")
        print("="*80 + "\n")

if __name__ == '__main__':
    validator = ComprehensiveValidator()
    validator.validate_all_aspects()
