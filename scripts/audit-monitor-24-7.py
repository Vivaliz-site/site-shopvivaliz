#!/usr/bin/env python3
"""Read-only, fail-closed production availability monitor."""
from __future__ import annotations

import hashlib
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import requests

BASE_URL = os.getenv("SHOPVIVALIZ_BASE_URL", "https://shopvivaliz.com.br").rstrip("/")
ROOT = Path(__file__).resolve().parents[1]
LOG_DIR = Path(os.getenv("SHOPVIVALIZ_AUDIT_LOG_DIR", str(ROOT / "logs")))
REPORT = LOG_DIR / "health-status-latest.json"
EVENTS = LOG_DIR / "audit-monitor-events.jsonl"
TIMEOUT_SECONDS = 15


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def failure_evidence(name: str, url: str, started_at: str, expected: int, error: str) -> dict[str, Any]:
    return {
        "name": name,
        "url": url,
        "started_at": started_at,
        "finished_at": utc_now(),
        "passed": False,
        "status_code": None,
        "expected_status_code": expected,
        "body_bytes": 0,
        "body_sha256": None,
        "error": error,
    }


def request_evidence(session: requests.Session, name: str, path: str, expected: int = 200) -> dict[str, Any]:
    url = f"{BASE_URL}{path}"
    started_at = utc_now()
    try:
        response = session.get(url, timeout=TIMEOUT_SECONDS, allow_redirects=True)
    except requests.RequestException as exc:
        return failure_evidence(name, url, started_at, expected, f"{type(exc).__name__}: {exc}")
    body = response.content
    return {
        "name": name,
        "url": url,
        "started_at": started_at,
        "finished_at": utc_now(),
        "passed": response.status_code == expected,
        "status_code": response.status_code,
        "expected_status_code": expected,
        "body_bytes": len(body),
        "body_sha256": hashlib.sha256(body).hexdigest(),
        "error": None if response.status_code == expected else "unexpected_status_code",
    }


def orders_health(session: requests.Session) -> dict[str, Any]:
    name = "orders_health"
    url = f"{BASE_URL}/api/orders/health.php"
    started_at = utc_now()
    try:
        response = session.get(url, timeout=TIMEOUT_SECONDS, allow_redirects=True)
    except requests.RequestException as exc:
        return failure_evidence(name, url, started_at, 200, f"{type(exc).__name__}: {exc}")

    body = response.content
    evidence: dict[str, Any] = {
        "name": name,
        "url": url,
        "started_at": started_at,
        "finished_at": utc_now(),
        "passed": response.status_code == 200,
        "status_code": response.status_code,
        "expected_status_code": 200,
        "body_bytes": len(body),
        "body_sha256": hashlib.sha256(body).hexdigest(),
        "error": None,
    }
    if response.status_code != 200:
        evidence["error"] = "unexpected_status_code"
        return evidence
    try:
        payload = response.json()
    except ValueError as exc:
        evidence.update({"passed": False, "error": f"invalid_health_json:{exc}"})
        return evidence
    evidence["payload_checks"] = {
        "ok": payload.get("ok") is True,
        "quote_signing_configured": payload.get("quote_signing_configured") is True,
    }
    evidence["passed"] = all(evidence["payload_checks"].values())
    if not evidence["passed"]:
        evidence["error"] = "orders_health_contract_failed"
    return evidence


def write_atomic(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    os.replace(temporary, path)


def main() -> int:
    generated_at = utc_now()
    with requests.Session() as session:
        session.headers.update({"User-Agent": "ShopVivaliz-Audit-Monitor/2.1"})
        checks = [
            request_evidence(session, "home", "/"),
            request_evidence(session, "catalog", "/catalogo"),
            request_evidence(session, "checkout", "/checkout"),
            orders_health(session),
        ]

    failures = [item["name"] for item in checks if item.get("passed") is not True]
    report = {
        "schema_version": 2,
        "generated_at": generated_at,
        "base_url": BASE_URL,
        "status": "PASSED" if not failures else "FAILED",
        "passed": not failures,
        "read_only": True,
        "check_count": len(checks),
        "failed_checks": failures,
        "checks": checks,
    }
    write_atomic(REPORT, report)
    with EVENTS.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(report, ensure_ascii=False) + "\n")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["passed"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
