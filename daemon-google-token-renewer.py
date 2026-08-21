#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Renova o access token do Google Ads preservando os metadados do .env."""

import json
import os
import stat
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

from scripts.env_keyset_guard import assert_monotonic_text

ENV_PATH = Path(os.environ.get("SHOPVIVALIZ_ENV_PATH", "c:/site-shopvivaliz/.env" if os.name == "nt" else "/home/ubuntu/shopvivaliz-deploy/current/.env"))
TOKEN_URL = "https://oauth2.googleapis.com/token"


def load_env() -> dict:
    config = {}
    if not ENV_PATH.is_file():
        return config
    with open(ENV_PATH, "r", encoding="utf-8", errors="ignore") as handle:
        for raw in handle.read().splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            config[key.strip()] = value.strip().strip('"').strip("'")
    return config


def resolve_env_write_target(path: Path) -> Path:
    if not path.exists():
        raise FileNotFoundError(path)
    target = path.resolve(strict=True)
    info = target.stat()
    if not stat.S_ISREG(info.st_mode):
        raise RuntimeError(".env alvo do renovador Google nao e arquivo regular")
    if os.name != "nt" and (stat.S_IMODE(info.st_mode) & 0o022):
        raise RuntimeError(".env alvo do renovador Google possui permissao de escrita para grupo/outros")
    return target


def write_env(new_values: dict) -> bool:
    if not ENV_PATH.is_file():
        print(f"[!] Erro: .env nao encontrado em {ENV_PATH}")
        return False
    try:
        target = resolve_env_write_target(ENV_PATH)
    except Exception as exc:
        print(f"[!] Erro: alvo .env inseguro para renovacao Google: {type(exc).__name__}")
        return False

    with open(target, "r", encoding="utf-8", errors="ignore") as handle:
        original_text = handle.read()
    lines = original_text.splitlines(keepends=True)
    updated_lines, pending = [], dict(new_values)
    for line in lines:
        stripped = line.strip()
        if stripped and not stripped.startswith("#") and "=" in stripped:
            key = stripped.split("=", 1)[0].strip()
            if key in pending:
                updated_lines.append(f"{key}={pending.pop(key)}\n")
                continue
        updated_lines.append(line)
    for key, value in pending.items():
        updated_lines.append(f"{key}={value}\n")

    candidate_text = "".join(updated_lines)
    if candidate_text and not candidate_text.endswith("\n"):
        candidate_text += "\n"
    assert_monotonic_text(original_text, candidate_text)

    original = target.stat()
    mode = original.st_mode & 0o777
    fd, temp_name = tempfile.mkstemp(prefix=".env.google.", dir=str(target.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(candidate_text)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, mode)
        if os.name != "nt":
            os.chown(temp_name, original.st_uid, original.st_gid)
        os.replace(temp_name, target)
        updated = target.stat()
        if (updated.st_mode & 0o777) != mode or (os.name != "nt" and (updated.st_uid != original.st_uid or updated.st_gid != original.st_gid)):
            raise RuntimeError("metadados do .env mudaram durante renovacao Google")
        return True
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def renew_google_token(config: dict) -> str | None:
    client_id = config.get("GOOGLE_OAUTH_CLIENT_ID")
    client_secret = config.get("GOOGLE_OAUTH_CLIENT_SECRET")
    refresh_token = config.get("GOOGLE_ADS_REFRESH_TOKEN")
    if not all([client_id, client_secret, refresh_token]):
        print("[!] Erro: Credenciais do Google Ads incompletas no .env")
        return None
    payload = urllib.parse.urlencode({"grant_type": "refresh_token", "client_id": client_id, "client_secret": client_secret, "refresh_token": refresh_token}).encode("utf-8")
    try:
        with urllib.request.urlopen(urllib.request.Request(TOKEN_URL, data=payload, headers={"Content-Type": "application/x-www-form-urlencoded"}), timeout=15) as response:
            data = json.loads(response.read().decode("utf-8"))
            access_token = data.get("access_token")
            new_refresh = data.get("refresh_token")
            if not access_token:
                return None
            updates = {"GOOGLE_ADS_ACCESS_TOKEN": access_token}
            if new_refresh:
                updates["GOOGLE_ADS_REFRESH_TOKEN"] = new_refresh
            if not write_env(updates):
                return None
            print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] Token do Google Ads renovado com sucesso.")
            return access_token
    except urllib.error.HTTPError as exc:
        print(f"[!] Erro HTTP ao renovar token do Google Ads: status={exc.code}")
        return None
    except Exception as exc:
        print(f"[!] Erro ao renovar token do Google Ads: {type(exc).__name__}")
        return None


def main() -> int:
    print("Iniciando Google Ads Token Renewer Daemon...")
    once = "--once" in sys.argv
    while True:
        token = renew_google_token(load_env())
        if once:
            return 0 if token else 1
        time.sleep(3000)


if __name__ == "__main__":
    raise SystemExit(main())
