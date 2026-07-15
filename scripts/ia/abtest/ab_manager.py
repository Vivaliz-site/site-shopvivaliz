"""
A/B test automático de imagens.
Testa fundo_branco vs lifestyle como imagem principal.
Declara vencedor baseado em CTR/clicks após threshold mínimo.
"""
import csv
import json
import time
from datetime import datetime, timezone
from pathlib import Path

RESULTS_FILE = Path(__file__).parents[3] / "storage" / "logs" / "abtest_results.csv"
RESULTS_FILE.parent.mkdir(parents=True, exist_ok=True)

_FIELDS = ["timestamp", "product_id", "platform", "variant_a", "variant_b",
           "clicks_a", "clicks_b", "winner", "reason"]
MIN_CLICKS_TO_DECIDE = 10


def _ensure_header():
    if not RESULTS_FILE.exists() or RESULTS_FILE.stat().st_size == 0:
        with open(RESULTS_FILE, "w", newline="", encoding="utf-8") as f:
            csv.writer(f).writerow(_FIELDS)

_ensure_header()


def register_session(
    product_id: str,
    platform: str,
    variant_a: str,
    variant_b: str,
) -> dict:
    """
    Registra uma sessão de A/B test.
    Retorna o estado inicial da sessão.
    """
    return {
        "product_id": product_id,
        "platform": platform,
        "variant_a": variant_a,
        "variant_b": variant_b,
        "clicks_a": 0,
        "clicks_b": 0,
        "winner": None,
        "started_at": datetime.now(timezone.utc).isoformat(),
    }


def record_click(session: dict, variant: str) -> dict:
    """Registra um click em uma variante (variant='a' ou 'b')."""
    if variant == "a":
        session["clicks_a"] += 1
    elif variant == "b":
        session["clicks_b"] += 1
    return session


def evaluate(session: dict) -> dict:
    """
    Avalia a sessão e declara vencedor se há clicks suficientes.
    Retorna session atualizada com 'winner' preenchido se decidido.
    """
    total = session["clicks_a"] + session["clicks_b"]
    if total < MIN_CLICKS_TO_DECIDE:
        return session

    # Score simples: clicks ponderados
    score_a = session["clicks_a"]
    score_b = session["clicks_b"]

    if score_a > score_b * 1.1:
        session["winner"] = "a"
        session["winner_variant"] = session["variant_a"]
        session["reason"] = f"A ganhou ({score_a} vs {score_b} clicks)"
    elif score_b > score_a * 1.1:
        session["winner"] = "b"
        session["winner_variant"] = session["variant_b"]
        session["reason"] = f"B ganhou ({score_b} vs {score_a} clicks)"
    else:
        # Empate: preferir fundo_branco (mais neutro para Shopee)
        session["winner"] = "a"
        session["winner_variant"] = session["variant_a"]
        session["reason"] = f"Empate ({score_a} vs {score_b}), preferindo variante A"

    _save_result(session)
    return session


def _save_result(session: dict):
    with open(RESULTS_FILE, "a", newline="", encoding="utf-8") as f:
        csv.writer(f).writerow([
            datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            session["product_id"],
            session["platform"],
            session["variant_a"],
            session["variant_b"],
            session["clicks_a"],
            session["clicks_b"],
            session.get("winner", ""),
            session.get("reason", ""),
        ])


def get_winner_image_type(product_images: dict[str, Path | None], platform: str) -> str:
    """
    Escolhe qual tipo de imagem usar como principal dado o histórico de A/B.
    Default: fundo_branco para Shopee, lifestyle para TikTok.
    """
    # Por ora usa regra determinística (A/B evolui com dados reais)
    if platform == "shopee":
        priority = ["fundo_branco", "angulo_45", "lifestyle", "close_up"]
    else:
        priority = ["lifestyle", "fundo_branco", "angulo_45", "close_up"]

    for t in priority:
        if product_images.get(t):
            return t
    return next(iter(product_images), "fundo_branco")
