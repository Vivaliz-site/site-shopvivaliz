from __future__ import annotations

import json
import os

from .config import Settings


class TokenResolutionError(RuntimeError):
    """Raised when no safe OAuth access token can be resolved."""


def _token_from_store(settings: Settings) -> str:
    path = settings.token_file
    if not path.exists():
        return ""
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise TokenResolutionError("Token store exists but could not be read safely") from exc
    if not isinstance(data, dict):
        raise TokenResolutionError("Token store has an invalid structure")
    for key in ("OLIST_ACCESS_TOKEN", "TINY_ACCESS_TOKEN"):
        value = str(data.get(key, "")).strip()
        if value:
            return value
    return ""


def resolve_access_token(settings: Settings) -> str:
    token = _token_from_store(settings)
    if token:
        return token
    for key in ("OLIST_ACCESS_TOKEN", "TINY_ACCESS_TOKEN"):
        value = os.getenv(key, "").strip()
        if value:
            return value
    raise TokenResolutionError("No OAuth access token is available in the canonical runtime sources")
