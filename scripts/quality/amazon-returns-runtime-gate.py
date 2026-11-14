#!/usr/bin/env python3
"""Fail-closed runtime gate for Amazon Returns release and service parity."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import urllib.request

SHA = re.compile(r"^[a-f0-9]{40}$")


def fetch_json(url: str) -> dict:
    request = urllib.request.Request(url, headers={
        "Cache-Control": "no-cache, no-store",
        "User-Agent": "ShopVivaliz-AmazonReturnsRuntimeGate/1.0",
    })
    with urllib.request.urlopen(request, timeout=20) as response:
        if response.status != 200:
            raise RuntimeError(f"health_http_{response.status}")
        payload = json.loads(response.read(32768))
    if not isinstance(payload, dict):
        raise RuntimeError("health_payload_not_object")
    return payload


def canonical_main_sha(repository: str) -> str:
    result = subprocess.run(
        ["git", "ls-remote", repository, "refs/heads/main"],
        capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode != 0:
        raise RuntimeError("canonical_repository_unavailable")
    sha = result.stdout.split()[0] if result.stdout.split() else ""
    if SHA.fullmatch(sha) is None:
        raise RuntimeError("canonical_sha_invalid")
    return sha


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--health-url", default="https://shopvivaliz.com.br/api/health/version.php?amazon_returns_guard=1")
    parser.add_argument("--repository", default="https://github.com/Vivaliz-site/amazon-returns-safet.git")
    parser.add_argument("--expected-sha", default="")
    args = parser.parse_args()
    try:
        payload = fetch_json(args.health_url)
        component = payload.get("components", {}).get("amazon_returns", {})
        live_sha = str(component.get("release_sha") or "").lower()
        expected_sha = args.expected_sha.lower() if args.expected_sha else canonical_main_sha(args.repository)
        if not payload.get("ok"):
            raise RuntimeError("site_health_not_ok")
        if component.get("ok") is not True:
            raise RuntimeError("amazon_returns_component_not_ok")
        if component.get("service_state") != "active":
            raise RuntimeError("amazon_returns_service_not_active")
        if SHA.fullmatch(live_sha) is None:
            raise RuntimeError("amazon_returns_release_sha_invalid")
        if SHA.fullmatch(expected_sha) is None or live_sha != expected_sha:
            raise RuntimeError("amazon_returns_release_sha_mismatch")
        print(json.dumps({
            "ok": True,
            "status": "AMAZON_RETURNS_RUNTIME_ALIGNED",
            "release_sha": live_sha,
            "service_state": component["service_state"],
        }, sort_keys=True))
        return 0
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
        print(json.dumps({"ok": False, "status": f"UNVERIFIED:{exc}"}, sort_keys=True))
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
