#!/usr/bin/env python3
"""Diagnostico somente-leitura do service token do Cloudflare Access usado pelo MCP da Marina.

Compara o token cujo Client ID esta em MARINA_MCP_ACCESS_CLIENT_ID com a lista de service tokens
da conta e verifica se alguma politica de aplicacao `mcp-marina` aceita esse token. Usa a API
oficial do Cloudflare com CLOUDFLARE_API_TOKEN (leitura) e CLOUDFLARE_DNS_EDIT_TOKEN (zona).

Nunca imprime o Client ID, o Client Secret, nem tokens: a comparacao e feita por SHA-256 e a
saida contem somente nome, datas, booleanos e o veredito.
"""
from __future__ import annotations

import datetime as dt
import hashlib
import json
import os
import sys
import urllib.error
import urllib.request

API = "https://api.cloudflare.com/client/v4"
APP_DOMAIN_MARKER = "mcp-marina."


def sha256_hex(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def find_token(tokens: list[dict], client_id: str) -> dict | None:
    """Retorna o service token cujo client_id bate com o secret, comparando por hash."""
    wanted = sha256_hex(client_id)
    for token in tokens:
        candidate = str(token.get("client_id") or "")
        if candidate and sha256_hex(candidate) == wanted:
            return token
    return None


def is_expired(expires_at: str | None, now: dt.datetime) -> bool:
    if not expires_at:
        return False
    try:
        parsed = dt.datetime.fromisoformat(expires_at.replace("Z", "+00:00"))
    except ValueError:
        return True
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=dt.timezone.utc)
    return parsed <= now


def policy_accepts_token(policies: list[dict], token_id: str) -> bool:
    for policy in policies:
        for rule in policy.get("include") or []:
            service = rule.get("service_token") if isinstance(rule, dict) else None
            if isinstance(service, dict) and service.get("token_id") == token_id:
                return True
    return False


def evaluate(tokens: list[dict], apps: list[dict], policies_by_app: dict[str, list[dict]],
             client_id: str, now: dt.datetime) -> dict:
    """Logica pura do diagnostico; nao faz rede e nao imprime segredos."""
    token = find_token(tokens, client_id)
    result: dict = {"token_found": token is not None}
    if token is None:
        result["verdict"] = "TOKEN_NOT_FOUND"
        return result

    result["token_name"] = str(token.get("name") or "")
    result["token_expires_at"] = str(token.get("expires_at") or "")
    result["token_expired"] = is_expired(token.get("expires_at"), now)

    marina_apps = [app for app in apps if APP_DOMAIN_MARKER in str(app.get("domain") or "")]
    result["marina_app_count"] = len(marina_apps)
    accepted = [app for app in marina_apps
                if policy_accepts_token(policies_by_app.get(str(app.get("id")), []), str(token.get("id")))]
    result["policy_accepts_token"] = bool(accepted)
    result["policy_app_names"] = [str(app.get("name") or "") for app in accepted]

    if result["token_expired"]:
        result["verdict"] = "TOKEN_EXPIRED"
    elif not marina_apps:
        result["verdict"] = "MARINA_APP_NOT_FOUND"
    elif not accepted:
        result["verdict"] = "NOT_IN_MARINA_POLICY"
    else:
        result["verdict"] = "OK"
    return result


def api_get(path: str, token: str) -> dict:
    request = urllib.request.Request(API + path, headers={"Authorization": "Bearer " + token})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            body = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        raise SystemExit(f"CLOUDFLARE_API_HTTP={error.code} path={path.split('?')[0]}")
    if body.get("success") is not True:
        raise SystemExit(f"CLOUDFLARE_API_SUCCESS=false path={path.split('?')[0]}")
    return body


def list_all(path: str, token: str) -> list[dict]:
    items: list[dict] = []
    page = 1
    while True:
        separator = "&" if "?" in path else "?"
        body = api_get(f"{path}{separator}per_page=50&page={page}", token)
        items.extend(body.get("result") or [])
        info = body.get("result_info") or {}
        total_pages = int(info.get("total_pages") or 1)
        if page >= total_pages:
            return items
        page += 1


def main() -> int:
    reader_token = os.environ.get("CLOUDFLARE_API_TOKEN", "")
    zone_token = os.environ.get("CLOUDFLARE_DNS_EDIT_TOKEN", "")
    client_id = os.environ.get("MARINA_MCP_ACCESS_CLIENT_ID", "")
    if not (reader_token and zone_token and client_id):
        print("CLOUDFLARE_ACCESS_TOKEN_PROBE=NOT_PROVISIONED")
        return 2

    zone = api_get("/zones?name=shopvivaliz.com.br&status=active&per_page=1", zone_token)
    zones = zone.get("result") or []
    account_id = str(((zones[0] if zones else {}).get("account") or {}).get("id") or "")
    if not account_id:
        print("CLOUDFLARE_ACCOUNT_LOOKUP=FAILED")
        return 3

    tokens = list_all(f"/accounts/{account_id}/access/service_tokens", reader_token)
    apps = list_all(f"/accounts/{account_id}/access/apps", reader_token)
    policies_by_app = {
        str(app.get("id")): list_all(f"/accounts/{account_id}/access/apps/{app.get('id')}/policies", reader_token)
        for app in apps if APP_DOMAIN_MARKER in str(app.get("domain") or "")
    }

    result = evaluate(tokens, apps, policies_by_app, client_id, dt.datetime.now(dt.timezone.utc))
    print(f"MARINA_TOKEN_FOUND={str(result['token_found']).lower()}")
    if result["token_found"]:
        print(f"MARINA_TOKEN_NAME={result['token_name']}")
        print(f"MARINA_TOKEN_EXPIRES_AT={result['token_expires_at']}")
        print(f"MARINA_TOKEN_EXPIRED={str(result['token_expired']).lower()}")
        print(f"MARINA_APP_COUNT={result['marina_app_count']}")
        print(f"MARINA_POLICY_ACCEPTS_TOKEN={str(result['policy_accepts_token']).lower()}")
        print("MARINA_POLICY_APPS=" + ",".join(result["policy_app_names"]))
    print(f"MARINA_TOKEN_VERDICT={result['verdict']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
