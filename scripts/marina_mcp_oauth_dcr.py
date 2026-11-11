#!/usr/bin/env python3
"""Verify and optionally repair Cloudflare Access DCR for the Marina MCP.

Default is read-only, credential-free OAuth discovery. A write requires
--apply, the exact existing Cloudflare Access app ID, the exact ChatGPT
callback URI, an exclusive private backup path, and a provider-authorized
CLOUDFLARE_API_TOKEN environment variable. No secrets are printed.
If absent in this process, run scripts/agent_cloudflare_preflight.py first:
GitHub Actions may already contain the secret in an authorized workflow context.
"""
import argparse
import copy
import json
import os
from pathlib import Path
import re
import sys
import time
from urllib.parse import urlsplit
import requests

DOMAIN = "mcp-marina.shopvivaliz.com.br"
RESOURCE = "https://" + DOMAIN + "/mcp"
ACCOUNT = "0afb7d99f708b9455598df9fa1035164"
API = "https://api.cloudflare.com/client/v4"


class DcrError(Exception):
    pass


def api_json(url, token="", method="GET", payload=None):
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    if payload is not None:
        headers["Content-Type"] = "application/json"
    try:
        response = requests.request(method, url, headers=headers, json=payload,
                                    allow_redirects=False, timeout=10)
    except requests.RequestException:
        raise DcrError("provider_transport_error") from None
    if response.status_code != 200:
        raise DcrError("provider_http_" + str(response.status_code))
    try:
        result = response.json()
    except ValueError:
        raise DcrError("provider_malformed_json") from None
    if not isinstance(result, dict):
        raise DcrError("provider_malformed_json")
    return result


def probe():
    meta = api_json("https://" + DOMAIN + "/.well-known/oauth-authorization-server")
    resource = api_json("https://" + DOMAIN + "/.well-known/oauth-protected-resource/mcp")
    issuer = meta.get("issuer")
    if not issuer or issuer not in resource.get("authorization_servers", []):
        raise DcrError("issuer_mismatch")
    if resource.get("resource") != RESOURCE:
        raise DcrError("resource_mismatch")
    if "S256" not in meta.get("code_challenge_methods_supported", []):
        raise DcrError("pkce_s256_missing")
    return bool(meta.get("registration_endpoint"))


STABLE_REDIRECT_URIS = (
    "https://chatgpt.com/connector_platform_oauth_redirect",
    "https://claude.ai/api/mcp/auth_callback",
    "https://claude.com/api/mcp/auth_callback",
)


def validate_redirect(uri):
    u = urlsplit(uri)
    if u.scheme != "https" or u.username or u.password or u.query or u.fragment:
        raise DcrError("invalid_chatgpt_redirect")
    try:
        if u.port is not None:
            raise DcrError("invalid_chatgpt_redirect")
    except ValueError:
        raise DcrError("invalid_chatgpt_redirect") from None
    if uri in STABLE_REDIRECT_URIS:
        return uri
    if u.hostname != "chatgpt.com":
        raise DcrError("invalid_chatgpt_redirect")
    if not re.fullmatch(r"/connector/oauth/[A-Za-z0-9_-]{8,128}", u.path):
        raise DcrError("invalid_chatgpt_redirect_path")
    return uri


def validate_redirects(uris):
    if isinstance(uris, str):
        uris = [uris]
    if not isinstance(uris, (list, tuple)) or not uris:
        raise DcrError("invalid_chatgpt_redirect")
    return list(dict.fromkeys(validate_redirect(u) for u in uris))


def plan(app, app_id, redirect):
    if app.get("id") != app_id or app.get("domain") != DOMAIN:
        raise DcrError("access_app_identity_mismatch")
    if app.get("type") not in ("self_hosted", "mcp", "mcp_server"):
        raise DcrError("access_app_type_mismatch")
    cfg = app.get("oauth_configuration")
    if not isinstance(cfg, dict) or cfg.get("enabled") is not True:
        raise DcrError("managed_oauth_not_enabled")
    callbacks = validate_redirects(redirect)
    result = copy.deepcopy(app)
    dynamic = result["oauth_configuration"].setdefault("dynamic_client_registration", {})
    if not isinstance(dynamic, dict):
        raise DcrError("invalid_existing_dcr")
    if dynamic.get("allow_any_on_localhost") or dynamic.get("allow_any_on_loopback"):
        raise DcrError("unrestricted_local_clients_require_separate_review")
    existing = dynamic.get("allowed_uris") or []
    if not isinstance(existing, list) or any(not isinstance(x, str) for x in existing):
        raise DcrError("invalid_redirect_allowlist")
    dynamic.update(enabled=True, allow_any_on_localhost=False, allow_any_on_loopback=False,
                   allowed_uris=list(dict.fromkeys(existing + callbacks)))
    return result


def app_get(token, app_id):
    if not re.fullmatch(r"[0-9a-fA-F-]{32,36}", app_id):
        raise DcrError("invalid_access_app_id")
    response = api_json(f"{API}/accounts/{ACCOUNT}/access/apps/{app_id}", token=token)
    if response.get("success") is not True or not isinstance(response.get("result"), dict):
        raise DcrError("cloudflare_read_failed")
    return response["result"]


def save_private_backup(path, obj):
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as output:
        json.dump(obj, output, sort_keys=True)
        output.write("\n")


def _without_dcr_and_observation_timestamps(app):
    """Compare security-relevant Access settings while allowing server time fields."""
    result = copy.deepcopy(app)
    for key in ("updated_at", "updated_on", "modified_at", "modified_on"):
        result.pop(key, None)
    cfg = result.get("oauth_configuration")
    if not isinstance(cfg, dict):
        raise DcrError("rollback_missing_oauth_configuration")
    cfg.pop("dynamic_client_registration", None)
    return result


def rollback_if_safe(token, app_id, before, expected):
    """Restore preimage only when no concurrent security or DCR change occurred."""
    current = app_get(token, app_id)
    if current.get("id") != app_id or current.get("domain") != DOMAIN:
        raise DcrError("rollback_app_identity_mismatch")
    if _without_dcr_and_observation_timestamps(current) != _without_dcr_and_observation_timestamps(before):
        raise DcrError("rollback_concurrent_app_change")
    before_dcr = before.get("oauth_configuration", {}).get("dynamic_client_registration", {})
    expected_dcr = expected.get("oauth_configuration", {}).get("dynamic_client_registration", {})
    current_dcr = current.get("oauth_configuration", {}).get("dynamic_client_registration", {})
    if current_dcr == before_dcr:
        print("CLOUDFLARE_DCR_ROLLBACK_CONFIRMED=true")
        return
    if current_dcr != expected_dcr:
        raise DcrError("rollback_concurrent_dcr_change")
    result = api_json(f"{API}/accounts/{ACCOUNT}/access/apps/{app_id}",
                      token=token, method="PUT", payload=before)
    if result.get("success") is not True:
        raise DcrError("rollback_provider_update_failed")
    readback = app_get(token, app_id)
    if (_without_dcr_and_observation_timestamps(readback) !=
            _without_dcr_and_observation_timestamps(before) or
            readback.get("oauth_configuration", {}).get("dynamic_client_registration", {}) != before_dcr):
        raise DcrError("rollback_readback_failed")
    print("CLOUDFLARE_DCR_ROLLBACK_CONFIRMED=true")

def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--app-id", default="")
    ap.add_argument("--redirect-uri", action="append", default=[])
    ap.add_argument("--backup-path", default="")
    args = ap.parse_args()
    old = updated = None
    token = ""
    change_might_have_been_sent = False
    try:
        current = probe()
        print("OAUTH_PKCE_S256=true")
        print("OAUTH_DCR_ADVERTISED=" + str(current).lower())
        if not args.apply:
            return 0 if current else 2
        callbacks = validate_redirects(args.redirect_uri)
        if not args.app_id or not args.backup_path:
            raise DcrError("exact_app_id_and_backup_required")
        token = os.environ.get("CLOUDFLARE_API_TOKEN", "")
        if not token:
            raise DcrError("provider_credential_not_in_current_process")
        old = app_get(token, args.app_id)
        updated = plan(old, args.app_id, callbacks)
        if updated != old:
            save_private_backup(Path(args.backup_path), old)
            print("PRIVATE_ROLLBACK_BACKUP_SAVED=true")
            change_might_have_been_sent = True
            response = api_json(f"{API}/accounts/{ACCOUNT}/access/apps/{args.app_id}",
                                token=token, method="PUT", payload=updated)
            if response.get("success") is not True:
                raise DcrError("cloudflare_update_failed")
        readback = app_get(token, args.app_id)
        dcr = readback.get("oauth_configuration", {}).get("dynamic_client_registration", {})
        allowed_after = dcr.get("allowed_uris", [])
        if readback.get("domain") != DOMAIN or dcr.get("enabled") is not True or any(cb not in allowed_after for cb in callbacks):
            raise DcrError("dcr_readback_mismatch")
        if (_without_dcr_and_observation_timestamps(readback) !=
                _without_dcr_and_observation_timestamps(old)):
            raise DcrError("access_app_security_settings_changed_after_write")
        print("CLOUDFLARE_DCR_CONFIG_READBACK_GREEN=true")
        for _ in range(15):
            if probe():
                print("OAUTH_DCR_PUBLIC_GREEN=true")
                return 0
            time.sleep(2)
        raise DcrError("public_oauth_discovery_not_propagated")
    except DcrError as exc:
        if change_might_have_been_sent and old is not None and updated is not None:
            try:
                rollback_if_safe(token, args.app_id, old, updated)
            except DcrError as restore_error:
                print("MCP_DCR_ROLLBACK_ERROR=" + str(restore_error), file=sys.stderr)
                print("MCP_DCR_ERROR=" + str(exc), file=sys.stderr)
                return 4
        print("MCP_DCR_ERROR=" + str(exc), file=sys.stderr)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
