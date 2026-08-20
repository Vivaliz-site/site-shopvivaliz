#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

ACCOUNT_ID = os.getenv("GOOGLE_MERCHANT_ACCOUNT_ID", "5381803710").strip()
API_BASE = "https://merchantapi.googleapis.com/accounts/v1beta"
POLICY_URL = "https://shopvivaliz.com.br/politica-devolucoes"


def fail(msg: str, code: int = 1) -> None:
    print("MERCHANT_RETURNS_ERROR=" + msg)
    raise SystemExit(code)


def token() -> str:
    cid = os.getenv("GOOGLE_OAUTH_CLIENT_ID", "").strip()
    secret = os.getenv("GOOGLE_OAUTH_CLIENT_SECRET", "").strip()
    refresh = os.getenv("GOOGLE_ADS_REFRESH_TOKEN", "").strip()
    if not all((cid, secret, refresh)):
        fail("OAUTH_ENV_MISSING", 2)
    data = urllib.parse.urlencode({
        "client_id": cid,
        "client_secret": secret,
        "refresh_token": refresh,
        "grant_type": "refresh_token",
    }).encode()
    req = urllib.request.Request(
        "https://oauth2.googleapis.com/token",
        data=data,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        payload = json.loads(resp.read().decode())
    access = str(payload.get("access_token") or "")
    if not access:
        fail("ACCESS_TOKEN_MISSING", 3)
    return access


def request_json(access: str, method: str, url: str, payload: dict | None = None) -> dict:
    body = None if payload is None else json.dumps(payload).encode()
    headers = {"Authorization": "Bearer " + access}
    if body is not None:
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            raw = resp.read().decode()
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode(errors="ignore")[:1200]
        print(f"MERCHANT_HTTP_ERROR={exc.code}")
        print("MERCHANT_HTTP_BODY=" + detail.replace("\n", " "))
        raise


def desired_policy(existing: dict | None = None) -> dict:
    policy = dict(existing or {})
    for key in ("name", "returnPolicyId"):
        if existing and existing.get(key):
            policy[key] = existing[key]
    policy["label"] = "default"
    policy["countries"] = ["BR"]
    policy["policy"] = {"type": "NUMBER_OF_DAYS_AFTER_DELIVERY", "days": "7"}
    policy["restockingFee"] = {
        "fixedFee": {"amountMicros": "0", "currencyCode": "BRL"}
    }
    policy["returnMethods"] = ["BY_MAIL"]
    policy["itemConditions"] = ["NEW", "USED"]
    zero_fee = {
        "type": "FIXED",
        "fixedFee": {"amountMicros": "0", "currencyCode": "BRL"},
    }
    policy["returnReasonCategoryInfo"] = [
        {
            "returnReasonCategory": "BUYER_REMORSE",
            "returnLabelSource": "DOWNLOAD_AND_PRINT",
            "returnShippingFee": zero_fee,
        },
        {
            "returnReasonCategory": "ITEM_DEFECT",
            "returnLabelSource": "DOWNLOAD_AND_PRINT",
            "returnShippingFee": zero_fee,
        },
    ]
    policy["returnPolicyUri"] = POLICY_URL
    return policy


def main() -> int:
    if not ACCOUNT_ID.isdigit():
        fail("INVALID_ACCOUNT_ID", 4)
    access = token()
    parent = f"accounts/{ACCOUNT_ID}"
    list_url = f"{API_BASE}/{parent}/onlineReturnPolicies"
    payload = request_json(access, "GET", list_url)
    policies = payload.get("onlineReturnPolicies") or []
    target = next(
        (
            p for p in policies
            if str(p.get("label") or "").lower() == "default"
            and "BR" in (p.get("countries") or [])
        ),
        None,
    )
    desired = desired_policy(target)
    if target:
        name = str(target.get("name") or "")
        if not name.startswith(parent + "/onlineReturnPolicies/"):
            fail("INVALID_POLICY_NAME", 5)
        result = request_json(
            access,
            "PATCH",
            f"{API_BASE}/{name}?updateMask=%2A",
            desired,
        )
        action = "UPDATED"
    else:
        result = request_json(access, "POST", list_url, desired)
        action = "CREATED"

    print("MERCHANT_ACCOUNT_ID=" + ACCOUNT_ID)
    print("MERCHANT_RETURN_POLICY_ACTION=" + action)
    print("MERCHANT_RETURN_POLICY_NAME=" + str(result.get("name") or ""))
    print("MERCHANT_RETURN_WINDOW_DAYS=7")
    print("MERCHANT_RETURN_SHIPPING_COST=0 BRL")
    print("MERCHANT_RETURN_POLICY_URL=" + POLICY_URL)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except urllib.error.HTTPError:
        raise SystemExit(10)
