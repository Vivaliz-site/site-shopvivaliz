"""
Logger centralizado: stdout + CSV em storage/logs/pipeline.csv
"""
import csv
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

LOG_DIR = Path(__file__).parents[2] / "storage" / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOG_DIR / "pipeline.csv"

_FIELDS = ["timestamp", "level", "stage", "product_id", "message"]

def _ensure_header():
    if not LOG_FILE.exists() or LOG_FILE.stat().st_size == 0:
        with open(LOG_FILE, "w", newline="", encoding="utf-8") as f:
            csv.writer(f).writerow(_FIELDS)

_ensure_header()


def _write(level: str, stage: str, message: str, product_id: str = ""):
    ts = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    row = [ts, level, stage, product_id, message]
    with open(LOG_FILE, "a", newline="", encoding="utf-8") as f:
        csv.writer(f).writerow(row)
    color = {"INFO": "\033[0m", "OK": "\033[32m", "WARN": "\033[33m", "ERROR": "\033[31m"}.get(level, "\033[0m")
    pid = f"[{product_id}] " if product_id else ""
    print(f"{color}[{ts}] {level:5s} {stage:20s} {pid}{message}\033[0m", flush=True)


def info(stage: str, message: str, product_id: str = ""):
    _write("INFO", stage, message, product_id)

def ok(stage: str, message: str, product_id: str = ""):
    _write("OK", stage, message, product_id)

def warn(stage: str, message: str, product_id: str = ""):
    _write("WARN", stage, message, product_id)

def error(stage: str, message: str, product_id: str = ""):
    _write("ERROR", stage, message, product_id)
