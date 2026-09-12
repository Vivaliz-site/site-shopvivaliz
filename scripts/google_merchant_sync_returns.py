#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request

ACCOUNT_ID = os.getenv("GOOGLE_MERCHANT_ACCOUNT_ID", "5381803710").strip()
API_BASE = "https://merchantapi.googleapis.com/accounts/v1"
POLICY_URL = "https://shopvivaliz.com.br/politica-devolucoes/"
WRITABLE_FIELDS = (
    "label",
    "countries",
    "policy",
    "seasonalOverrides",
    "restockingFee",
    "returnMethods",
    "itemConditions",
    "returnShippingFee",
    "returnPolicyUri",
    "acceptDefectiveOnly",
    "processRefundDays",
    "acceptExchange",
    "returnLabelSource",
)


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


def is_default_policy(policy: dict, country: str = "BR") -> bool:
    label = str(policy.get("label") or "").strip().lower()
    countries = {str(value).upper() for value in (policy.get("countries") or [])}
    return country.upper() in countries and label in {"", "default"}


def _zero_price(value: dict | None, *, missing_is_zero: bool = False) -> bool:
    if not value:
        return missing_is_zero
    amount = str(value.get("amountMicros") or "0")
    currency = str(value.get("currencyCode") or "BRL").upper()
    return amount == "0" and currency == "BRL"


def desired_policy(existing: dict | None = None) -> dict:
    del existing  # v1 default policies are recreated without a label.
    return {
        "countries": ["BR"],
        "policy": {"type": "NUMBER_OF_DAYS_AFTER_DELIVERY", "days": "7"},
        "restockingFee": {
            "fixedFee": {"amountMicros": "0", "currencyCode": "BRL"}
        },
        "returnMethods": ["BY_MAIL"],
        "itemConditions": ["NEW", "USED"],
        "returnShippingFee": {
            "type": "FIXED",
            "fixedFee": {"amountMicros": "0", "currencyCode": "BRL"},
        },
        "returnPolicyUri": POLICY_URL,
        "returnLabelSource": "DOWNLOAD_AND_PRINT",
        "acceptExchange": True,
    }


def create_payload(policy: dict) -> dict:
    return {key: policy[key] for key in WRITABLE_FIELDS if key in policy and policy[key] is not None}


def policy_matches(actual: dict, desired: dict) -> bool:
    if not is_default_policy(actual, "BR"):
        return False
    actual_rule = actual.get("policy") or {}
    desired_rule = desired.get("policy") or {}
    if str(actual_rule.get("type") or "") != str(desired_rule.get("type") or ""):
        return False
    if str(actual_rule.get("days") or "") != str(desired_rule.get("days") or ""):
        return False
    if set(actual.get("returnMethods") or []) != set(desired.get("returnMethods") or []):
        return False
    if set(actual.get("itemConditions") or []) != set(desired.get("itemConditions") or []):
        return False
    actual_shipping = actual.get("returnShippingFee") or {}
    desired_shipping = desired.get("returnShippingFee") or {}
    if str(actual_shipping.get("type") or "") != str(desired_shipping.get("type") or ""):
        return False
    if not _zero_price(actual_shipping.get("fixedFee"), missing_is_zero=True):
        return False
    actual_restocking = actual.get("restockingFee") or {}
    if not _zero_price(actual_restocking.get("fixedFee"), missing_is_zero=True):
        return False
    if str(actual.get("returnLabelSource") or "") != str(desired.get("returnLabelSource") or ""):
        return False
    if str(actual.get("returnPolicyUri") or "").rstrip("/") != str(desired.get("returnPolicyUri") or "").rstrip("/"):
        return False
    if bool(actual.get("acceptExchange", False)) != bool(desired.get("acceptExchange", False)):
        return False
    return True


def list_policies(access: str, list_url: str) -> list[dict]:
    payload = request_json(access, "GET", list_url)
    policies = payload.get("onlineReturnPolicies") or []
    return [item for item in policies if isinstance(item, dict)]


def find_default_policy(policies: list[dict], country: str = "BR") -> dict | None:
    return next((item for item in policies if is_default_policy(item, country)), None)


def delete_policy(access: str, parent: str, policy: dict) -> None:
    name = str(policy.get("name") or "")
    if not name.startswith(parent + "/onlineReturnPolicies/"):
        fail("INVALID_POLICY_NAME", 5)
    request_json(access, "DELETE", f"{API_BASE}/{name}")


def replace_policy(access: str, parent: str, list_url: str, current: dict, desired: dict) -> dict:
    backup = create_payload(current)
    delete_policy(access, parent, current)
    try:
        created = request_json(access, "POST", list_url, desired)
        readback = find_default_policy(list_policies(access, list_url), "BR")
        if readback is None or not policy_matches(readback, desired):
            raise RuntimeError("POLICY_READBACK_MISMATCH")
        return readback or created
    except Exception as original_error:
        try:
            # If creation succeeded but read-back failed, remove that replacement
            # before restoring the exact writable state captured above.
            replacement = find_default_policy(list_policies(access, list_url), "BR")
            if replacement is not None:
                delete_policy(access, parent, replacement)
        except Exception as cleanup_error:
            print("MERCHANT_RETURN_POLICY_ROLLBACK_CLEANUP_ERROR=" + type(cleanup_error).__name__)
        try:
            restored = request_json(access, "POST", list_url, backup)
            restored_name = str(restored.get("name") or "")
            print("MERCHANT_RETURN_POLICY_ROLLBACK=true")
            print("MERCHANT_RETURN_POLICY_ROLLBACK_NAME=" + restored_name)
        except Exception as rollback_error:
            print("MERCHANT_RETURN_POLICY_ROLLBACK=false")
            raise RuntimeError("MERCHANT_RETURN_POLICY_ROLLBACK_FAILED") from rollback_error
        raise original_error


def main() -> int:
    if not ACCOUNT_ID.isdigit():
        fail("INVALID_ACCOUNT_ID", 4)
    access = token()
    parent = f"accounts/{ACCOUNT_ID}"
    list_url = f"{API_BASE}/{parent}/onlineReturnPolicies"
    policies = list_policies(access, list_url)
    target = find_default_policy(policies, "BR")
    desired = desired_policy(target)

    if target is not None and policy_matches(target, desired):
        result = target
        action = "VERIFIED"
    elif target is not None:
        result = replace_policy(access, parent, list_url, target, desired)
        action = "REPLACED"
    else:
        result = request_json(access, "POST", list_url, desired)
        readback = find_default_policy(list_policies(access, list_url), "BR")
        if readback is None or not policy_matches(readback, desired):
            if readback is not None:
                delete_policy(access, parent, readback)
            raise RuntimeError("POLICY_CREATE_READBACK_MISMATCH")
        result = readback or result
        action = "CREATED"

    print("MERCHANT_ACCOUNT_ID=" + ACCOUNT_ID)
    print("MERCHANT_RETURN_POLICY_ACTION=" + action)
    print("MERCHANT_RETURN_POLICY_NAME=" + str(result.get("name") or ""))
    print("MERCHANT_RETURN_WINDOW_DAYS=7")
    print("MERCHANT_RETURN_SHIPPING_COST=0 BRL")
    print("MERCHANT_RETURN_LABEL_SOURCE=DOWNLOAD_AND_PRINT")
    print("MERCHANT_RETURN_POLICY_URL=" + POLICY_URL)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except urllib.error.HTTPError:
        raise SystemExit(10)
