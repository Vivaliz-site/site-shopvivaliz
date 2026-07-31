#!/usr/bin/env python3
"""Canonical safety entrypoint for Olist OAuth authorization.

Interactive credentials, disabled TLS verification, browser-form scraping and
printing token fragments are forbidden. OAuth authorization must be completed
through the provider UI and callback using protected secrets.
"""
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
REPORT = ROOT / "artifacts" / "disabled-executors" / "olist-oauth-login.json"


def build_report() -> dict[str, object]:
    return {
        "schema_version": 2,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "status": "blocked",
        "executor": "scripts/marketplace/olist/oauth_login.py",
        "external_operation_performed": False,
        "reason": "legacy automated login used user credentials, disabled TLS verification and exposed token fragments",
        "required_replacement": [
            "provider-hosted authorization UI",
            "validated HTTPS callback",
            "state and PKCE validation when supported",
            "protected secret storage",
            "redacted token refresh evidence",
        ],
    }


def main() -> int:
    payload = build_report()
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(payload), file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
