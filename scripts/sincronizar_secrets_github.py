#!/usr/bin/env python3
"""Materialize runtime environment variables into ``.env.local`` safely.

GitHub Secrets are intentionally write-only and cannot be downloaded with
``gh secret list``. This compatibility entrypoint therefore never pretends to
retrieve secret values from GitHub. It only copies non-empty values already
present in the process environment and preserves every existing value for
which no replacement was explicitly supplied.
"""

from __future__ import annotations

import argparse
import os
import re
import stat
import sys
import tempfile
from pathlib import Path

SUPPORTED_KEYS = (
    "DB_HOST",
    "DB_PORT",
    "DB_NAME",
    "DB_USER",
    "DB_PASS",
    "DB_USERNAME",
    "DB_PASSWORD",
    "FTP_SERVER",
    "FTP_USERNAME",
    "FTP_PASSWORD",
    "FTP_PORT",
    "FTP_REMOTE_DIR",
    "MAIL_HOST",
    "MAIL_PORT",
    "MAIL_USER",
    "MAIL_PASS",
    "SMTP_HOST",
    "SMTP_PORT",
    "SMTP_USER",
    "SMTP_PASS",
    "EMAIL_SMTP_HOST",
    "EMAIL_SMTP_PORT",
    "EMAIL_USER",
    "EMAIL_PASSWORD",
    "EMAIL_FROM",
    "EMAIL_TO",
    "ANTHROPIC_API_KEY",
    "OPENAI_API_KEY",
    "GEMINI_API_KEY",
    "SHOPEE_PARTNER_ID",
    "SHOPEE_PARTNER_KEY",
    "SHOPEE_SHOP_ID",
    "SHOPEE_ACCESS_TOKEN",
    "SHOPEE_REFRESH_TOKEN",
    "OLIST_ACCESS_TOKEN",
    "TIKTOK_ACCESS_TOKEN",
    "MELHORENVIO_ACCESS_TOKEN",
    "APP_ENV",
    "APP_DEBUG",
    "APP_URL",
)

ASSIGNMENT_RE = re.compile(r"^(?P<prefix>\s*)(?P<key>[A-Za-z_][A-Za-z0-9_]*)\s*=")
FORBIDDEN_PLACEHOLDERS = {"(do GitHub)", "(from GitHub)", "***"}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Preserva o .env.local atual e aplica somente valores nao vazios "
            "ja materializados no ambiente do processo."
        )
    )
    parser.add_argument(
        "--env-file",
        default=".env.local",
        help="Arquivo de destino (padrao: .env.local)",
    )
    return parser.parse_args()


def runtime_values() -> dict[str, str]:
    values: dict[str, str] = {}
    for key in SUPPORTED_KEYS:
        value = os.environ.get(key)
        if value is None or value == "":
            continue
        if "\n" in value or "\r" in value:
            raise ValueError(f"{key} contem quebra de linha e nao pode ser gravado em .env")
        if value in FORBIDDEN_PLACEHOLDERS:
            raise ValueError(f"{key} contem um placeholder, nao um valor materializado")
        values[key] = value
    return values


def read_existing(path: Path) -> list[str]:
    if path.is_symlink():
        raise RuntimeError(f"recusado arquivo simbolico: {path}")
    if path.exists() and not path.is_file():
        raise RuntimeError(f"destino nao e um arquivo regular: {path}")
    if not path.exists():
        return []
    return path.read_text(encoding="utf-8").splitlines(keepends=True)


def render(existing: list[str], incoming: dict[str, str]) -> tuple[str, int]:
    rendered: list[str] = []
    seen: set[str] = set()
    changed = 0

    for line in existing:
        match = ASSIGNMENT_RE.match(line)
        key = match.group("key") if match else ""
        if key and key in incoming:
            newline = "\n" if line.endswith("\n") else ""
            replacement = f"{key}={incoming[key]}{newline}"
            rendered.append(replacement)
            seen.add(key)
            if replacement != line:
                changed += 1
        else:
            rendered.append(line)

    missing = [key for key in SUPPORTED_KEYS if key in incoming and key not in seen]
    if missing:
        if rendered and not rendered[-1].endswith("\n"):
            rendered[-1] += "\n"
        if rendered and rendered[-1].strip() != "":
            rendered.append("\n")
        rendered.append("# Valores materializados pelo ambiente de execucao\n")
        rendered.extend(f"{key}={incoming[key]}\n" for key in missing)
        changed += len(missing)

    return "".join(rendered), changed


def atomic_write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=str(path.parent))
    temp_path = Path(temp_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_path, stat.S_IRUSR | stat.S_IWUSR)
        os.replace(temp_path, path)
    finally:
        if temp_path.exists():
            temp_path.unlink()


def materialize(path: Path) -> int:
    existing = read_existing(path)
    incoming = runtime_values()

    if not existing and not incoming:
        print(
            "ERRO: .env.local inexistente e nenhum valor foi materializado no ambiente.",
            file=sys.stderr,
        )
        print(
            "GitHub Secrets nao podem ser recuperados por gh secret list; injete-os pelo ambiente autorizado.",
            file=sys.stderr,
        )
        return 2

    rendered, changed = render(existing, incoming)
    current = "".join(existing)

    if rendered != current:
        atomic_write(path, rendered)
    elif path.exists():
        os.chmod(path, stat.S_IRUSR | stat.S_IWUSR)

    if changed:
        print(f"OK: {changed} valor(es) materializado(s); valores existentes nao fornecidos foram preservados.")
    else:
        print("OK: nenhuma alteracao necessaria; valores existentes foram preservados.")
    return 0


def main() -> int:
    args = parse_args()
    try:
        return materialize(Path(args.env_file))
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"ERRO: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
