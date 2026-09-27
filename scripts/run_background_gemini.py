#!/usr/bin/env python3
"""Run Gemini CLI safely for detached background task continuation.

This wrapper deliberately reads only the Gemini credential from the protected
runtime env file. It does not source the full .env into the daemon process and
removes unrelated AI provider secrets from the Gemini child environment.
"""
from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Mapping

DEFAULT_ENV_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/.env")
DEFAULT_GEMINI_BIN = Path("/home/ubuntu/.local/bin/gemini")
SUPPORTED_KEYS = ("GEMINI_API_KEY", "GOOGLE_API_KEY", "GOOGLE_GEMINI_API_KEY")
SECRET_KEYS_TO_REMOVE = (
    "OPENAI_API_KEY",
    "CODEX_API_KEY",
    "ANTHROPIC_API_KEY",
    "GOOGLE_API_KEY",
    "GOOGLE_GEMINI_API_KEY",
)


def _unquote(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
        return value[1:-1]
    return value


def select_gemini_credential(text: str) -> tuple[str, str]:
    values: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key, value = line.split("=", 1)
        key = key.strip()
        if key in SUPPORTED_KEYS:
            values[key] = _unquote(value)
    for key in SUPPORTED_KEYS:
        value = values.get(key, "").strip()
        if value:
            return key, value
    return "", ""


def build_child_env(base: Mapping[str, str], credential: str) -> dict[str, str]:
    child = dict(base)
    for key in SECRET_KEYS_TO_REMOVE:
        child.pop(key, None)
    child["GEMINI_API_KEY"] = credential
    child["GEMINI_CLI_TRUST_WORKSPACE"] = "true"
    return child


def _resolve_binary(configured: str) -> str:
    candidate = Path(configured).expanduser()
    if candidate.is_file() and os.access(candidate, os.X_OK):
        return str(candidate)
    fallback = shutil.which("gemini")
    return fallback or ""


def run(
    *,
    model: str,
    prompt_file: Path,
    env_file: Path,
    gemini_bin: str,
) -> int:
    if not prompt_file.is_file():
        print("background_gemini_error=prompt_file_missing", file=sys.stderr)
        return 66
    if not env_file.is_file():
        print("background_gemini_error=runtime_env_missing", file=sys.stderr)
        return 78

    try:
        env_text = env_file.read_text(encoding="utf-8", errors="strict")
    except OSError:
        print("background_gemini_error=runtime_env_unreadable", file=sys.stderr)
        return 78

    source, credential = select_gemini_credential(env_text)
    if not credential:
        print("background_gemini_error=credential_missing", file=sys.stderr)
        return 78

    executable = _resolve_binary(gemini_bin)
    if not executable:
        print("background_gemini_error=cli_missing", file=sys.stderr)
        return 127

    prompt = prompt_file.read_text(encoding="utf-8", errors="strict")
    child = build_child_env(os.environ, credential)
    command = [
        executable,
        "--model",
        model,
        "--approval-mode",
        "auto_edit",
        "--prompt",
        prompt,
    ]

    try:
        completed = subprocess.run(
            command,
            env=child,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            check=False,
        )
    except OSError:
        print("background_gemini_error=exec_failed", file=sys.stderr)
        return 127

    output = (completed.stdout or "").replace(credential, "***")
    if output:
        sys.stdout.write(output)
        if not output.endswith("\n"):
            sys.stdout.write("\n")
    print(f"background_gemini_credential_source={source}")
    print(f"background_gemini_exit_code={completed.returncode}")
    return int(completed.returncode)


def main() -> int:
    parser = argparse.ArgumentParser(description="Run protected Gemini CLI background continuation.")
    parser.add_argument("--model", required=True)
    parser.add_argument("--prompt-file", required=True)
    parser.add_argument(
        "--env-file",
        default=os.getenv("GEMINI_ENV_FILE", str(DEFAULT_ENV_FILE)),
    )
    parser.add_argument(
        "--gemini-bin",
        default=os.getenv("SHOPVIVALIZ_GEMINI_BIN", str(DEFAULT_GEMINI_BIN)),
    )
    args = parser.parse_args()
    return run(
        model=args.model,
        prompt_file=Path(args.prompt_file).expanduser(),
        env_file=Path(args.env_file).expanduser(),
        gemini_bin=args.gemini_bin,
    )


if __name__ == "__main__":
    raise SystemExit(main())
