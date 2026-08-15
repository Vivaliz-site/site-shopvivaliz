#!/usr/bin/env python3
"""Execute a command with SHOPEE_* values loaded from a private dotenv file.

Only Shopee variables are imported. Values are never printed. This is intended for
production jobs that run on the VM where shared/.env is the canonical credential
source, avoiding copies of rotating OAuth tokens into GitHub Actions environments.
"""
from __future__ import annotations

import argparse
import os
import shlex
import subprocess
import sys
from pathlib import Path


def parse_value(raw: str) -> str:
    text = raw.strip()
    if not text:
        return ""
    if text[0:1] in {"'", '"'}:
        try:
            parts = shlex.split(text, posix=True)
            return parts[0] if parts else ""
        except ValueError:
            return text.strip("'\"")
    return text.split(" #", 1)[0].strip()


def load_shopee_env(path: Path) -> dict[str, str]:
    if not path.is_file() or not os.access(path, os.R_OK):
        raise RuntimeError(f"runtime env file unavailable: {path}")
    result: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key, raw_value = line.split("=", 1)
        key = key.strip()
        if not key.startswith("SHOPEE_"):
            continue
        value = parse_value(raw_value)
        if value:
            result[key] = value
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description="Run a command with Shopee runtime credentials")
    parser.add_argument("--env-file", required=True)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = list(args.command)
    if command and command[0] == "--":
        command = command[1:]
    if not command:
        print("ERROR: command is required", file=sys.stderr)
        return 2

    try:
        loaded = load_shopee_env(Path(args.env_file))
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 3

    required = {"SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY", "SHOPEE_SHOP_ID"}
    if not required.issubset(loaded):
        print("ERROR: required Shopee runtime credentials are incomplete", file=sys.stderr)
        return 4
    if not loaded.get("SHOPEE_ACCESS_TOKEN") and not loaded.get("SHOPEE_REFRESH_TOKEN"):
        print("ERROR: Shopee access/refresh token missing in runtime env", file=sys.stderr)
        return 4

    env = os.environ.copy()
    env.update(loaded)
    completed = subprocess.run(command, env=env, check=False)
    return int(completed.returncode)


if __name__ == "__main__":
    raise SystemExit(main())
