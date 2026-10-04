#!/usr/bin/env python3
"""Refresh Shopee OAuth tokens using the canonical private token cache."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import tempfile
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ENV_PATH = Path(
    os.environ.get(
        "SHOPVIVALIZ_ENV_PATH",
        "c:/site-shopvivaliz/.env"
        if os.name == "nt"
        else "/home/ubuntu/shopvivaliz-deploy/current/.env",
    )
)
TOKEN_PATH = Path(
    os.environ.get(
        "SHOPEE_TOKEN_FILE",
        "c:/site-shopvivaliz/shopee-tokens.json"
        if os.name == "nt"
        else "/home/ubuntu/shopvivaliz-deploy/shared/shopee-tokens.json",
    )
)
BASE_URL = "https://partner.shopeemobile.com/api/v2"
STATIC_KEYS = ("SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY", "SHOPEE_SHOP_ID")


def _load_env_file() -> dict[str, str]:
    config: dict[str, str] = {}
    if not ENV_PATH.is_file():
        return config
    for raw in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        clean = value.strip().strip('"').strip("'")
        if clean:
            config[key.strip()] = clean
    return config


def _load_token_cache() -> dict[str, Any]:
    if not TOKEN_PATH.is_file():
        return {}
    try:
        payload = json.loads(TOKEN_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return payload if isinstance(payload, dict) else {}


def get_config() -> dict[str, str]:
    config = _load_env_file()

    # EnvironmentFile values are available directly to the service. Prefer them
    # for static credentials when present, while retaining file fallback for
    # manual/diagnostic execution.
    for key in STATIC_KEYS:
        value = (os.environ.get(key) or "").strip()
        if value:
            config[key] = value

    # Rotating OAuth tokens have one canonical source: the private shared cache.
    # Cache values intentionally override any legacy token values still present
    # in .env so a rotated refresh token can never regress to a stale copy.
    cache = _load_token_cache()
    access = str(cache.get("access_token") or "").strip()
    refresh = str(cache.get("refresh_token") or "").strip()
    if access:
        config["SHOPEE_ACCESS_TOKEN"] = access
    if refresh:
        config["SHOPEE_REFRESH_TOKEN"] = refresh
    return config


def sign(partner_id: str, partner_key: str, path: str, timestamp: int) -> str:
    api_path = path if path.startswith("/api/") else f"/api/v2{path}"
    payload = f"{partner_id}{api_path}{timestamp}".encode()
    return hmac.new(partner_key.encode(), payload, hashlib.sha256).hexdigest()


def renew_token(config: dict[str, str]) -> dict[str, Any] | None:
    partner_id = config.get("SHOPEE_PARTNER_ID", "")
    partner_key = config.get("SHOPEE_PARTNER_KEY", "")
    shop_id = config.get("SHOPEE_SHOP_ID", "")
    refresh_token = config.get("SHOPEE_REFRESH_TOKEN", "")
    if not all((partner_id, partner_key, shop_id, refresh_token)):
        print("[!] Credenciais Shopee incompletas")
        return None

    path = "/auth/access_token/get"
    timestamp = int(time.time())
    query = (
        f"partner_id={partner_id}&timestamp={timestamp}"
        f"&sign={sign(partner_id, partner_key, path, timestamp)}"
    )
    body = json.dumps(
        {
            "refresh_token": refresh_token,
            "shop_id": int(shop_id),
            "partner_id": int(partner_id),
        }
    ).encode()

    try:
        request = urllib.request.Request(
            f"{BASE_URL}{path}?{query}",
            data=body,
            headers={"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(request, timeout=30) as response:
            result = json.loads(response.read())
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
        print(f"[!] Renovação Shopee falhou: {type(exc).__name__}")
        return None

    if isinstance(result, dict) and result.get("error"):
        print(f"[!] Renovação Shopee falhou: {result.get('error')}")
        return None
    return result if isinstance(result, dict) else None


def update_token_cache(
    new_token: str,
    new_refresh_token: str,
    expire_in: int = 0,
) -> None:
    target = TOKEN_PATH
    target.parent.mkdir(parents=True, exist_ok=True)

    existing: dict[str, Any] = {}
    original = target.stat() if target.exists() else None
    if original is not None:
        try:
            decoded = json.loads(target.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise RuntimeError("cache Shopee existente inválido; atualização recusada") from exc
        if not isinstance(decoded, dict):
            raise RuntimeError("cache Shopee existente não é objeto JSON")
        existing = dict(decoded)

    now = int(time.time())
    payload = dict(existing)
    payload["access_token"] = new_token
    payload["refresh_token"] = new_refresh_token
    payload["expires_at"] = now + max(0, int(expire_in)) if expire_in else 0
    payload["updated_at"] = now

    mode = (original.st_mode & 0o777) if original is not None else 0o640
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{target.name}.",
        dir=target.parent,
    )
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(payload, handle, ensure_ascii=False, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())

        os.chmod(temporary, mode)
        if os.name != "nt" and original is not None:
            os.chown(temporary, original.st_uid, original.st_gid)

        os.replace(temporary, target)

        updated = target.stat()
        if (updated.st_mode & 0o777) != mode:
            raise RuntimeError("modo do cache Shopee mudou durante renovação")
        if (
            os.name != "nt"
            and original is not None
            and (updated.st_uid != original.st_uid or updated.st_gid != original.st_gid)
        ):
            raise RuntimeError("owner/group do cache Shopee mudou durante renovação")
    finally:
        temporary.unlink(missing_ok=True)


def renew_once() -> bool:
    config = get_config()
    result = renew_token(config)
    response = result.get("response") if isinstance(result, dict) else None
    if not isinstance(response, dict):
        response = result if isinstance(result, dict) else {}

    access_token = response.get("access_token")
    refresh_token = response.get("refresh_token") or config.get("SHOPEE_REFRESH_TOKEN")
    try:
        expire_in = int(response.get("expire_in") or 0)
    except (TypeError, ValueError):
        expire_in = 0

    if (
        not isinstance(access_token, str)
        or not access_token
        or not isinstance(refresh_token, str)
        or not refresh_token
    ):
        return False

    update_token_cache(access_token, refresh_token, expire_in)
    print(f"[+] Token Shopee renovado em {datetime.now(timezone.utc).isoformat()}")
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--interval", type=int, default=10800)
    parser.add_argument("--retry-interval", type=int, default=900)
    args = parser.parse_args()

    while True:
        try:
            ok = renew_once()
        except KeyboardInterrupt:
            return 130
        except Exception as exc:
            print(f"[!] Renovação falhou com segurança: {type(exc).__name__}")
            ok = False
        if args.once:
            return 0 if ok else 1
        time.sleep(max(60, args.interval if ok else args.retry_interval))


if __name__ == "__main__":
    raise SystemExit(main())
