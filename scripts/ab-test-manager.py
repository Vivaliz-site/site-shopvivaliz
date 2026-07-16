#!/usr/bin/env python3
"""
ShopVivaliz - Gerenciador de Testes A/B
Etapa 6 do pipeline: registra imagens geradas como variantes A/B,
coleta métricas e define imagem vencedora via API do site.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

SITE_BASE_URL   = os.getenv("SITE_BASE_URL", "https://dev.shopvivaliz.com.br").rstrip("/")
SQUAD_TOKEN     = os.getenv("SQUAD_TOKEN", "")
DEFAULT_REPORT  = Path(os.getenv("AI_REPORT_JSON", "logs/ai-images-report.json"))
DEFAULT_OUT     = Path(os.getenv("AB_REPORT_JSON",  "logs/ab-test-report.json"))
SLEEP_BETWEEN   = float(os.getenv("AB_SLEEP", "0.5"))
USER_AGENT      = "ShopVivalizABTestManager/1.0"

# Tipos de imagem para A/B test (pares a comparar)
AB_PAIRS = [
    ("fundo_branco", "lifestyle"),    # Shopee: fundo branco vs lifestyle
    ("fundo_branco", "angulo_45"),    # Shopee: fundo branco vs 45°
]


# ---------------------------------------------------------------------------
# HTTP helpers
# ---------------------------------------------------------------------------

def log(msg: str) -> None:
    print(msg, flush=True)


def fail(msg: str, code: int = 1) -> None:
    print(f"ERRO: {msg}", file=sys.stderr, flush=True)
    sys.exit(code)


def api_post(endpoint: str, payload: dict, timeout: int = 30) -> dict:
    url  = f"{SITE_BASE_URL}{endpoint}"
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req  = Request(
        url,
        data=body,
        headers={
            "Content-Type":    "application/json",
            "X-Squad-Token":   SQUAD_TOKEN,
            "User-Agent":      USER_AGENT,
        },
        method="POST",
    )
    try:
        with urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            return json.loads(raw) if raw else {}
    except HTTPError as exc:
        body_err = exc.read().decode("utf-8", errors="replace")
        return {"ok": False, "_http_status": exc.code, "_error": body_err[:500]}
    except (URLError, TimeoutError) as exc:
        return {"ok": False, "_http_status": 0, "_error": str(exc)}
    except json.JSONDecodeError as exc:
        return {"ok": False, "_http_status": 0, "_error": f"invalid_json:{exc}"}


def api_get(endpoint: str, timeout: int = 30) -> dict:
    url = f"{SITE_BASE_URL}{endpoint}"
    req = Request(
        url,
        headers={
            "X-Squad-Token": SQUAD_TOKEN,
            "User-Agent":    USER_AGENT,
        },
        method="GET",
    )
    try:
        with urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            return json.loads(raw) if raw else {}
    except HTTPError as exc:
        return {"ok": False, "_http_status": exc.code}
    except Exception as exc:
        return {"ok": False, "_error": str(exc)}


# ---------------------------------------------------------------------------
# Registro de sessão A/B
# ---------------------------------------------------------------------------

def register_ab_session(product: dict, variant_a: str, variant_b: str) -> dict:
    """Registra uma sessão A/B no banco via endpoint do site."""
    sku      = product.get("sku", "")
    olist_id = product.get("olist_id", "")
    images   = product.get("images", {})

    url_a = (images.get(variant_a) or {}).get("site_url", "")
    url_b = (images.get(variant_b) or {}).get("site_url", "")

    if not url_a or not url_b:
        return {
            "status":   "skip",
            "reason":   f"URLs ausentes para {variant_a}/{variant_b}",
            "variant_a": variant_a,
            "variant_b": variant_b,
        }

    payload = {
        "sku":             sku,
        "olist_id":        olist_id,
        "variant_a_type":  variant_a,
        "variant_a_url":   url_a,
        "variant_b_type":  variant_b,
        "variant_b_url":   url_b,
        "started_at":      datetime.now(timezone.utc).isoformat(),
        "status":          "running",
    }

    resp = api_post("/api/agent/ai-image-jobs.php?action=ab_register", payload)
    return {
        "status":    "registered" if resp.get("ok") else "error",
        "session_id": resp.get("session_id"),
        "error":     resp.get("_error", ""),
        "variant_a": variant_a,
        "variant_a_url": url_a,
        "variant_b": variant_b,
        "variant_b_url": url_b,
    }


# ---------------------------------------------------------------------------
# Consulta de métricas e definição de vencedor
# ---------------------------------------------------------------------------

def check_winner(session_id: str) -> dict:
    """Consulta métricas de uma sessão A/B e retorna vencedor se disponível."""
    resp = api_get(f"/api/agent/ai-image-jobs.php?action=ab_metrics&session_id={session_id}")
    return resp


def declare_winner(session_id: str, winner_type: str, winner_url: str) -> bool:
    resp = api_post("/api/agent/ai-image-jobs.php?action=ab_winner", {
        "session_id":   session_id,
        "winner_type":  winner_type,
        "winner_url":   winner_url,
        "decided_at":   datetime.now(timezone.utc).isoformat(),
    })
    return bool(resp.get("ok"))


# ---------------------------------------------------------------------------
# Modo de análise de sessões existentes
# ---------------------------------------------------------------------------

def analyze_existing_sessions(min_impressions: int = 100) -> List[dict]:
    """Busca sessões A/B com impressões suficientes e decide vencedor."""
    resp = api_get(f"/api/agent/ai-image-jobs.php?action=ab_list&status=running&min_impressions={min_impressions}")
    sessions = resp.get("sessions", [])
    results  = []

    for session in sessions:
        session_id = session.get("session_id", "")
        metrics    = check_winner(session_id)

        clicks_a  = int(metrics.get("clicks_a",   0))
        clicks_b  = int(metrics.get("clicks_b",   0))
        sales_a   = int(metrics.get("sales_a",    0))
        sales_b   = int(metrics.get("sales_b",    0))
        total     = clicks_a + clicks_b

        if total < min_impressions:
            results.append({"session_id": session_id, "status": "insufficient_data"})
            continue

        score_a = (sales_a * 3) + clicks_a
        score_b = (sales_b * 3) + clicks_b

        if score_a == score_b:
            results.append({"session_id": session_id, "status": "tie"})
            continue

        if score_a > score_b:
            winner_type = metrics.get("variant_a_type", "A")
            winner_url  = metrics.get("variant_a_url",  "")
        else:
            winner_type = metrics.get("variant_b_type", "B")
            winner_url  = metrics.get("variant_b_url",  "")

        ok = declare_winner(session_id, winner_type, winner_url)
        results.append({
            "session_id":   session_id,
            "sku":          session.get("sku", ""),
            "winner_type":  winner_type,
            "winner_url":   winner_url,
            "score_a":      score_a,
            "score_b":      score_b,
            "status":       "winner_declared" if ok else "error_declaring",
        })
        log(f"  Sessão {session_id}: vencedor {winner_type} (score {max(score_a, score_b)} vs {min(score_a, score_b)})")

    return results


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Gerenciador A/B de imagens ShopVivaliz.")
    sub = p.add_subparsers(dest="command")

    reg = sub.add_parser("register", help="Registra novas sessões A/B a partir do relatório de imagens.")
    reg.add_argument("--report",  default=str(DEFAULT_REPORT))
    reg.add_argument("--out",     default=str(DEFAULT_OUT))
    reg.add_argument("--dry-run", action="store_true")
    reg.add_argument("--limit",   type=int, default=0)

    sub.add_parser("analyze", help="Analisa sessões existentes e declara vencedores.")

    return p.parse_args(argv)


def cmd_register(args: argparse.Namespace) -> int:
    report_path = Path(args.report)
    if not report_path.is_file():
        fail(f"Relatório não encontrado: {report_path}")

    report   = json.loads(report_path.read_text(encoding="utf-8"))
    products = report.get("products", [])
    if args.limit > 0:
        products = products[:args.limit]

    log(f"Registrando A/B para {len(products)} produtos...")
    all_results = []

    for idx, product in enumerate(products, start=1):
        sku = product.get("sku", "-")
        log(f"\n[{idx}/{len(products)}] SKU={sku}")

        product_results = []
        for variant_a, variant_b in AB_PAIRS:
            if args.dry_run:
                log(f"  dry-run: {variant_a} vs {variant_b}")
                product_results.append({
                    "status": "dry_run", "variant_a": variant_a, "variant_b": variant_b
                })
                continue

            result = register_ab_session(product, variant_a, variant_b)
            product_results.append(result)
            log(f"  {variant_a} vs {variant_b}: {result['status']}")
            time.sleep(SLEEP_BETWEEN)

        all_results.append({
            "sku":      product.get("sku", ""),
            "olist_id": product.get("olist_id", ""),
            "sessions": product_results,
        })

    output = {
        "ok":           True,
        "agent":        "shopvivaliz_ab_test_manager",
        "command":      "register",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "dry_run":      args.dry_run,
        "total":        len(all_results),
        "results":      all_results,
    }

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")
    log(f"\nRelatório salvo: {out_path}")
    return 0


def cmd_analyze() -> int:
    log("=== Analisando sessões A/B existentes ===")
    results = analyze_existing_sessions(min_impressions=50)
    decided = sum(1 for r in results if r.get("status") == "winner_declared")
    log(f"\nSessões analisadas: {len(results)} | Vencedores declarados: {decided}")
    return 0


def main(argv: List[str]) -> int:
    args = parse_args(argv)

    log("=== ShopVivaliz A/B Test Manager ===")

    if args.command == "register":
        return cmd_register(args)
    elif args.command == "analyze":
        return cmd_analyze()
    else:
        log("Use: ab-test-manager.py register | analyze")
        return 1


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        print("\nInterrompido.", file=sys.stderr)
        raise SystemExit(130)
