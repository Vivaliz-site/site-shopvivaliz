#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import sys
from datetime import UTC, datetime
from pathlib import Path

import joblib
import numpy as np
import psycopg
from xgboost import XGBRegressor

ROOT = Path(__file__).resolve().parents[1]
MODEL_DIR = ROOT / "storage" / "ml"
MODEL_PATH = MODEL_DIR / "ranking-model.joblib"
SCORES_PATH = MODEL_DIR / "product-scores.json"
STATUS_PATH = MODEL_DIR / "training-status.json"
MODEL_VERSION = datetime.now(UTC).strftime("%Y%m%dT%H%M%SZ")


def database_url() -> str:
    value = os.getenv("ML_DATABASE_URL", "").strip()
    if not value:
        raise RuntimeError("ML_DATABASE_URL is required")
    return value


def load_rows(conn: psycopg.Connection) -> list[dict]:
    query = """
        SELECT product_id,
               COUNT(*) FILTER (WHERE event_type='page_view')::int AS views,
               COUNT(*) FILTER (WHERE event_type='click')::int AS clicks,
               COUNT(*) FILTER (WHERE event_type='add_to_cart')::int AS carts,
               COUNT(*) FILTER (WHERE event_type='purchase')::int AS purchases,
               COUNT(DISTINCT visitor_id)::int AS visitors
        FROM events
        WHERE occurred_at >= NOW() - INTERVAL '90 days'
        GROUP BY product_id
    """
    with conn.cursor() as cur:
        cur.execute(query)
        columns = [column.name for column in cur.description]
        return [dict(zip(columns, row, strict=True)) for row in cur.fetchall()]


def write_status(status: str, **extra: object) -> None:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    payload = {"status": status, "generated_at": datetime.now(UTC).isoformat(), **extra}
    STATUS_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    with psycopg.connect(database_url()) as conn:
        rows = load_rows(conn)
        total_events = sum(int(row["views"] + row["clicks"] + row["carts"] + row["purchases"]) for row in rows)
        purchase_products = sum(1 for row in rows if int(row["purchases"]) > 0)
        if len(rows) < 8 or total_events < 100 or purchase_products < 3:
            write_status(
                "insufficient_real_data",
                products=len(rows),
                events=total_events,
                products_with_purchase=purchase_products,
                model_updated=False,
            )
            print("INSUFFICIENT_REAL_DATA")
            return 0

        features = np.asarray([
            [row["views"], row["clicks"], row["carts"], row["visitors"]]
            for row in rows
        ], dtype=np.float32)
        targets = np.asarray([
            (row["purchases"] + 0.25 * row["carts"]) / max(1, row["views"])
            for row in rows
        ], dtype=np.float32)
        model = XGBRegressor(
            n_estimators=120,
            max_depth=3,
            learning_rate=0.05,
            subsample=0.85,
            colsample_bytree=0.9,
            objective="reg:squarederror",
            n_jobs=1,
            random_state=42,
        )
        model.fit(features, targets)
        scores = np.maximum(model.predict(features), 0.0)
        joblib.dump({"model": model, "version": MODEL_VERSION}, MODEL_PATH)

        output = {}
        with conn.cursor() as cur:
            for row, score in zip(rows, scores, strict=True):
                product_id = str(row["product_id"])
                value = float(score)
                output[product_id] = value
                cur.execute(
                    """
                    INSERT INTO metrics(product_id, score, views, clicks, carts, purchases, model_version, updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,NOW())
                    ON CONFLICT(product_id) DO UPDATE SET
                      score=EXCLUDED.score, views=EXCLUDED.views, clicks=EXCLUDED.clicks,
                      carts=EXCLUDED.carts, purchases=EXCLUDED.purchases,
                      model_version=EXCLUDED.model_version, updated_at=NOW()
                    """,
                    (product_id, value, row["views"], row["clicks"], row["carts"], row["purchases"], MODEL_VERSION),
                )
        conn.commit()
        SCORES_PATH.write_text(json.dumps({
            "generated_at": datetime.now(UTC).isoformat(),
            "model_version": MODEL_VERSION,
            "scores": output,
        }, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        write_status("trained", products=len(rows), events=total_events, model_updated=True, model_version=MODEL_VERSION)
        print(f"TRAINED products={len(rows)} events={total_events} version={MODEL_VERSION}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        write_status("failed", error=type(exc).__name__, model_updated=False)
        print(f"TRAINING_FAILED {type(exc).__name__}", file=sys.stderr)
        raise
