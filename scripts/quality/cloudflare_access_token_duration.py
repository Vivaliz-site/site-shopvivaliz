#!/usr/bin/env python3
"""Altera a duracao do service token do Cloudflare Access usado pelo MCP da Marina.

Em vez de gerar um token novo, atualiza a duracao do token existente para "forever"
(sem expiracao). Client ID e Client Secret NAO mudam, entao nenhum segredo e copiado,
rotacionado ou impresso. O token e localizado pelo NOME exato (nao e segredo).

Modo padrao: seco (so le e informa a acao prevista). Com --apply faz o PUT e le de novo
para provar o resultado. Usa a API oficial do Cloudflare:
  CLOUDFLARE_DNS_EDIT_TOKEN  -> descobrir o account id pela zona
  CLOUDFLARE_API_TOKEN       -> ler/editar service tokens (precisa de Access: Service Tokens Edit)

Saida: somente nome, datas, booleanos e veredito. Nunca imprime ids, client_id ou secrets.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.request

API = "https://api.cloudflare.com/client/v4"
DEFAULT_TOKEN_NAME = "marina-mcp-automation-3521-eb504b66"
TARGET_DURATION = "forever"


def find_by_name(tokens: list[dict], name: str) -> dict | None:
    """Retorna o service token cujo nome bate exatamente; None se nao houver ou se for ambiguo."""
    matches = [token for token in tokens if str(token.get("name") or "") == name]
    return matches[0] if len(matches) == 1 else None


def plan(token: dict | None) -> dict:
    """Logica pura: decide a acao a partir do token lido. Nao faz rede."""
    if token is None:
        return {"verdict": "TOKEN_NOT_FOUND", "will_update": False}
    expires_at = str(token.get("expires_at") or "")
    already = str(token.get("duration") or "") == TARGET_DURATION
    return {
        "verdict": "ALREADY_NON_EXPIRING" if already else "WILL_UPDATE",
        "will_update": not already,
        "expires_at": expires_at,
        "duration": str(token.get("duration") or ""),
    }


def candidate_lines(tokens: list[dict], limit: int = 20) -> list[str]:
    """Quando o nome nao bate, lista so nome e expiracao de tokens que lembram Marina/MCP.

    Nome e data nao sao segredo; ids, client_id e secrets nunca sao impressos.
    """
    lines = []
    for token in tokens:
        name = str(token.get("name") or "")
        if "marina" in name.lower() or "mcp" in name.lower():
            lines.append(f"SERVICE_TOKEN_CANDIDATE name={name} expires_at={token.get('expires_at') or ''}"
                         f" duration={token.get('duration') or ''}")
    return lines[:limit]


def classify_http_error(code: int) -> str:
    if code in (401, 403):
        return "SCOPE_INSUFFICIENT"
    if code in (400, 422):
        return "DURATION_REJECTED"
    return f"HTTP_{code}"


def request(method: str, path: str, token: str, payload: dict | None = None) -> tuple[int, dict]:
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    req = urllib.request.Request(API + path, data=data, method=method, headers={
        "Authorization": "Bearer " + token,
        "Content-Type": "application/json",
    })
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return response.status, json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        return error.code, {}


def list_service_tokens(account_id: str, token: str) -> tuple[int, list[dict]]:
    items: list[dict] = []
    page = 1
    while True:
        code, body = request("GET", f"/accounts/{account_id}/access/service_tokens?per_page=50&page={page}", token)
        if code != 200 or body.get("success") is not True:
            return code, items
        items.extend(body.get("result") or [])
        total_pages = int((body.get("result_info") or {}).get("total_pages") or 1)
        if page >= total_pages:
            return 200, items
        page += 1


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true", help="faz o PUT; sem isso e modo seco")
    parser.add_argument("--name", default=os.environ.get("MARINA_TOKEN_NAME") or DEFAULT_TOKEN_NAME)
    args = parser.parse_args()

    api_token = os.environ.get("CLOUDFLARE_API_TOKEN", "")
    zone_token = os.environ.get("CLOUDFLARE_DNS_EDIT_TOKEN", "")
    if not (api_token and zone_token):
        print("MARINA_TOKEN_DURATION=NOT_PROVISIONED")
        return 2

    code, body = request("GET", "/zones?name=shopvivaliz.com.br&status=active&per_page=1", zone_token)
    zones = body.get("result") or []
    account_id = str(((zones[0] if zones else {}).get("account") or {}).get("id") or "")
    if code != 200 or not account_id:
        print("CLOUDFLARE_ACCOUNT_LOOKUP=FAILED")
        return 3

    code, tokens = list_service_tokens(account_id, api_token)
    if code != 200:
        print(f"MARINA_TOKEN_DURATION={classify_http_error(code)}")
        return 4
    token = find_by_name(tokens, args.name)
    decision = plan(token)
    print(f"MARINA_TOKEN_NAME={args.name}")
    print(f"MARINA_TOKEN_FOUND={str(token is not None).lower()}")
    print(f"MARINA_TOKEN_EXPIRES_AT_BEFORE={decision.get('expires_at', '')}")
    print(f"MARINA_TOKEN_DURATION_BEFORE={decision.get('duration', '')}")
    print(f"MARINA_TOKEN_PLAN={decision['verdict']}")
    if token is None:
        print(f"SERVICE_TOKENS_TOTAL={len(tokens)}")
        for line in candidate_lines(tokens):
            print(line)
        return 5
    if not decision["will_update"]:
        print("MARINA_TOKEN_DURATION=ALREADY_NON_EXPIRING")
        return 0
    if not args.apply:
        print("MARINA_TOKEN_DURATION=DRY_RUN")
        return 0

    token_id = str(token.get("id") or "")
    code, _ = request("PUT", f"/accounts/{account_id}/access/service_tokens/{token_id}", api_token,
                      {"name": args.name, "duration": TARGET_DURATION})
    if code != 200:
        print(f"MARINA_TOKEN_DURATION={classify_http_error(code)}")
        return 6

    code, tokens = list_service_tokens(account_id, api_token)
    after = find_by_name(tokens, args.name) if code == 200 else None
    print(f"MARINA_TOKEN_EXPIRES_AT_AFTER={str((after or {}).get('expires_at') or '')}")
    print(f"MARINA_TOKEN_DURATION_AFTER={str((after or {}).get('duration') or '')}")
    ok = after is not None and str(after.get("duration") or "") == TARGET_DURATION
    print("MARINA_TOKEN_DURATION=" + ("UPDATED" if ok else "UPDATE_NOT_CONFIRMED"))
    return 0 if ok else 7


if __name__ == "__main__":
    sys.exit(main())
