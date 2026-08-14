#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Otimizacao REAL item a item - Shopee.
Acessa catálogo real e aplica mudanças.
"""
import sys
import time
import json
from pathlib import Path
from datetime import datetime

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

# ============================================================================
# TENTATIVA 1: USAR DADOS DE IMPORT
# ============================================================================

def load_import_catalog():
    """Carrega catalogo dos imports."""
    import_dir = ROOT / "imports"
    if not import_dir.exists():
        return None

    # Procurar por arquivos Excel/JSON de produto
    catalog_files = list(import_dir.glob("*.json")) + list(import_dir.glob("*.xlsx"))

    if not catalog_files:
        return None

    print(f"[ENCONTRADO] Arquivo de catalogo: {catalog_files[0].name}\n")

    # Se for JSON, carregar direto
    if str(catalog_files[0]).endswith(".json"):
        try:
            with open(catalog_files[0], "r", encoding="utf-8") as f:
                data = json.load(f)
                if isinstance(data, list):
                    return data
                if isinstance(data, dict):
                    # Procurar por chave de produtos
                    for key in ["products", "items", "data", "catalog"]:
                        if key in data and isinstance(data[key], list):
                            return data[key]
        except:
            pass

    return None

# ============================================================================
# TENTATIVA 2: USAR API SHOPEE
# ============================================================================

def optimize_via_shopee_api():
    """Otimiza via API Shopee - RECOMENDADO."""
    try:
        from utils.shopee_client import ShopeeClient
        print("[API] Conectando a Shopee API...\n")

        client = ShopeeClient()

        print("[API] Carregando catálogo da Shopee...\n")
        all_products = []

        for i, product in enumerate(client.iter_all_products(page_size=100)):
            all_products.append(product)
            if (i + 1) % 50 == 0:
                print(f"  [{i+1}] produtos carregados...")

        print(f"\n[OK] Total: {len(all_products)} produtos encontrados\n")

        if not all_products:
            print("[AVISO] Catálogo vazio\n")
            return False

        # Processar
        return process_products(all_products, client)

    except RuntimeError as e:
        print(f"[API] Indisponivel: {e}\n")
        return False
    except Exception as e:
        print(f"[API] Erro: {e}\n")
        return False

# ============================================================================
# PROCESSAR PRODUTOS
# ============================================================================

def process_products(products: list, client=None) -> bool:
    """Processa cada produto: analisa e otimiza."""
    stats = {
        "total": len(products),
        "optimized": 0,
        "skipped": 0,
        "errors": 0,
        "changes": [],
        "timestamp": datetime.now().isoformat(),
    }

    print("="*80)
    print(f"OTIMIZANDO {len(products)} PRODUTOS")
    print("="*80)
    print()

    for idx, product in enumerate(products, 1):
        try:
            item_id = product.get("item_id")
            current_title = (product.get("item_name") or "").strip()

            if not current_title:
                continue

            # Gerar otimizacoes
            optimized_title = generate_optimized_title(product)
            optimized_desc = generate_optimized_description(product, optimized_title)

            title_changed = optimized_title != current_title
            desc_changed = optimized_desc != product.get("description", "")

            # Exibir mudanca (apenas se houver mudanca)
            if title_changed or desc_changed:
                print(f"[{idx}/{len(products)}] Item {item_id}")
                print(f"  Titulo ANTES:")
                print(f"    {current_title[:70]}")
                print(f"  Titulo DEPOIS:")
                print(f"    {optimized_title[:70]}")

                # Aplicar via API se disponivel
                if client:
                    try:
                        client.update_product(
                            int(item_id),
                            title=optimized_title if title_changed else None,
                            description=optimized_desc if desc_changed else None,
                        )

                        # Verificar
                        verified = client.get_product_details([int(item_id)])
                        if verified and verified[0].get("item_name") == optimized_title:
                            print(f"  [OK] ATUALIZADO E VERIFICADO")
                            stats["optimized"] += 1
                            stats["changes"].append({
                                "item_id": item_id,
                                "title_before": current_title,
                                "title_after": optimized_title,
                            })
                        else:
                            print(f"  [ERRO] Verificacao falhou")
                            stats["errors"] += 1

                    except Exception as e:
                        print(f"  [ERRO] {e}")
                        stats["errors"] += 1

                else:
                    # Modo simulacao (sem API)
                    print(f"  [SIM] Seria atualizado (simulacao)")
                    stats["optimized"] += 1
                    stats["changes"].append({
                        "item_id": item_id,
                        "title_before": current_title,
                        "title_after": optimized_title,
                        "simulated": True,
                    })

            else:
                if idx <= 5 or idx % 20 == 0:
                    print(f"[{idx}/{len(products)}] Item {item_id} - OK (sem mudancas)")
                stats["skipped"] += 1

            print()

            # Delay para nao sobrecarregar
            if idx % 20 == 0:
                time.sleep(2)

        except Exception as e:
            print(f"[{idx}] ERRO: {e}\n")
            stats["errors"] += 1

    # Resumo
    print("\n" + "="*80)
    print("RESUMO FINAL")
    print("="*80)
    print(f"Total processado: {stats['total']}")
    print(f"Otimizados: {stats['optimized']}")
    print(f"Sem mudancas: {stats['skipped']}")
    print(f"Erros: {stats['errors']}")
    print()

    # Salvar relatorio
    report_dir = ROOT / "logs" / "shopee-optimizer"
    report_dir.mkdir(parents=True, exist_ok=True)

    report_file = report_dir / f"otimizacao_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"

    with open(report_file, "w", encoding="utf-8") as f:
        json.dump(stats, f, ensure_ascii=False, indent=2)

    print(f"[RELATORIO] Salvo em: {report_file.relative_to(ROOT)}\n")

    return stats["optimized"] > 0

# ============================================================================
# FUNCOES DE OTIMIZACAO
# ============================================================================

def generate_optimized_title(product: dict) -> str:
    """Gera titulo otimizado."""
    original = (product.get("item_name") or "").strip()

    if not original or len(original) > 110:
        return original[:120]

    # Extrair atributos
    attrs = product.get("attribute_list") or []
    improvements = []

    for attr in attrs:
        name = (attr.get("attribute_name") or "").lower()
        values = attr.get("attribute_value_list") or []

        if name in {"marca", "modelo"} and values:
            val = str(values[0].get("value_unit") or values[0].get("original_value_name") or "").strip()
            if val and val.lower() not in original.lower():
                improvements.append(val)

    # Adicionar SEO keywords
    if "qualidade" not in original.lower():
        improvements.append("Qualidade")

    # Construir
    result = original
    for imp in improvements[:2]:
        test = f"{result} {imp}"
        if len(test) <= 120:
            result = test

    return result[:120].strip()

def generate_optimized_description(product: dict, title: str) -> str:
    """Gera descricao otimizada."""
    original = (product.get("description") or "").strip()

    # Se ja tem boa, manter
    if len(original) > 100:
        return original

    # Construir
    parts = [title]

    if original:
        parts.append("")
        parts.append(original)

    # Specs
    attrs = product.get("attribute_list") or []
    specs = []

    for attr in attrs:
        name = (attr.get("attribute_name") or "").strip()
        values = attr.get("attribute_value_list") or []

        if values:
            vals = [str(v.get("value_unit") or v.get("original_value_name") or "").strip() for v in values]
            vals = [v for v in vals if v]
            if vals:
                specs.append(f"- {name}: {', '.join(vals)}")

    if specs:
        parts += ["", "Especificacoes:", *specs[:8]]

    parts.append("\nConfira antes de comprar.")

    return "\n".join(parts)[:3000]

# ============================================================================
# MAIN
# ============================================================================

def main():
    print("\n" + "="*80)
    print("OTIMIZADOR INTELIGENTE DE TITULOS - SHOPEE")
    print("MODO: ITEM A ITEM AUTOMATICO")
    print("="*80 + "\n")

    # Tentar carregar catalogo
    products = None

    # Modo 1: API Shopee
    print("[1/2] Tentando conectar a Shopee API...\n")
    if optimize_via_shopee_api():
        return 0

    # Modo 2: Dados de import
    print("[2/2] Procurando catalogo local...\n")
    products = load_import_catalog()

    if products:
        print(f"[ENCONTRADO] {len(products)} produtos\n")
        process_products(products, client=None)
        return 0

    print("[ERRO] Nao conseguiu acessar catálogo")
    print("  - Shopee API nao disponivel (configure SHOPEE_PARTNER_ID, etc)")
    print("  - Nenhum arquivo de catalogo encontrado")
    print()
    print("[ALTERNATIVA] Executar:")
    print("  $ python scripts/shopee_quick_analysis.py")
    print()

    return 1

if __name__ == "__main__":
    sys.exit(main())
