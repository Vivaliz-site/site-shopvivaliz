#!/usr/bin/env python3
"""Add missing production credentials to a dotenv file without exposing values.

Input is a NUL-delimited sequence of ``name, value`` pairs. Names must belong
to the explicit runtime allowlist. Existing non-empty values always win; this
writer is for recovery/convergence, not credential rotation.
"""

from __future__ import annotations

import os
import re
import shutil
import sys
import tempfile
import time
from pathlib import Path


EMAIL_KEYS = {
    "BREVO_API_KEY",
    "EMAIL_FROM", "EMAIL_PASSWORD", "EMAIL_SMTP_HOST", "EMAIL_SMTP_PORT", "EMAIL_TO", "EMAIL_USER",
    "MAIL_HOST", "MAIL_PASS", "MAIL_PORT", "MAIL_USER",
}

ALLOWED_KEYS = {
    "ADMIN_EMAIL", "ANTHROPIC_API_KEY", "APP_URL", "BASE_URL",
    "BLOG_PUBLISH_TOKEN", "BREVO_API_KEY",
    "DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS",
    "EMAIL_FROM", "EMAIL_PASSWORD", "EMAIL_SMTP_HOST", "EMAIL_SMTP_PORT", "EMAIL_TO", "EMAIL_USER",
    "GA4_ID", "GEMINI_API_KEY",
    "GOOGLE_ADS_ACCESS_TOKEN", "GOOGLE_ADS_CONVERSION_SOURCE", "GOOGLE_ADS_CUSTOMER_ID",
    "GOOGLE_ADS_DEVELOPER_TOKEN", "GOOGLE_ADS_PURCHASE_CONVERSION_NAME", "GOOGLE_ADS_REFRESH_TOKEN",
    "GOOGLE_ANALYTICS", "GOOGLE_ANALYTICS_ID", "GOOGLE_MERCHANT_ID",
    "GOOGLE_OAUTH_CLIENT_ID", "GOOGLE_OAUTH_CLIENT_SECRET", "GOOGLE_OAUTH_REFRESH_TOKEN",
    "GOOGLE_TAG_MANAGER_ID",
    "LOJA_PIX_KEY", "LOJA_PIX_NAME", "LOJA_WHATSAPP",
    "MAIL_HOST", "MAIL_PASS", "MAIL_PORT", "MAIL_USER",
    "MELHORENVIO_CLIENTE_ID", "MELHORENVIO_CLIENTE_SECRET", "MELHORENVIO_REDIRECT_URI",
    "MERCADOPAGO_ACCESS_TOKEN", "MERCADOPAGO_PUBLIC_KEY", "MERCADOPAGO_WEBHOOK_SECRET",
    "ML_CLIENT_ID", "ML_CLIENT_SECRET", "ML_REDIRECT_URI", "ML_SELLER_ID",
    "OLIST_ACCESS_TOKEN", "OLIST_CLIENT_ID", "OLIST_CLIENT_SECRET", "OLIST_REDIRECT_URI", "OLIST_REFRESH_TOKEN",
    "OPENAI_API_KEY", "QUOTE_SIGNING_KEY",
    "SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY", "SHOPEE_REDIRECT_URI", "SHOPEE_SHOP_ID",
    "SHOPVIVALIZ_AGENT_KEY", "SHOPVIVALIZ_BASE_URL", "SITE_URL",
    "TIKTOK_APP_KEY", "TIKTOK_APP_SECRET", "TIKTOK_AUTH_REGION", "TIKTOK_REDIRECT_URL", "TIKTOK_SERVICE_ID",
    "TINY_ACCESS_TOKEN", "TINY_CLIENT_ID", "TINY_CLIENT_SECRET", "TINY_REDIRECT_URI", "TINY_REFRESH_TOKEN",
    "URL_REDIRCT_OLIST", "URL_TINY_OLIST", "WHATSAPP_NUMBER",
    "AMAZON_LWA_CLIENT_ID", "AMAZON_LWA_CLIENT_SECRET", "AMAZON_LWA_REFRESH_TOKEN",
}

KEY_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
ASSIGNMENT_RE = re.compile(r"^(\s*(?:export\s+)?)([A-Za-z_][A-Za-z0-9_]*)(\s*=)(.*)$")
PLACEHOLDER_MARKERS = (
    "changeme", "placeholder", "your_", "replace_me", "dummy", "token_here",
    "client_id_here", "client_secret_here", "undefined",
)


def clean_effective_value(raw: str) -> str:
    value = raw.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
        value = value[1:-1].strip()
    return value


def validate_value(key: str, value: str) -> None:
    if any(character in value for character in ("\x00", "\r", "\n")):
        raise ValueError(f"invalid control character in {key}")
    normalized = value.strip().lower()
    if normalized and any(marker in normalized for marker in PLACEHOLDER_MARKERS):
        raise ValueError(f"placeholder value refused for {key}")
    if normalized == "null":
        raise ValueError(f"null value refused for {key}")
    if normalized and key.endswith(("TOKEN", "SECRET", "KEY", "PASS", "PASSWORD")) and len(value.strip()) < 8:
        raise ValueError(f"suspiciously short credential refused for {key}")


def parse_payload_fields(fields: list[bytes], scope: str = "all") -> dict[str, str]:
    if scope not in {"all", "email"}:
        raise ValueError(f"unsupported credential scope: {scope}")
    if len(fields) % 2:
        raise ValueError("payload must contain name/value pairs")

    scope_keys = ALLOWED_KEYS if scope == "all" else EMAIL_KEYS
    values: dict[str, str] = {}
    seen: set[str] = set()
    for raw_key, raw_value in zip(fields[0::2], fields[1::2]):
        key = raw_key.decode("ascii")
        value = raw_value.decode("utf-8").strip()
        if not KEY_RE.fullmatch(key) or key not in ALLOWED_KEYS:
            raise ValueError(f"unsupported runtime credential key: {key}")
        if key in seen:
            raise ValueError(f"duplicate runtime credential key: {key}")
        seen.add(key)
        if key not in scope_keys:
            continue
        validate_value(key, value)
        if value:
            values[key] = value
    if not values:
        raise ValueError(f"no non-empty runtime credentials supplied for scope {scope}")
    return values


def read_payload(scope: str = "all") -> dict[str, str]:
    fields = sys.stdin.buffer.read().split(b"\0")
    if fields and fields[-1] == b"":
        fields.pop()
    return parse_payload_fields(fields, scope=scope)


def merge_missing(path: Path, incoming: dict[str, str]) -> tuple[list[str], list[str], Path | None]:
    if not path.is_file():
        raise FileNotFoundError(path)
    original = path.stat()
    lines = path.read_text(encoding="utf-8", errors="strict").splitlines()

    existing_nonempty: set[str] = set()
    for line in lines:
        match = ASSIGNMENT_RE.match(line)
        if match and clean_effective_value(match.group(4)):
            existing_nonempty.add(match.group(2))

    additions = {key: value for key, value in incoming.items() if key not in existing_nonempty}
    preserved = sorted(set(incoming) - set(additions))
    if not additions:
        return [], preserved, None

    output: list[str] = []
    written: set[str] = set()
    for line in lines:
        match = ASSIGNMENT_RE.match(line)
        key = match.group(2) if match else ""
        if key not in additions:
            output.append(line)
            continue
        if key not in written:
            output.append(f"{key}={additions[key]}")
            written.add(key)
        # Drop duplicate empty assignments for a key being recovered.
    for key in sorted(additions):
        if key not in written:
            output.append(f"{key}={additions[key]}")

    backup = path.with_name(f"{path.name}.credential-union-backup.{time.time_ns()}")
    shutil.copy2(path, backup)
    os.chmod(backup, 0o600)
    if hasattr(os, "chown"):
        os.chown(backup, original.st_uid, original.st_gid)

    descriptor, temporary_name = tempfile.mkstemp(prefix=".credential-union.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, original.st_mode & 0o777)
        if hasattr(os, "chown"):
            os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, path)
        if os.name != "nt":
            directory_fd = os.open(path.parent, os.O_RDONLY)
            try:
                os.fsync(directory_fd)
            finally:
                os.close(directory_fd)
    finally:
        temporary.unlink(missing_ok=True)

    final = path.stat()
    if (
        final.st_uid != original.st_uid
        or final.st_gid != original.st_gid
        or (final.st_mode & 0o777) != (original.st_mode & 0o777)
    ):
        raise RuntimeError("shared env metadata changed unexpectedly")
    return sorted(additions), preserved, backup


def main() -> int:
    args = sys.argv[1:]
    scope = "all"
    if len(args) == 3 and args[0] == "--scope":
        scope = args[1]
        args = args[2:]
    if len(args) != 1 or scope not in {"all", "email"}:
        print("usage: merge-runtime-credential-union.py [--scope all|email] SHARED_ENV", file=sys.stderr)
        return 2
    try:
        incoming = read_payload(scope=scope)
        added, preserved, backup = merge_missing(Path(args[0]), incoming)
    except (OSError, UnicodeDecodeError, ValueError, RuntimeError) as exc:
        print(f"credential union failed: {exc}", file=sys.stderr)
        return 2

    print("supplied_key_count=" + str(len(incoming)))
    print("added_key_count=" + str(len(added)))
    print("added_keys=" + ",".join(added))
    print("preserved_existing_key_count=" + str(len(preserved)))
    print("backup_created=" + str(backup is not None).lower())
    print("values_exposed=false")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
