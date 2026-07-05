#!/usr/bin/env python3
"""
ShopVivaliz - Auto-otimização de Imagens
Etapa 7 do pipeline: detecta imagens ruins (baixa resolução, erro, ausentes)
e re-gera automaticamente usando o pipeline de IA.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

SITE_BASE_URL  = os.getenv("SITE_BASE_URL", "https://dev.shopvivaliz.com.br").rstrip("/")
SQUAD_TOKEN    = os.getenv("SQUAD_TOKEN", "")
DEFAULT_REPORT = Path(os.getenv("AI_REPORT_JSON", "logs/ai-images-report.json"))
DEFAULT_OUT    = Path(os.getenv("OPTIMIZE_REPORT", "logs/auto-optimize-report.json"))
MIN_SIZE_BYTES = int(os.getenv("AI_MIN_IMAGE_BYTES", str(10 * 1024)))   # 10 KB mínimo
USER_AGENT     = "ShopVivalizAutoOptimize/1.0"

BAD_STATUSES = {"error", "pending", "uploaded_locally"}


# ---------------------------------------------------------------------------
# Utilitários
# ---------------------------------------------------------------------------

def log(msg: str) -> None:
    print(msg, flush=True)


def fail(msg: str, code: int = 1) -> None:
    print(f"ERRO: {msg}", file=sys.stderr, flush=True)
    sys.exit(code)


def check_url_alive(url: str, timeout: int = 20) -> tuple[bool, str]:
    req = Request(url, headers={"User-Agent": USER_AGENT, "Range": "bytes=0-1023"}, method="GET")
    try:
        with urlopen(req, timeout=timeout) as resp:
            content_type = resp.headers.get("Content-Type", "")
            if not content_type.lower().startswith("image/"):
                return False, f"content-type não é imagem: {content_type}"
            data = resp.read(1024)
            if len(data) < 100:
                return False, "arquivo muito pequeno"
            return True, ""
    except HTTPError as exc:
        return False, f"HTTP {exc.code}"
    except (URLError, TimeoutError) as exc:
        return False, str(exc)


# ---------------------------------------------------------------------------
# Detecção de imagens ruins
# ---------------------------------------------------------------------------

def detect_bad_images(report: dict, check_urls: bool = False) -> List[Dict[str, Any]]:
    bad_products: List[Dict[str, Any]] = []

    for product in report.get("products", []):
        sku      = product.get("sku", "")
        olist_id = product.get("olist_id", "")
        images   = product.get("images", {})
        problems: List[str] = []
        bad_types: List[str] = []

        expected_types = {"fundo_branco", "angulo_45", "lifestyle", "close_up"}
        present_types  = set(images.keys())
        missing        = expected_types - present_types

        if missing:
            problems.append(f"tipos ausentes: {', '.join(sorted(missing))}")
            bad_types.extend(sorted(missing))

        for img_type, img_data in images.items():
            status = img_data.get("status", "")
            error  = img_data.get("error", "")
            url    = img_data.get("site_url", "")

            if status in BAD_STATUSES:
                problems.append(f"{img_type}: status={status} ({error[:60]})")
                if img_type not in bad_types:
                    bad_types.append(img_type)
                continue

            if check_urls and url:
                alive, reason = check_url_alive(url)
                if not alive:
                    problems.append(f"{img_type}: URL inválida ({reason})")
                    if img_type not in bad_types:
                        bad_types.append(img_type)

        if problems or bad_types:
            bad_products.append({
                "sku":       sku,
                "olist_id":  olist_id,
                "problems":  problems,
                "bad_types": bad_types,
            })

    return bad_products


# ---------------------------------------------------------------------------
# Re-geração de imagens ruins
# ---------------------------------------------------------------------------

def build_mini_csv(product_data: Dict[str, Any], original_report: dict, out_path: Path) -> bool:
    """Cria um CSV temporário com um produto para re-gerar apenas os tipos ruins."""
    # Precisamos recuperar a URL da imagem original do produto do relatório completo
    olist_id = product_data.get("olist_id", "")
    sku      = product_data.get("sku", "")

    # Tenta buscar a URL da imagem original via endpoint do site
    url = ""
    if SQUAD_TOKEN:
        req = Request(
            f"{SITE_BASE_URL}/api/agent/ai-image-jobs.php?action=get_product&sku={sku}&olist_id={olist_id}",
            headers={"X-Squad-Token": SQUAD_TOKEN, "User-Agent": USER_AGENT},
            method="GET",
        )
        try:
            with urlopen(req, timeout=20) as resp:
                data = json.loads(resp.read().decode("utf-8", errors="replace"))
                url  = data.get("primary_image_url", "")
        except Exception:
            pass

    if not url:
        log(f"    Sem URL original para {sku or olist_id} — pulando.")
        return False

    out_path.parent.mkdir(parents=True, exist_ok=True)
    with out_path.open("w", encoding="utf-8-sig") as fh:
        fh.write("olist_id,sku,nome,primary_image_url\n")
        nome = sku or olist_id
        fh.write(f'"{olist_id}","{sku}","{nome}","{url}"\n')
    return True


def regenerate_product(product_data: Dict[str, Any], original_report: dict, dry_run: bool) -> Dict[str, Any]:
    sku      = product_data.get("sku", "")
    olist_id = product_data.get("olist_id", "")
    bad_types = product_data.get("bad_types", [])

    log(f"  Re-gerando tipos: {bad_types}")

    if dry_run:
        return {"status": "dry_run", "bad_types": bad_types}

    tmp_csv = Path("logs/tmp-regen-input.csv")
    if not build_mini_csv(product_data, original_report, tmp_csv):
        return {"status": "error", "error": "sem_url_original"}

    cmd = [
        sys.executable,
        "scripts/generate-ai-images.py",
        "--input",   str(tmp_csv),
        "--limit",   "1",
        "--types",   *bad_types,
    ]

    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
        if result.returncode == 0:
            return {"status": "regenerated", "bad_types": bad_types}
        return {"status": "error", "error": result.stderr[-500:]}
    except subprocess.TimeoutExpired:
        return {"status": "error", "error": "timeout ao re-gerar"}
    except Exception as exc:
        return {"status": "error", "error": str(exc)}


# ---------------------------------------------------------------------------
# Atualização do relatório com novas imagens
# ---------------------------------------------------------------------------

def merge_regen_into_report(report: dict, bad_product: dict) -> dict:
    new_report_path = Path("logs/ai-images-report.json")
    if not new_report_path.is_file():
        return report

    new_report = json.loads(new_report_path.read_text(encoding="utf-8"))
    sku        = bad_product.get("sku", "")
    olist_id   = bad_product.get("olist_id", "")

    new_product = next(
        (p for p in new_report.get("products", [])
         if p.get("sku") == sku or p.get("olist_id") == olist_id),
        None,
    )
    if not new_product:
        return report

    for existing in report.get("products", []):
        if existing.get("sku") == sku or existing.get("olist_id") == olist_id:
            existing["images"].update(new_product.get("images", {}))
            break

    return report


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Auto-otimização de imagens ShopVivaliz.")
    p.add_argument("--report",    default=str(DEFAULT_REPORT))
    p.add_argument("--out",       default=str(DEFAULT_OUT))
    p.add_argument("--dry-run",   action="store_true")
    p.add_argument("--check-urls",action="store_true", help="Verifica se URLs das imagens estão acessíveis.")
    p.add_argument("--limit",     type=int, default=0)
    return p.parse_args(argv)


def main(argv: List[str]) -> int:
    args = parse_args(argv)
    log("=== ShopVivaliz Auto-Otimização de Imagens ===")

    report_path = Path(args.report)
    if not report_path.is_file():
        fail(f"Relatório não encontrado: {report_path}")

    report = json.loads(report_path.read_text(encoding="utf-8"))

    log("Detectando imagens ruins...")
    bad_products = detect_bad_images(report, check_urls=args.check_urls)

    if args.limit > 0:
        bad_products = bad_products[:args.limit]

    log(f"Produtos com problemas: {len(bad_products)}")
    for bp in bad_products:
        log(f"  SKU={bp['sku'] or '-'}: {' | '.join(bp['problems'][:3])}")

    if not bad_products:
        log("Nenhum problema detectado. Pipeline de imagens OK.")
        output = {
            "ok":           True,
            "agent":        "shopvivaliz_auto_optimize",
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "bad_products": 0,
            "regenerated":  0,
            "results":      [],
        }
        out_path = Path(args.out)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")
        return 0

    log("\nRe-gerando imagens com problemas...")
    results = []
    regenerated = 0

    for idx, bad in enumerate(bad_products, start=1):
        sku = bad.get("sku", "-")
        log(f"\n[{idx}/{len(bad_products)}] SKU={sku}")
        result = regenerate_product(bad, report, args.dry_run)
        results.append({"sku": sku, "olist_id": bad.get("olist_id", ""), **result})

        if result.get("status") == "regenerated":
            regenerated += 1
            report = merge_regen_into_report(report, bad)

    # Salva relatório atualizado se houve re-gerações
    if regenerated > 0 and not args.dry_run:
        report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
        log(f"\nRelatório principal atualizado: {report_path}")

    output = {
        "ok":           True,
        "agent":        "shopvivaliz_auto_optimize",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "dry_run":      args.dry_run,
        "bad_products": len(bad_products),
        "regenerated":  regenerated,
        "results":      results,
    }

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")

    log(f"\n=== CONCLUÍDO ===")
    log(f"Produtos com problemas: {len(bad_products)}")
    log(f"Re-gerados:             {regenerated}")
    log(f"Relatório: {out_path}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        print("\nInterrompido.", file=sys.stderr)
        raise SystemExit(130)
