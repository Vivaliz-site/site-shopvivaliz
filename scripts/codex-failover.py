#!/usr/bin/env python3
"""Compatibility shim for the retired API-key Codex failover.

All Codex execution must use the native ChatGPT Business profile failover
(fredmourao / marinaofaleiro). This shim intentionally never reads or exports
OpenAI API credentials.
"""
from __future__ import annotations
import os
import pathlib
import subprocess
import sys


def native_engine() -> pathlib.Path:
    path = pathlib.Path(__file__).with_name('codex-native-profile-failover.py')
    if not path.is_file():
        raise FileNotFoundError(f'native Codex profile failover not found: {path}')
    return path


def sanitized_env() -> dict[str, str]:
    env = os.environ.copy()
    env.pop('OPENAI_API_KEY', None)
    env.pop('CODEX_API_KEY', None)
    return env


def main(argv: list[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    return subprocess.call([sys.executable, str(native_engine()), *args], env=sanitized_env())


if __name__ == '__main__':
    raise SystemExit(main())
