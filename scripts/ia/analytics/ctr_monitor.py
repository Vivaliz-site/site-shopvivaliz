"""
Monitor de CTR por produto.
Detecta produtos com performance ruim e marca para regeneração de imagem.
"""
import csv
import json
from datetime import datetime, timezone
from pathlib import Path

REPORT_FILE = Path(__file__).parents[3] / "storage" / "logs" / "ctr_report.csv"
REPORT_FILE.parent.mkdir(parents=True, exist_ok=True)

_FIELDS = ["timestamp", "product_id", "platform", "ctr", "views",
           "clicks", "sales", "flag", "action"]
CTR_LOW_THRESHOLD = 0.02   # CTR < 2% com mínimo de views = ruim
MIN_VIEWS_TO_JUDGE = 50    # precisa de pelo menos 50 views para julgar


def _ensure_header():
    if not REPORT_FILE.exists() or REPORT_FILE.stat().st_size == 0:
        with open(REPORT_FILE, "w", newline="", encoding="utf-8") as f:
            csv.writer(f).writerow(_FIELDS)

_ensure_header()


def analyze_shopee_metrics(metrics: list[dict]) -> list[dict]:
    """
    Analisa métricas Shopee e retorna produtos que precisam de nova imagem.
    metrics: lista retornada por ShopeeClient.get_product_metrics()
    """
    flagged = []
    for m in metrics:
        pid = str(m.get("item_id") or m.get("id") or "")
        views = int(m.get("page_views") or m.get("views") or 0)
        clicks = int(m.get("clicks") or 0)
        sales = int(m.get("orders") or m.get("sales") or 0)

        if views < MIN_VIEWS_TO_JUDGE:
            continue

        ctr = clicks / views if views > 0 else 0
        flag = ctr < CTR_LOW_THRESHOLD
        action = "regenerate_images" if flag else "ok"

        _log(pid, "shopee", ctr, views, clicks, sales, flag, action)

        if flag:
            flagged.append({"product_id": pid, "platform": "shopee", "ctr": ctr, "action": action})

    return flagged


def analyze_tiktok_metrics(metrics: list[dict]) -> list[dict]:
    """
    Analisa métricas TikTok e retorna produtos que precisam de nova imagem.
    metrics: lista retornada por TikTokClient.get_product_metrics()
    """
    flagged = []
    for m in metrics:
        pid = str(m.get("product_id") or m.get("id") or "")
        views = int(m.get("view_count") or m.get("views") or 0)
        clicks = int(m.get("click_count") or m.get("clicks") or 0)
        sales = int(m.get("order_count") or m.get("sales") or 0)

        if views < MIN_VIEWS_TO_JUDGE:
            continue

        ctr = clicks / views if views > 0 else 0
        flag = ctr < CTR_LOW_THRESHOLD
        action = "regenerate_images" if flag else "ok"

        _log(pid, "tiktok", ctr, views, clicks, sales, flag, action)

        if flag:
            flagged.append({"product_id": pid, "platform": "tiktok", "ctr": ctr, "action": action})

    return flagged


def _log(pid, platform, ctr, views, clicks, sales, flag, action):
    ts = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    with open(REPORT_FILE, "a", newline="", encoding="utf-8") as f:
        csv.writer(f).writerow([ts, pid, platform, f"{ctr:.4f}",
                                  views, clicks, sales, flag, action])


def products_needing_new_images(
    shopee_metrics: list[dict],
    tiktok_metrics: list[dict],
) -> set[str]:
    """Retorna conjunto de IDs de produtos que precisam de nova imagem."""
    flagged_shopee = {m["product_id"] for m in analyze_shopee_metrics(shopee_metrics)}
    flagged_tiktok = {m["product_id"] for m in analyze_tiktok_metrics(tiktok_metrics)}
    return flagged_shopee | flagged_tiktok
