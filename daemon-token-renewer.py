#!/usr/bin/env python3
"""Refresh Olist OAuth tokens proactively without exposing secrets."""

from __future__ import annotations

import argparse
import base64
import binascii
import json
import os
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from scripts.env_keyset_guard import assert_monotonic_text


DEFAULT_ENV_PATH = Path(
    "c:/site-shopvivaliz/.env"
    if os.name == "nt"
    else "/home/ubuntu/shopvivaliz-deploy/current/.env"
)
DEFAULT_TOKEN_STORE_PATH = Path(
    "c:/site-shopvivaliz/storage/private/olist-tokens.json"
    if os.name == "nt"
    else "/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json"
)
ENV_PATH = Path(os.environ.get("SHOPVIVALIZ_ENV_PATH", str(DEFAULT_ENV_PATH)))
TOKEN_STORE_PATH = Path(
    os.environ.get("SHOPVIVALIZ_OLIST_TOKEN_FILE", str(DEFAULT_TOKEN_STORE_PATH))
)
TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
DEFAULT_CHECK_INTERVAL = 300
DEFAULT_REFRESH_MARGIN = 1800
SAFE_OAUTH_ERROR_CODES = frozenset(
    {
        "invalid_request",
        "invalid_client",
        "invalid_grant",
        "unauthorized_client",
        "unsupported_grant_type",
        "invalid_scope",
        "temporarily_unavailable",
        "server_error",
        "access_denied",
    }
)


def current_token_store_path() -> Path:
    """Return the shared production store, while isolating tests/local runs."""
    if TOKEN_STORE_PATH != DEFAULT_TOKEN_STORE_PATH or "SHOPVIVALIZ_OLIST_TOKEN_FILE" in os.environ:
        return TOKEN_STORE_PATH
    if ENV_PATH != DEFAULT_ENV_PATH:
        return ENV_PATH.parent / "storage/private/olist-tokens.json"
    return TOKEN_STORE_PATH


def _read_env() -> dict[str, str]:
    config: dict[str, str] = {}
    if not ENV_PATH.is_file():
        return config
    for raw in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        config[key.strip()] = value.strip().strip('"').strip("'")
    return config


def read_token_store() -> dict[str, Any]:
    path = current_token_store_path()
    if not path.is_file():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        return {}
    return payload if isinstance(payload, dict) else {}


def get_config() -> dict[str, str]:
    config = _read_env()
    store = read_token_store()
    for key in (
        "OLIST_ACCESS_TOKEN",
        "OLIST_REFRESH_TOKEN",
        "TINY_ACCESS_TOKEN",
        "TINY_REFRESH_TOKEN",
    ):
        value = store.get(key)
        if isinstance(value, str) and value.strip():
            config[key] = value.strip()
    return config


def safe_oauth_error_code(exc: urllib.error.HTTPError) -> str:
    """Return only a whitelisted OAuth error class from an HTTP error body."""
    try:
        raw = exc.read(4096)
        data = json.loads(raw.decode("utf-8", errors="replace"))
    except (OSError, UnicodeError, json.JSONDecodeError, AttributeError, TypeError):
        return ""
    candidate = data.get("error") if isinstance(data, dict) else None
    if isinstance(candidate, str) and candidate in SAFE_OAUTH_ERROR_CODES:
        return candidate
    return ""


def oauth_client_candidates(config: dict[str, str]) -> list[tuple[str, str, str]]:
    candidates: list[tuple[str, str, str]] = []
    seen: set[tuple[str, str]] = set()
    for alias, id_key, secret_key in (
        ("olist", "OLIST_CLIENT_ID", "OLIST_CLIENT_SECRET"),
        ("tiny", "TINY_CLIENT_ID", "TINY_CLIENT_SECRET"),
        ("legacy", "CLIENT_ID_API_OLIST", "CLIENT_SECRET_OLIST"),
    ):
        client_id = config.get(id_key, "").strip()
        client_secret = config.get(secret_key, "").strip()
        pair = (client_id, client_secret)
        if not all(pair) or pair in seen:
            continue
        seen.add(pair)
        candidates.append((alias, client_id, client_secret))
    return candidates


def oauth_refresh_candidates(config: dict[str, str]) -> list[tuple[str, str]]:
    candidates: list[tuple[str, str]] = []
    seen: set[str] = set()
    sources: tuple[dict[str, Any], ...] = (read_token_store(), config, _read_env())
    for source in sources:
        for alias, key in (
            ("olist", "OLIST_REFRESH_TOKEN"),
            ("tiny", "TINY_REFRESH_TOKEN"),
        ):
            value = source.get(key)
            token = value.strip() if isinstance(value, str) else ""
            if not token or token in seen:
                continue
            seen.add(token)
            candidates.append((alias, token))
    return candidates


def print_oauth_failure(
    status: int,
    error_code: str,
    client_alias: str,
    refresh_alias: str,
) -> None:
    suffix = f" oauth_error={error_code}" if error_code else ""
    print(
        f"[!] Renovação Olist recusada: HTTP {status}{suffix} "
        f"credential_alias={client_alias} refresh_alias={refresh_alias}"
    )


def renew_token(config: dict[str, str]) -> dict[str, Any] | None:
    clients = oauth_client_candidates(config)
    refreshes = oauth_refresh_candidates(config)
    if not clients or not refreshes:
        print("[!] Credenciais Olist incompletas")
        return None

    last_failure: tuple[int, str, str, str] | None = None
    for client_alias, client_id, client_secret in clients:
        client_rejected = False
        for refresh_alias, refresh_token in refreshes:
            payload = urllib.parse.urlencode(
                {
                    "grant_type": "refresh_token",
                    "client_id": client_id,
                    "client_secret": client_secret,
                    "refresh_token": refresh_token,
                }
            ).encode("utf-8")
            request = urllib.request.Request(
                TOKEN_URL,
                data=payload,
                headers={"Content-Type": "application/x-www-form-urlencoded"},
            )
            try:
                with urllib.request.urlopen(request, timeout=30) as response:
                    result = json.loads(response.read())
            except urllib.error.HTTPError as exc:
                error_code = safe_oauth_error_code(exc)
                status = int(getattr(exc, "code", 0) or 0)
                last_failure = (status, error_code, client_alias, refresh_alias)
                if error_code == "invalid_client":
                    client_rejected = True
                    break
                if error_code == "invalid_grant":
                    continue
                print_oauth_failure(status, error_code, client_alias, refresh_alias)
                return None
            except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
                print(f"[!] Renovação Olist falhou: {type(exc).__name__}")
                return None

            if not isinstance(result, dict):
                return None
            result["_sv_refresh_token_fallback"] = refresh_token
            result["_sv_credential_alias"] = client_alias
            result["_sv_refresh_alias"] = refresh_alias
            return result

        if client_rejected:
            continue

    if last_failure is not None:
        print_oauth_failure(*last_failure)
    return None


def ensure_token_store_parent() -> None:
    path = current_token_store_path()
    if not ENV_PATH.exists():
        return
    env_target = ENV_PATH.resolve(strict=True)
    env_stat = env_target.stat()
    path.parent.mkdir(parents=True, exist_ok=True)
    if os.name != "nt":
        os.chmod(path.parent, 0o770)
        os.chown(path.parent, env_stat.st_uid, env_stat.st_gid)


def _atomic_write(
    path: Path,
    text: str,
    mode: int,
    uid: int | None,
    gid: int | None,
) -> None:
    if not path.parent.is_dir():
        raise RuntimeError("diretorio de destino ausente")
    descriptor, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(text)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        if os.name != "nt" and uid is not None and gid is not None:
            os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def update_env(new_token: str, new_refresh_token: str) -> None:
    target = ENV_PATH.resolve(strict=True)
    content = target.read_text(encoding="utf-8")
    replacements = {
        "OLIST_ACCESS_TOKEN": new_token,
        "OLIST_REFRESH_TOKEN": new_refresh_token,
        "TINY_ACCESS_TOKEN": new_token,
        "TINY_REFRESH_TOKEN": new_refresh_token,
        "TOKEN_API_OLIST": new_token,
    }
    found: set[str] = set()
    lines: list[str] = []
    for line in content.splitlines():
        key = line.split("=", 1)[0].strip() if "=" in line else ""
        if key in replacements:
            lines.append(f"{key}={replacements[key]}")
            found.add(key)
        else:
            lines.append(line)
    for key, value in replacements.items():
        if key not in found:
            lines.append(f"{key}={value}")

    candidate_text = "\n".join(lines).rstrip("\n") + "\n"
    assert_monotonic_text(content, candidate_text)

    original = target.stat()
    _atomic_write(
        target,
        candidate_text,
        original.st_mode & 0o777,
        original.st_uid if os.name != "nt" else None,
        original.st_gid if os.name != "nt" else None,
    )
    updated = target.stat()
    if (updated.st_mode & 0o777) != (original.st_mode & 0o777):
        raise RuntimeError("permissao do .env mudou durante renovacao Olist")
    if os.name != "nt" and (updated.st_uid != original.st_uid or updated.st_gid != original.st_gid):
        raise RuntimeError("owner/group do .env mudou durante renovacao Olist")


def update_token_store(new_token: str, new_refresh_token: str, result: dict[str, Any]) -> None:
    ensure_token_store_parent()
    store = read_token_store()
    expires_in_raw = result.get("expires_in")
    try:
        expires_in = max(0, int(expires_in_raw))
    except (TypeError, ValueError):
        expires_in = 0
    now = int(time.time())
    store.update(
        {
            "OLIST_ACCESS_TOKEN": new_token,
            "TINY_ACCESS_TOKEN": new_token,
            "OLIST_REFRESH_TOKEN": new_refresh_token,
            "TINY_REFRESH_TOKEN": new_refresh_token,
            "updated_at": datetime.now(timezone.utc).isoformat(),
        }
    )
    if expires_in > 0:
        expires_at_epoch = now + expires_in
        store["expires_in"] = expires_in
        store["expires_at_epoch"] = expires_at_epoch
        store["expires_at"] = datetime.fromtimestamp(expires_at_epoch, timezone.utc).isoformat()

    env_target = ENV_PATH.resolve(strict=True)
    env_stat = env_target.stat()
    payload = json.dumps(store, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    _atomic_write(
        current_token_store_path(),
        payload,
        0o660,
        env_stat.st_uid if os.name != "nt" else None,
        env_stat.st_gid if os.name != "nt" else None,
    )


def _decode_jwt_exp(token: str) -> int | None:
    parts = token.split(".")
    if len(parts) < 2:
        return None
    segment = parts[1]
    padding = "=" * ((4 - len(segment) % 4) % 4)
    try:
        payload = json.loads(base64.urlsafe_b64decode(segment + padding).decode("utf-8"))
        value = int(payload.get("exp")) if isinstance(payload, dict) else 0
    except (ValueError, TypeError, UnicodeError, json.JSONDecodeError, binascii.Error):
        return None
    return value if value > 0 else None


def token_expiry_epoch(config: dict[str, str]) -> int | None:
    token = config.get("OLIST_ACCESS_TOKEN") or config.get("TINY_ACCESS_TOKEN") or ""
    store = read_token_store()
    stored_token = store.get("OLIST_ACCESS_TOKEN") or store.get("TINY_ACCESS_TOKEN")
    if token and isinstance(stored_token, str) and stored_token == token:
        raw = store.get("expires_at_epoch")
        try:
            value = int(raw)
        except (TypeError, ValueError):
            value = 0
        if value > 0:
            return value
    return _decode_jwt_exp(token) if token else None


def token_requires_refresh(config: dict[str, str], refresh_margin: int, now: int | None = None) -> bool:
    token = config.get("OLIST_ACCESS_TOKEN") or config.get("TINY_ACCESS_TOKEN") or ""
    if not token:
        return True
    expiry = token_expiry_epoch(config)
    if expiry is None:
        return True
    current = int(time.time()) if now is None else int(now)
    return expiry - current <= max(60, int(refresh_margin))


def renew_once() -> dict[str, Any] | None:
    config = get_config()
    result = renew_token(config)
    access_token = result.get("access_token") if isinstance(result, dict) else None
    if not isinstance(access_token, str) or not access_token:
        return None
    refresh_token = result.get("refresh_token") or result.get("_sv_refresh_token_fallback")
    if not isinstance(refresh_token, str) or not refresh_token:
        return None
    credential_alias = str(result.get("_sv_credential_alias") or "")
    refresh_alias = str(result.get("_sv_refresh_alias") or "")

    update_token_store(access_token, refresh_token, result)
    update_env(access_token, refresh_token)

    if credential_alias and refresh_alias:
        print(
            f"[+] Credencial OAuth aceita: credential_alias={credential_alias} "
            f"refresh_alias={refresh_alias}"
        )
    print(f"[+] Token Olist renovado preventivamente em {datetime.now(timezone.utc).isoformat()}")
    return result


def check_and_renew(refresh_margin: int) -> tuple[bool, bool]:
    ensure_token_store_parent()
    config = get_config()
    if not token_requires_refresh(config, refresh_margin):
        expiry = token_expiry_epoch(config)
        remaining = max(0, (expiry or 0) - int(time.time())) if expiry else 0
        print(f"[+] Token Olist ainda valido; refresh preventivo em janela futura remaining_seconds={remaining}")
        return True, False
    return (renew_once() is not None), True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Forca uma renovacao e encerra")
    parser.add_argument("--interval", type=int, default=DEFAULT_CHECK_INTERVAL, help="Intervalo entre checagens")
    parser.add_argument("--retry-interval", type=int, default=300, help="Intervalo apos falha")
    parser.add_argument(
        "--refresh-margin",
        type=int,
        default=DEFAULT_REFRESH_MARGIN,
        help="Segundos de antecedencia para renovar antes do exp",
    )
    args = parser.parse_args()

    if args.once:
        try:
            return 0 if renew_once() is not None else 1
        except Exception as exc:
            print(f"[!] Renovação falhou com segurança: {type(exc).__name__}")
            return 1

    while True:
        try:
            ok, attempted = check_and_renew(args.refresh_margin)
        except KeyboardInterrupt:
            return 130
        except Exception as exc:
            print(f"[!] Renovação falhou com segurança: {type(exc).__name__}")
            ok, attempted = False, True
        delay = args.interval if ok else args.retry_interval
        if attempted and not ok:
            delay = min(delay, args.retry_interval)
        time.sleep(max(60, delay))


if __name__ == "__main__":
    raise SystemExit(main())
