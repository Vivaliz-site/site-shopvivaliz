#!/usr/bin/env python3
"""Fail-fast, secret-safe validation for Google Ads authentication inputs.

This script performs no network calls and never prints secret values. It catches
obviously stale or malformed OAuth configuration before the Google Ads SDK is
installed or any live API call is attempted.
"""

from __future__ import annotations

import os
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
REQUIRED_ENV = [
    "GOOGLE_OAUTH_CLIENT_ID",
    "GOOGLE_OAUTH_CLIENT_SECRET",
    "GOOGLE_ADS_CUSTOMER_ID",
    "GOOGLE_ADS_DEVELOPER_TOKEN",
]
OAUTH_CLIENT_RE = re.compile(r"^[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$")
CUSTOMER_ID_RE = re.compile(r"^\d{10}$")


def load_dotenv(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


def is_placeholder(value: str) -> bool:
    lowered = value.strip().casefold()
    return (
        not lowered
        or lowered in {"placeholder", "changeme", "xxx"}
        or lowered.startswith(("your_", "obter", "<"))
        or lowered.endswith(">")
    )


def main() -> int:
    load_dotenv(ROOT / ".env")
    errors: list[str] = []

    for key in REQUIRED_ENV:
        if is_placeholder(os.getenv(key, "")):
            errors.append(f"{key}=MISSING_OR_PLACEHOLDER")

    refresh_token = os.getenv("GOOGLE_OAUTH_REFRESH_TOKEN", "").strip() or os.getenv("GOOGLE_ADS_REFRESH_TOKEN", "").strip()
    if is_placeholder(refresh_token):
        errors.append("GOOGLE_OAUTH_REFRESH_TOKEN_OR_GOOGLE_ADS_REFRESH_TOKEN=MISSING_OR_PLACEHOLDER")

    client_id = os.getenv("GOOGLE_OAUTH_CLIENT_ID", "").strip()
    if client_id and not is_placeholder(client_id) and not OAUTH_CLIENT_RE.fullmatch(client_id):
        errors.append("GOOGLE_OAUTH_CLIENT_ID=FORMAT_INVALID_EXPECTED_*.apps.googleusercontent.com")

    customer_id = os.getenv("GOOGLE_ADS_CUSTOMER_ID", "").replace("-", "").strip()
    if customer_id and not is_placeholder(customer_id) and not CUSTOMER_ID_RE.fullmatch(customer_id):
        errors.append("GOOGLE_ADS_CUSTOMER_ID=FORMAT_INVALID_EXPECTED_10_DIGITS")

    login_customer_id = os.getenv("GOOGLE_ADS_LOGIN_CUSTOMER_ID", "").replace("-", "").strip()
    if login_customer_id and not CUSTOMER_ID_RE.fullmatch(login_customer_id):
        errors.append("GOOGLE_ADS_LOGIN_CUSTOMER_ID=FORMAT_INVALID_EXPECTED_10_DIGITS")

    if errors:
        print("GOOGLE_ADS_AUTH_PREFLIGHT_FAILED")
        for error in errors:
            print(error)
        print("NO_SECRET_VALUES_PRINTED")
        return 1

    print("GOOGLE_ADS_AUTH_PREFLIGHT_OK")
    print("client_id_format=valid")
    print("customer_id_format=valid")
    print("required_secret_presence=valid")
    print("NO_SECRET_VALUES_PRINTED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
