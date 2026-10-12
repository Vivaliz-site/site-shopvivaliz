#!/usr/bin/env python3
"""Classify local AI bridge readiness without waking disabled paid models.

A bridge intentionally disabled by the no-paid-background policy is a
healthy service but an unavailable inference provider. The disabled state
is acceptable for deployment only when POST /v1/respond is denied (403).
"""
from __future__ import annotations

import json
import sys
from urllib.error import HTTPError, URLError
from urllib.request import ProxyHandler, Request, build_opener

PORTS = {"codex": 17656, "claude": 17657}
EXPECTED_ENDPOINTS = {
    "codex": "ai-squad-codex-bridge",
    "claude": "ai-squad-claude-bridge",
}


def classify_health(provider: str, body: object) -> str:
    if provider not in PORTS or not isinstance(body, dict):
        return "invalid"
    if body.get("endpoint") != EXPECTED_ENDPOINTS[provider]:
        return "invalid"
    if (body.get("ok") is False
        and body.get("policy") == "manual_approval_required"
        and body.get("auth_mode") == "disabled"):
        return "disabled"
    if body.get("ok") is not True:
        return "invalid"
    if provider == "codex" and body.get("web_search_mode") == "live":
        return "ready"
    if provider == "claude" and body.get("authenticated") is True:
        return "ready"
    return "invalid"


def check_bridge(provider: str, opener=None, timeout: int = 4) -> str:
    if provider not in PORTS:
        return "invalid"
    opener = opener or build_opener(ProxyHandler({}))
    url = f"http://127.0.0.1:{PORTS[provider]}"
    try:
        request = Request(url + "/health", method="GET")
        with opener.open(request, timeout=timeout) as response:
            data = response.read(65537)
            if len(data) > 65536:
                return "invalid"
            payload = json.loads(data)
        health = classify_health(provider, payload)
        if health != "disabled":
            return health
        # An intentionally disabled bridge cannot be accepted without a
        # real negative POST test: a misconfigured bridge might still serve.
        request = Request(
            url + "/v1/respond",
            data=b"{}",
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        try:
            with opener.open(request, timeout=timeout):
                return "invalid"
        except HTTPError as error:
            return "disabled" if error.code == 403 else "invalid"
    except (HTTPError, URLError, OSError, ValueError, TypeError, json.JSONDecodeError):
        return "invalid"


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] not in PORTS:
        print("AI_BRIDGE_POLICY_HEALTH=INVALID_ARGUMENT", file=sys.stderr)
        return 64
    provider = sys.argv[1]
    result = check_bridge(provider)
    if result not in {"ready", "disabled"}:
        print(f"AI_SQUAD_{provider.upper()}_BRIDGE_HEALTH=INVALID", file=sys.stderr)
        return 1
    print(f"AI_SQUAD_{provider.upper()}_BRIDGE_HEALTH={result.upper()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
