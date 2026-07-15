#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Audita o modulo local de alertas de estoque.

Escopo seguro:
- le arquivos locais do modulo;
- valida JSONL de inscricoes e outbox;
- gera relatorio local;
- nao envia mensagens, nao publica campanhas e nao faz deploy.
"""
from __future__ import annotations

import json
import sys
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parents[1]
DATA_DIR = ROOT / "storage" / "stock-alerts"
SUBSCRIBERS = DATA_DIR / "subscribers.jsonl"
OUTBOX = DATA_DIR / "outbox.jsonl"
SUBSCRIBE_ENDPOINT = ROOT / "api" / "stock-alerts" / "subscribe.php"
PROCESSOR = ROOT / "api" / "stock-alerts" / "process.php"
REPORT_JSON = ROOT / "logs" / "stock-alerts-audit.json"
REPORT_MD = ROOT / "logs" / "stock-alerts-audit.md"


def read_text(path: Path) -> str:
    if not path.is_file():
        return ""
    return path.read_text(encoding="utf-8", errors="ignore")


def read_jsonl(path: Path) -> tuple[list[dict[str, Any]], list[str]]:
    rows: list[dict[str, Any]] = []
    errors: list[str] = []
    if not path.exists():
        return rows, errors

    for line_no, line in enumerate(path.read_text(encoding="utf-8", errors="ignore").splitlines(), start=1):
        line = line.strip()
        if not line:
            continue
        try:
            value = json.loads(line)
        except json.JSONDecodeError as exc:
            errors.append(f"{path.name}:{line_no}: invalid_json: {exc.msg}")
            continue
        if not isinstance(value, dict):
            errors.append(f"{path.name}:{line_no}: expected_object")
            continue
        rows.append(value)
    return rows, errors


def validate_subscriber(row: dict[str, Any], index: int) -> list[str]:
    issues: list[str] = []
    if not str(row.get("id") or "").strip():
        issues.append(f"subscriber[{index}]: missing_id")
    if not str(row.get("sku") or "").strip():
        issues.append(f"subscriber[{index}]: missing_sku")
    email = str(row.get("email") or "").strip()
    if "@" not in email or "." not in email:
        issues.append(f"subscriber[{index}]: invalid_email")
    if str(row.get("status") or "pending") not in {"pending", "queued"}:
        issues.append(f"subscriber[{index}]: unexpected_status")
    return issues


def validate_outbox(row: dict[str, Any], index: int) -> list[str]:
    issues: list[str] = []
    if row.get("type") != "stock_available":
        issues.append(f"outbox[{index}]: unexpected_type")
    if not str(row.get("subscription_id") or "").strip():
        issues.append(f"outbox[{index}]: missing_subscription_id")
    if not str(row.get("sku") or "").strip():
        issues.append(f"outbox[{index}]: missing_sku")
    governance = row.get("governance")
    if not isinstance(governance, dict):
        issues.append(f"outbox[{index}]: missing_governance")
    elif any(bool(governance.get(key)) for key in ("price_changed", "campaign_published", "deploy_triggered")):
        issues.append(f"outbox[{index}]: governance_violation_flag")
    return issues


def main() -> int:
    warnings: list[str] = []
    errors: list[str] = []

    if not SUBSCRIBE_ENDPOINT.is_file():
        errors.append("missing_subscribe_endpoint")
    if not PROCESSOR.is_file():
        errors.append("missing_cli_processor")

    processor_text = read_text(PROCESSOR)
    if "PHP_SAPI !== 'cli'" not in processor_text:
        errors.append("processor_not_cli_guarded")
    if "file_put_contents" not in processor_text or "outbox.jsonl" not in processor_text:
        warnings.append("processor_outbox_write_not_detected")

    subscribe_text = read_text(SUBSCRIBE_ENDPOINT)
    if "FILTER_VALIDATE_EMAIL" not in subscribe_text:
        errors.append("subscribe_email_validation_not_detected")
    if "subscribers.jsonl" not in subscribe_text:
        errors.append("subscribe_storage_not_detected")

    subscribers, subscriber_parse_errors = read_jsonl(SUBSCRIBERS)
    outbox, outbox_parse_errors = read_jsonl(OUTBOX)
    errors.extend(subscriber_parse_errors)
    errors.extend(outbox_parse_errors)

    for index, row in enumerate(subscribers):
        errors.extend(validate_subscriber(row, index))
    for index, row in enumerate(outbox):
        errors.extend(validate_outbox(row, index))

    if not DATA_DIR.exists():
        warnings.append("data_dir_not_created_yet")
    if not subscribers:
        warnings.append("no_subscribers_yet")
    if not outbox:
        warnings.append("outbox_empty")

    status = "HEALTHY"
    if errors:
        status = "CRITICAL"
    elif warnings:
        status = "WARNING"

    report = {
        "generated_at": datetime.now(UTC).isoformat().replace("+00:00", "Z"),
        "status": status,
        "summary": {
            "subscribers": len(subscribers),
            "pending_subscribers": sum(1 for row in subscribers if row.get("status", "pending") == "pending"),
            "queued_subscribers": sum(1 for row in subscribers if row.get("status") == "queued"),
            "outbox_messages": len(outbox),
        },
        "checks": {
            "subscribe_endpoint": str(SUBSCRIBE_ENDPOINT.relative_to(ROOT)),
            "cli_processor": str(PROCESSOR.relative_to(ROOT)),
            "data_dir": str(DATA_DIR.relative_to(ROOT)),
            "subscribers_file_exists": SUBSCRIBERS.exists(),
            "outbox_file_exists": OUTBOX.exists(),
            "processor_cli_guarded": "PHP_SAPI !== 'cli'" in processor_text,
        },
        "warnings": warnings,
        "errors": errors,
    }

    REPORT_JSON.parent.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    REPORT_MD.write_text(markdown_report(report), encoding="utf-8")

    print(json.dumps(report, ensure_ascii=False))
    return 1 if errors else 0


def markdown_report(report: dict[str, Any]) -> str:
    lines = [
        "# Stock alerts audit",
        "",
        f"- generated_at: {report['generated_at']}",
        f"- status: {report['status']}",
        f"- subscribers: {report['summary']['subscribers']}",
        f"- pending_subscribers: {report['summary']['pending_subscribers']}",
        f"- queued_subscribers: {report['summary']['queued_subscribers']}",
        f"- outbox_messages: {report['summary']['outbox_messages']}",
        "",
        "## Checks",
        "",
    ]
    for key, value in report["checks"].items():
        lines.append(f"- {key}: {value}")

    lines.extend(["", "## Warnings", ""])
    lines.extend([f"- {item}" for item in report["warnings"]] or ["- none"])
    lines.extend(["", "## Errors", ""])
    lines.extend([f"- {item}" for item in report["errors"]] or ["- none"])
    lines.append("")
    return "\n".join(lines)


if __name__ == "__main__":
    raise SystemExit(main())
