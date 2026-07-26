from __future__ import annotations

import os
from datetime import datetime
from typing import Any, Literal

import psycopg
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="ShopVivaliz ML", docs_url=None, redoc_url=None)


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
