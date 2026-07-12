#!/usr/bin/env python3
"""Validate email environment configuration without sending messages."""
from __future__ import annotations

import json
import os
from pathlib import Path


def first_env(*names: str) -> tuple[str, str]:
    for name in names:
        value = os.getenv(name, "").strip()
        if value:
            return name, value
    return "", ""


def main() -> int:
    host_name, host = first_env("SMTP_HOST", "EMAIL_SMTP_HOST", "MAIL_HOST")
    port_name, port = first_env("SMTP_PORT", "EMAIL_SMTP_PORT", "MAIL_PORT")
    user_name, user = first_env("SMTP_USER", "EMAIL_USER", "MAIL_USER")
    pass_name, password = first_env("SMTP_PASS", "EMAIL_PASSWORD", "MAIL_PASS")
    to_name, recipients = first_env("EMAIL_TO")
    from_name, sender = first_env("EMAIL_FROM", "SMTP_USER", "EMAIL_USER", "MAIL_USER")

    recipient_list = [item.strip() for item in recipients.split(",") if item.strip()]
    recipients_valid = bool(recipient_list) and all("@" in item and "." in item for item in recipient_list)

    checks = {
        "host": bool(host),
        "port": bool(port),
        "user": bool(user),
        "password": bool(password),
        "recipients": recipients_valid,
        "sender": bool(sender),
    }
    ok = all(checks.values())
    report = {
        "ok": ok,
        "sources": {
            "host": host_name,
            "port": port_name,
            "user": user_name,
            "password": pass_name,
            "recipients": to_name,
            "sender": from_name,
        },
        "checks": checks,
        "recipient_count": len(recipient_list),
    }

    Path("logs").mkdir(exist_ok=True)
    Path("logs/email-config-check.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
