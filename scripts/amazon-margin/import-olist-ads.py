#!/usr/bin/env python3
"""Read-only importer for Olist ERP > Cadastros > Anuncios exports.

Never guesses an ERP mapping from title/EAN and never alters prices.
An old export remains HISTORICAL, never certified current provenance.
"""
from __future__ import annotations

import argparse
import csv
from datetime import datetime, timedelta, timezone
import hashlib
import io
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import unicodedata
from zoneinfo import ZoneInfo

INTEGRATIONS = {
    "amazon": "Amazon",
    "amazon onsite": "Amazon Onsite",
    "amazon classic": "Amazon Classic",
}
VALID_EXTENSIONS = {".xls", ".xlsx", ".csv"}
MAX_FILE_BYTES = 8 * 1024 * 1024
MAX_TOTAL_ROWS = 25000
FILENAME_TIMESTAMP = re.compile(
    r"(?i)^anuncios?[-_](\d{4})[-_](\d{2})[-_](\d{2})"
    r"[-_](\d{2})[-_](\d{2})(?:[-_](\d{2}))?"
)
LOCAL_TZ = ZoneInfo("America/Sao_Paulo")


def normalized(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or "").strip())
    return "".join(ch for ch in text if not unicodedata.combining(ch)).lower()


def scalar(value: object) -> str:
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    text = str(value if value is not None else "").strip()
    if len(text) > 120 or any(ord(ch) < 32 for ch in text):
        raise ValueError("invalid_item_identifier")
    if text.startswith(("=", "+", "@")):
        raise ValueError("unsafe_spreadsheet_identifier")
    return text


def observed_at(filename: str) -> datetime | None:
    m = FILENAME_TIMESTAMP.search(filename)
    if not m:
        return None
    parts = [int(v) if v is not None else 0 for v in m.groups()]
    try:
        return datetime(*parts, tzinfo=LOCAL_TZ).astimezone(timezone.utc)
    except ValueError:
        return None


def iter_rows(path: Path):
    extension = path.suffix.lower()
    if extension == ".xls":
        import xlrd  # requirements: xlrd==2.0.2
        wb = xlrd.open_workbook(str(path), on_demand=True,
                                ignore_workbook_corruption=True,
                                logfile=io.StringIO())
        try:
            sheet = wb.sheet_by_index(0)
            if sheet.nrows > MAX_TOTAL_ROWS + 1:
                raise ValueError("too_many_rows")
            for i in range(sheet.nrows):
                yield sheet.row_values(i)
        finally:
            wb.release_resources()
    elif extension == ".xlsx":
        from openpyxl import load_workbook  # requirements: openpyxl==3.1.5
        wb = load_workbook(path, read_only=True, data_only=True)
        try:
            sheet = wb.worksheets[0]
            if sheet.max_row > MAX_TOTAL_ROWS + 1:
                raise ValueError("too_many_rows")
            yield from sheet.iter_rows(values_only=True)
        finally:
            wb.close()
    elif extension == ".csv":
        raw = path.read_bytes()
        try:
            text = raw.decode("utf-8-sig")
        except UnicodeDecodeError:
            text = raw.decode("cp1252")
        try:
            dialect = csv.Sniffer().sniff(text[:5000], delimiters=";,\t")
            reader = csv.reader(io.StringIO(text), dialect)
        except csv.Error:
            reader = csv.reader(io.StringIO(text), delimiter=";")
        yield from reader
    else:
        raise ValueError("unsupported_file_extension")


def header_indexes(header) -> dict[str, int]:
    cells = [normalized(x) for x in header]
    def find(predicate):
        for i, cell in enumerate(cells):
            if predicate(cell):
                return i
        return None
    integration = find(lambda x: x.startswith("integr"))
    identifier = find(lambda x: x == "identificador")
    product = find(lambda x: x.startswith("produto") and "sku" in x)
    if integration is None or identifier is None or product is None:
        raise ValueError("not_an_olist_ads_export")
    return {"integration": integration, "identifier": identifier,
            "product": product}


def build_index(paths: list[Path], now: datetime | None = None,
                fresh_hours: int = 48) -> dict:
    now = now or datetime.now(timezone.utc)
    if not 1 <= len(paths) <= 5:
        raise ValueError("expected_one_to_five_exports")
    if fresh_hours < 1 or fresh_hours > 72:
        raise ValueError("invalid_freshness_limit")
    all_rows: dict[str, list[dict]] = {}
    files: list[dict] = []
    consumed = 0
    for path in paths:
        if not path.is_file() or path.is_symlink() or path.suffix.lower() not in VALID_EXTENSIONS:
            raise ValueError("invalid_export_file")
        if path.stat().st_size < 60 or path.stat().st_size > MAX_FILE_BYTES:
            raise ValueError("invalid_export_size")
        if not FILENAME_TIMESTAMP.search(path.name):
            raise ValueError("missing_olist_export_timestamp")
        observed = observed_at(path.name)
        if observed is None:
            raise ValueError("invalid_olist_export_timestamp")
        if observed > now + timedelta(minutes=10):
            raise ValueError("future_olist_export")
        source_hash = hashlib.sha256(path.read_bytes()).hexdigest()
        indexed = 0
        header = None
        keys = None
        for line_no, row in enumerate(iter_rows(path)):
            consumed += 1
            if consumed > MAX_TOTAL_ROWS:
                raise ValueError("too_many_rows")
            if line_no == 0:
                header = row
                keys = header_indexes(header)
                continue
            if not row or not keys:
                continue
            try:
                integration_raw = scalar(row[keys["integration"]])
                integration = INTEGRATIONS.get(integration_raw.casefold())
                if integration is None:
                    continue  # Other marketplaces are NOT part of Amazon.
                seller_sku = scalar(row[keys["identifier"]])
                erp_sku = scalar(row[keys["product"]])
            except (IndexError, ValueError):
                continue
            if not seller_sku or not erp_sku or len(seller_sku) > 80:
                continue
            all_rows.setdefault(seller_sku, []).append({
                "erp_sku": erp_sku, "integration": integration,
                "observed_at": observed.isoformat(),
                "file_sha256": source_hash,
            })
            indexed += 1
        if header is None or indexed == 0:
            raise ValueError("no_amazon_erp_ads_in_export")
        files.append({
            "name": path.name, "sha256": source_hash,
            "observed_at": observed.isoformat(),
            "integration_rows": indexed,
        })
    entries: dict[str, dict] = {}
    counts = {"unique": 0, "conflicting": 0, "fresh_unique": 0,
              "historical_unique": 0}
    for seller_sku, rows in sorted(all_rows.items()):
        skus = sorted({r["erp_sku"] for r in rows})
        newest = max(datetime.fromisoformat(r["observed_at"]) for r in rows)
        age = now - newest
        fresh = timedelta(0) <= age <= timedelta(hours=fresh_hours)
        unique = len(skus) == 1
        if unique:
            counts["unique"] += 1
            counts["fresh_unique" if fresh else "historical_unique"] += 1
        else:
            counts["conflicting"] += 1
        entries[seller_sku] = {
            "status": "unique" if unique else "conflicting",
            "erp_sku": skus[0] if unique else None,
            "erp_sku_candidates": skus if not unique else [],
            "integrations": sorted({r["integration"] for r in rows}),
            "observed_at": newest.isoformat(),
            "fresh": fresh and unique,
            "source_sha256": sorted({r["file_sha256"] for r in rows}),
        }
    return {
        "schema": 1,
        "source": "OLIST_ERP_ANUNCIOS_EXPORT",
        "created_at": now.isoformat(),
        "freshness_hours": fresh_hours,
        "files": files,
        "stats": {"rows_read": consumed, "unique_seller_skus": len(entries), **counts},
        "entries": entries,
    }


def atomic_save(index: dict, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o2770)
    if path.is_symlink():
        raise ValueError("output_must_not_be_symlink")
    import fcntl
    lock = path.with_suffix(".lock")
    lock_fd = os.open(lock, os.O_CREAT | os.O_RDWR, 0o640)
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        fd, temp = tempfile.mkstemp(prefix=".olist-ads-", dir=path.parent)
        try:
            os.fchmod(fd, 0o640)
            with os.fdopen(fd, "w", encoding="utf-8") as out:
                json.dump(index, out, ensure_ascii=False, separators=(",", ":"))
                out.flush()
                os.fsync(out.fileno())
            os.replace(temp, path)
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
    finally:
        fcntl.flock(lock_fd, fcntl.LOCK_UN)
        os.close(lock_fd)


def main() -> int:
    parser = argparse.ArgumentParser(description="Import Olist ERP Amazon ad links; no commerce writes")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("files", nargs="+", type=Path)
    args = parser.parse_args()
    try:
        index = build_index(args.files)
        atomic_save(index, args.output)
        print(json.dumps({"ok": True, "stats": index["stats"],
                          "files": len(index["files"]),
                          "source": index["source"]}, ensure_ascii=False))
        return 0
    except (OSError, ValueError, ImportError) as e:
        print(json.dumps({"ok": False, "error": str(e)[:180]}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    sys.exit(main())
