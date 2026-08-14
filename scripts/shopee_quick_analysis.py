#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Analise rapida de produtos da Shopee.
Simula o que seria otimizado sem fazer mudancas.
"""
import sys
import json
from pathlib import Path

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

def analyze_product_title(title: str) -> dict:
    """Analisa um titulo e retorna metricas."""
    length = len(title)
    keywords = ["qualidade", "novo", "original", "lacrado", "premium"]
    found = [k for k in keywords if k.lower() in title.lower()]

    score = 100
    issues = []

    if length < 15:
        score -= 20
        issues.append(f"Muito curto ({length} chars)")
    elif length > 120:
        score -= 30
        issues.append(f"Muito longo ({length} chars, max 120)")
    elif length < 30:
        score -= 10

    if not found:
        score -= 15
        issues.append("Falta palavras-chave de SEO")

    return {
        "original": title,
        "length": length,
        "keywords": found,
        "score": max(0, min(100, score)),
        "issues": issues,
    }

def generate_improved_title(product: dict) -> str:
    """Gera titulo melhorado."""
    title = (product.get("item_name") or "").strip()

    if len(title) >= 110:
        return title

    improved = title

    if "qualidade" not in improved.lower() and len(improved) < 100:
        improved = improved + " - Qualidade"

    if "novo" not in improved.lower():
        if len(improved) < 110:
            improved = improved + " Novo"

    return improved[:120].strip()

def try_load_catalog():
    """Tenta carregar catalogo de arquivos locais."""
    # Procurar por arquivos de catalogo
    catalog_paths = [
        ROOT / "storage" / "catalog.json",
        ROOT / "data" / "products.json",
        ROOT / "imports" / "shopee-products.json",
    ]

    for path in catalog_paths:
        if path.exists():
            try:
                with open(path, "r", encoding="utf-8") as f:
                    data = json.load(f)
                    if isinstance(data, list):
                        return data
                    if isinstance(data, dict) and "products" in data:
                        return data["products"]
            except:
                pass

    return None

def main():
    print("="*80)
    print("ANALISE RAPIDA DE OTIMIZACAO - SHOPEE")
    print("="*80)
    print()

    # Tentar carregar catalogo real
    products = try_load_catalog()

    # Se nao tiver, usar dados de exemplo
    if not products:
        print("[INFO] Usando dados de exemplo (arquivo de catalogo nao encontrado)\n")
        products = [
            {"item_name": "Rodizio 50MM"},
            {"item_name": "Kit Ferramentas Profissional 127 Pecas"},
            {"item_name": "Vasilha Plastico Organizador Cozinha 5L"},
            {"item_name": "Cadeado Papaiz 50MM Cromado"},
            {"item_name": "Manivela Porta"},
            {"item_name": "Parafuso Zinco M8"},
            {"item_name": "A"},
        ]

    stats = {
        "total": len(products),
        "optimizable": 0,
        "high_score": 0,
        "low_score": 0,
        "changes": []
    }

    print("[ANALISANDO] Cada produto...\n")
    print(f"{'Item':<5} {'Score':<8} {'Status':<20} {'Titulo':<50}")
    print("-" * 90)

    for idx, product in enumerate(products, 1):
        title = product.get("item_name", "").strip()

        if not title:
            continue

        analysis = analyze_product_title(title)
        score = analysis["score"]
        issues = analysis["issues"]

        if score >= 80:
            status = "OK - Otimo"
            stats["high_score"] += 1
        elif score >= 60:
            status = "Pode melhorar"
            stats["optimizable"] += 1
        else:
            status = "Precisa otimizar"
            stats["optimizable"] += 1
            stats["low_score"] += 1

        # Exibir
        print(f"{idx:<5} {score:<8} {status:<20} {title[:48]:<50}")

        if issues:
            for issue in issues:
                print(f"      -> {issue}")

        if score < 80:
            improved = generate_improved_title(product)
            print(f"      [SUGESTAO] {improved[:60]}")

            stats["changes"].append({
                "item": idx,
                "original": title,
                "suggested": improved,
                "score_before": score,
                "score_after": analyze_product_title(improved)["score"],
            })

        print()

    # Resumo
    print("\n" + "="*80)
    print("RESUMO DA ANALISE")
    print("="*80)
    print(f"Total de produtos: {stats['total']}")
    print(f"COM SCORE ALTO (80+): {stats['high_score']}")
    print(f"PODEM MELHORAR: {stats['optimizable']}")
    print(f"PRECISAM URGENTE: {stats['low_score']}")
    print(f"Mudancas sugeridas: {len(stats['changes'])}")
    print()

    if stats["changes"]:
        print("[IMPACTO ESPERADO]")
        scores_before = [c["score_before"] for c in stats["changes"]]
        scores_after = [c["score_after"] for c in stats["changes"]]
        avg_before = sum(scores_before) / len(scores_before)
        avg_after = sum(scores_after) / len(scores_after)
        improvement = avg_after - avg_before

        print(f"  Score medio ANTES: {avg_before:.0f}/100")
        print(f"  Score medio DEPOIS: {avg_after:.0f}/100")
        print(f"  Melhoria: +{improvement:.0f} pontos ({improvement/avg_before*100:.0f}%)")
        print()

    print("[PROXIMO PASSO]")
    print("  $ python scripts/shopee_batch_optimize_auto.py")
    print("    -> Aplica automaticamente as otimizacoes")
    print()

if __name__ == "__main__":
    main()
