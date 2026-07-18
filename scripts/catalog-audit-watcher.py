#!/usr/bin/env python3
"""Watcher leve que grava um log de auditoria toda vez que
api/catalog/fallback-products.json muda -- criado porque o auditd do
kernel nao capturou nenhum evento real neste ambiente de VM (regras
carregadas com sucesso, mas ausearch nunca retornou nada, mesmo com
escritas reais confirmadas por outro lado). Alternativa: polling do
mtime + snapshot de processos ativos + contagem/sync_source do
catalogo no momento da mudanca, tudo em um log append-only.
"""
import json
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path("/home/ubuntu/site-shopvivaliz")
CATALOG_PATH = ROOT / "api" / "catalog" / "fallback-products.json"
AUDIT_LOG = ROOT / "logs" / "catalog-audit.log"
POLL_SECONDS = 5


def catalog_snapshot() -> str:
    try:
        data = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
        items = data.get("products", data) if isinstance(data, dict) else data
        if isinstance(items, dict) and "products" in items:
            items = items["products"]
        total = len(items) if isinstance(items, list) else 0
        source = ""
        images = 0
        if isinstance(items, list) and items:
            source = items[0].get("sync_source", "")
            images = sum(1 for p in items if isinstance(p, dict) and p.get("images"))
        return f"total={total} sync_source={source} com_imagens={images}"
    except Exception as exc:
        return f"erro_ao_ler_catalogo={exc}"


def process_snapshot() -> str:
    try:
        out = subprocess.run(
            ["ps", "-eo", "pid,ppid,etimes,cmd", "--sort=-etimes"],
            capture_output=True, text=True, timeout=10,
        ).stdout
        lines = [l for l in out.splitlines() if any(
            k in l for k in ("php", "python3", "sync", "olist", "tiny")
        )]
        return "\n    ".join(lines[:25])
    except Exception as exc:
        return f"erro_ao_listar_processos={exc}"


def main() -> None:
    AUDIT_LOG.parent.mkdir(parents=True, exist_ok=True)
    last_mtime = CATALOG_PATH.stat().st_mtime if CATALOG_PATH.exists() else 0
    with AUDIT_LOG.open("a", encoding="utf-8") as f:
        f.write(f"[{datetime.now(timezone.utc).isoformat()}] watcher iniciado. estado atual: {catalog_snapshot()}\n")
        f.flush()

    while True:
        time.sleep(POLL_SECONDS)
        try:
            mtime = CATALOG_PATH.stat().st_mtime if CATALOG_PATH.exists() else 0
        except OSError:
            continue
        if mtime != last_mtime:
            last_mtime = mtime
            ts = datetime.now(timezone.utc).isoformat()
            snap = catalog_snapshot()
            procs = process_snapshot()
            with AUDIT_LOG.open("a", encoding="utf-8") as f:
                f.write(f"\n[{ts}] MUDANCA DETECTADA: {snap}\n")
                f.write(f"    processos ativos no momento:\n    {procs}\n")
                f.flush()


if __name__ == "__main__":
    main()
