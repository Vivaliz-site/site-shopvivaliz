#!/usr/bin/env python3
"""
Sonda combinacoes de secrets de Shopee e TikTok sem expor os valores.

Objetivo:
- testar combinacoes plausiveis de nomes/hosts/chaves
- identificar se o bloqueio e assinatura, token invalido, app key incorreta, etc.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import time
from itertools import product
from typing import Any

import requests


def present(name: str) -> bool:
    return bool(os.environ.get(name, "").strip())


def env(name: str) -> str:
    return os.environ.get(name, "").strip()


def log(section: str, message: str) -> None:
    print(f"[{section}] {message}")


def short_json(data: Any, limit: int = 320) -> str:
    try:
        text = json.dumps(data, ensure_ascii=False)
    except Exception:
        text = str(data)
    return text[:limit]


def shopee_sign(partner_id: str, partner_key: str, path: str, timestamp: int, access_token: str, shop_id: str) -> str:
    api_path = path if path.startswith("/api/") else f"/api/v2{path}"
    base = f"{partner_id}{api_path}{timestamp}{access_token}{shop_id}"
    return hmac.new(partner_key.encode("utf-8"), base.encode("utf-8"), hashlib.sha256).hexdigest()


def shopee_auth_sign(partner_id: str, partner_key: str, path: str, timestamp: int) -> str:
    api_path = path if path.startswith("/api/") else f"/api/v2{path}"
    base = f"{partner_id}{api_path}{timestamp}"
    return hmac.new(partner_key.encode("utf-8"), base.encode("utf-8"), hashlib.sha256).hexdigest()


def probe_shopee() -> None:
    log("shopee", "inicio")
    partner_id_candidates = [(name, env(name)) for name in ("SHOPEE_PARTNER_ID", "SHOPEE_TEST_PARTNER_ID") if present(name)]
    partner_key_candidates = [(name, env(name)) for name in ("SHOPEE_PARTNER_KEY", "SHOPEE_TEST_PARTNER_KEY", "SHOPEE_TEST_API_KEY") if present(name)]
    access_token_candidates = [(name, env(name)) for name in ("SHOPEE_ACCESS_TOKEN",) if present(name)]
    refresh_token_candidates = [(name, env(name)) for name in ("SHOPEE_REFRESH_TOKEN",) if present(name)]
    shop_id_candidates = [(name, env(name)) for name in ("SHOPEE_SHOP_ID",) if present(name)]

    base_urls: list[tuple[str, str]] = []
    if present("SHOPEE_BASE_URL"):
        base_urls.append(("SHOPEE_BASE_URL", env("SHOPEE_BASE_URL").rstrip("/")))
    base_urls.extend([
        ("sandbox", "https://openplatform.sandbox.test-stable.shopee.sg/api/v2"),
        ("prod", "https://partner.shopeemobile.com/api/v2"),
    ])

    if not (partner_id_candidates and partner_key_candidates and shop_id_candidates):
        log("shopee", "faltam secrets minimos para probe")
        return

    seen: set[tuple[str, str, str]] = set()

    for (base_name, base_url), (pid_name, partner_id), (pkey_name, partner_key), (shop_name, shop_id) in product(
        base_urls, partner_id_candidates, partner_key_candidates, shop_id_candidates
    ):
        combo_id = (base_name, pid_name, pkey_name)
        if combo_id in seen:
            continue
        seen.add(combo_id)
        log("shopee", f"testando base={base_name} partner_id={pid_name} partner_key={pkey_name} shop_id={shop_name}")

        if refresh_token_candidates:
            for rname, refresh_token in refresh_token_candidates:
                path = "/auth/access_token/get"
                timestamp = int(time.time())
                params = {
                    "partner_id": partner_id,
                    "timestamp": timestamp,
                    "sign": shopee_auth_sign(partner_id, partner_key, path, timestamp),
                }
                body = {
                    "refresh_token": refresh_token,
                    "shop_id": int(shop_id),
                    "partner_id": int(partner_id),
                }
                try:
                    resp = requests.post(f"{base_url}{path}", params=params, json=body, timeout=30)
                    data = resp.json()
                except Exception as exc:
                    log("shopee", f"refresh erro tecnico: {exc}")
                    continue
                response = data.get("response", {})
                if response.get("access_token"):
                    log("shopee", f"refresh OK com {base_name}/{pid_name}/{pkey_name}/{rname}")
                    access_token_candidates = [("refreshed_access_token", response["access_token"])]
                    break
                message = data.get("message") or data.get("error") or short_json(data)
                log("shopee", f"refresh falhou: {message}")

        for aname, access_token in access_token_candidates:
            path = "/product/get_item_list"
            timestamp = int(time.time())
            params = {
                "partner_id": partner_id,
                "timestamp": timestamp,
                "access_token": access_token,
                "shop_id": shop_id,
                "sign": shopee_sign(partner_id, partner_key, path, timestamp, access_token, shop_id),
                "offset": 0,
                "page_size": 1,
                "item_status": "NORMAL",
            }
            try:
                resp = requests.get(f"{base_url}{path}", params=params, timeout=30)
                data = resp.json()
            except Exception as exc:
                log("shopee", f"list erro tecnico: {exc}")
                continue
            message = data.get("message") or data.get("error") or "ok"
            log("shopee", f"list via {aname}: http={resp.status_code} resultado={message}")


def tiktok_sign(app_secret: str, path: str, params: dict[str, str], body: str = "") -> str:
    exclude = {"sign", "access_token"}
    sorted_params = sorted((k, str(v)) for k, v in params.items() if k not in exclude)
    param_str = "".join(f"{k}{v}" for k, v in sorted_params)
    sign_str = f"{app_secret}{path}{param_str}{body}{app_secret}"
    return hmac.new(app_secret.encode("utf-8"), sign_str.encode("utf-8"), hashlib.sha256).hexdigest()


def probe_tiktok() -> None:
    log("tiktok", "inicio")
    app_key_candidates = [(name, env(name)) for name in ("TIKTOK_APP_KEY", "TIKTOK_SERVICE_ID") if present(name)]
    app_secret_candidates = [(name, env(name)) for name in ("TIKTOK_APP_SECRET",) if present(name)]
    access_token_candidates = [(name, env(name)) for name in ("TIKTOK_ACCESS_TOKEN",) if present(name)]
    shop_candidates = [(name, env(name)) for name in ("TIKTOK_SHOP_CIPHER", "TIKTOK_SHOP_ID") if present(name)]

    if not (app_key_candidates and app_secret_candidates and access_token_candidates and shop_candidates):
        log("tiktok", "faltam secrets minimos para probe")
        return

    path = "/product/202309/products/search"
    body = "{}"

    for (app_name, app_key), (secret_name, app_secret), (token_name, access_token), (shop_name, shop_value) in product(
        app_key_candidates, app_secret_candidates, access_token_candidates, shop_candidates
    ):
        timestamp = str(int(time.time()))
        params = {
            "app_key": app_key,
            "shop_cipher": shop_value,
            "timestamp": timestamp,
            "page_size": "1",
            "version": "202309",
        }
        params["sign"] = tiktok_sign(app_secret, path, params, body)
        headers = {
            "x-tts-access-token": access_token,
            "Content-Type": "application/json",
        }
        log("tiktok", f"testando app={app_name} secret={secret_name} token={token_name} shop={shop_name}")
        try:
            resp = requests.post(
                f"https://open-api.tiktokglobalshop.com{path}",
                params=params,
                data=body,
                headers=headers,
                timeout=30,
            )
            data = resp.json()
        except Exception as exc:
            log("tiktok", f"erro tecnico: {exc}")
            continue
        code = data.get("code")
        message = data.get("message") or short_json(data)
        log("tiktok", f"http={resp.status_code} code={code} message={message}")


def main() -> int:
    print("== Marketplace Secrets Probe ==")
    probe_shopee()
    probe_tiktok()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
