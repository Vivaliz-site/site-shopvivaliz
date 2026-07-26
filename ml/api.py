from __future__ import annotations

import json
import os
from datetime import datetime
from pathlib import Path
from typing import Any, Literal

import psycopg
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="ShopVivaliz ML", docs_url=None, redoc_url=None)
STORAGE_DIR = Path("/app/storage/ml")


class Event(BaseModel):
    event_type: Literal["page_view", "click", "add_to_cart", "purchase"]
    product_id: str = Field(min_length=1, max_length=191)
    visitor_id: str = Field(min_length=16, max_length=128)
    session_id: str | None = Field(default=None, max_length=128)
    metadata: dict[str, Any] = Field(default_factory=dict)


def connect() -> psycopg.Connection:
    url = os.getenv("ML_DATABASE_URL", "")
    if not url:
        raise RuntimeError("ML_DATABASE_URL is required")
    return psycopg.connect(url)


@app.get("/health")
def health() -> dict:
    try:
        with connect() as conn, conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) FROM events")
            count = int(cur.fetchone()[0])
        return {"ok": True, "events": count, "time": datetime.utcnow().isoformat() + "Z"}
    except Exception as exc:
        raise HTTPException(status_code=503, detail=type(exc).__name__) from exc


@app.get("/status")
def status() -> dict:
    with connect() as conn, conn.cursor() as cur:
        cur.execute("SELECT COUNT(*) FROM events")
        total_events = int(cur.fetchone()[0])
        cur.execute("SELECT COUNT(DISTINCT product_id) FROM events")
        products_with_events = int(cur.fetchone()[0])
        cur.execute("SELECT COUNT(*) FROM metrics")
        products_scored = int(cur.fetchone()[0])

    payload: dict[str, Any] = {
        "ok": True,
        "checked_at": datetime.utcnow().isoformat() + "Z",
        "events": {
            "total": total_events,
            "products_with_events": products_with_events,
        },
        "metrics": {
            "products_scored": products_scored,
        },
    }

    training_path = STORAGE_DIR / "training-status.json"
    scores_path = STORAGE_DIR / "product-scores.json"

    if training_path.is_file():
        payload["training"] = json.loads(training_path.read_text(encoding="utf-8"))
    else:
        payload["training"] = {"status": "missing"}

    if scores_path.is_file():
        scores = json.loads(scores_path.read_text(encoding="utf-8"))
        payload["scores"] = {
            "generated_at": scores.get("generated_at"),
            "model_version": scores.get("model_version"),
            "products_scored": len(scores.get("scores", {}) or {}),
        }
    else:
        payload["scores"] = {
            "generated_at": None,
            "model_version": None,
            "products_scored": 0,
        }

    return payload


@app.post("/event", status_code=202)
def collect(event: Event) -> dict:
    with connect() as conn, conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO events(event_type, product_id, visitor_id, session_id, metadata)
            VALUES (%s,%s,%s,%s,%s)
            """,
            (event.event_type, event.product_id, event.visitor_id, event.session_id, psycopg.types.json.Jsonb(event.metadata)),
        )
        conn.commit()
    return {"ok": True}


@app.get("/predict")
def predict(product_id: str) -> dict:
    with connect() as conn, conn.cursor() as cur:
        cur.execute("SELECT score, model_version, updated_at FROM metrics WHERE product_id=%s", (product_id,))
        row = cur.fetchone()
    if row is None:
        return {"product_id": product_id, "score": None, "trained": False}
    return {"product_id": product_id, "score": float(row[0]), "model_version": row[1], "updated_at": row[2]}
