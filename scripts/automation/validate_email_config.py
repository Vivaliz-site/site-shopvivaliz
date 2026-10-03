#!/usr/bin/env python3
"""Validate email environment configuration without sending messages."""
from __future__ import annotations

import json
import os
from pathlib import Path


def load_env_files(paths: list[str]) -> None:
    for raw_path in paths:
        path = Path(raw_path)
        if not path.exists():
            continue
        for raw_line in path.read_text(encoding="utf-8").splitlines():
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            key = key.strip()
            value = value.strip().strip("\"'")
            if key and key not in os.environ:
                os.environ[key] = value


def first_env(*names: str) -> tuple[str, str]:
    for name in names:
        value = os.getenv(name, "").strip()
        if value:
            return name, value
    return "", ""


def inspect_config() -> dict:
    load_env_files([".env", ".env.local"])
    key_name, api_key = first_env("BREVO_API_KEY")
    to_name, recipients = first_env("EMAIL_TO")
    recipient_list = [item.strip() for item in recipients.split(",") if item.strip()]
    recipients_valid = bool(recipient_list) and all("@" in item and "." in item for item in recipient_list)

    checks = {
        "brevo_api_key": bool(api_key),
        "recipients": recipients_valid,
        "fixed_sender": True,
        "fixed_reply_to": True,
    }
    return {
        "ok": all(checks.values()),
        "provider": "brevo_api",
        "sources": {
            "api_key": key_name,
            "recipients": to_name,
        },
        "checks": checks,
        "recipient_count": len(recipient_list),
        "sender": "atendimento@shopvivaliz.com.br",
        "reply_to": "atendimento@shopvivaliz.com.br",
    }


def main() -> int:
    report = inspect_config()

    Path("logs").mkdir(exist_ok=True)
    Path("logs/email-config-check.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if report.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
