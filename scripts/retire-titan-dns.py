#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import urllib.parse
import urllib.request

API = "https://api.cloudflare.com/client/v4"
ZONE = "shopvivaliz.com.br"
RETIRED_RECORD = "titan1._domainkey.shopvivaliz.com.br"

def api_json(token: str, method: str, path: str) -> dict:
    request = urllib.request.Request(
        API + path,
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        method=method,
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.loads(response.read().decode("utf-8"))
    if not payload.get("success"):
        raise RuntimeError("cloudflare_api_failed")
    return payload

def select_zone_id(payload: dict) -> str:
    rows = payload.get("result") or []
    exact = [row for row in rows if row.get("name") == ZONE and row.get("id")]
    if len(exact) != 1:
        raise RuntimeError("cloudflare_zone_not_unique")
    return str(exact[0]["id"])

def validate_retired_records(payload: dict) -> list[str]:
    rows = payload.get("result") or []
    ids: list[str] = []
    for row in rows:
        if row.get("name") != RETIRED_RECORD:
            continue
        if row.get("type") != "TXT":
            raise RuntimeError("unexpected_record_type_for_titan_selector")
        record_id = str(row.get("id") or "").strip()
        if not record_id:
            raise RuntimeError("cloudflare_record_id_missing")
        ids.append(record_id)
    return ids

def main() -> int:
    parser = argparse.ArgumentParser(description="Retire the obsolete Titan DKIM DNS selector")
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    token = (os.getenv("CLOUDFLARE_DNS_EDIT_TOKEN") or os.getenv("CLOUDFLARE_API_TOKEN") or "").strip()
    if not token:
        raise SystemExit("cloudflare_dns_token_missing")

    zone_payload = api_json(token, "GET", "/zones?" + urllib.parse.urlencode({"name": ZONE}))
    zone_id = select_zone_id(zone_payload)
    query = urllib.parse.urlencode({"name": RETIRED_RECORD})
    record_payload = api_json(token, "GET", f"/zones/{zone_id}/dns_records?{query}")
    record_ids = validate_retired_records(record_payload)

    print("zone=" + ZONE)
    print("retired_record=" + RETIRED_RECORD)
    print("matching_records=" + str(len(record_ids)))
    print("apply=" + str(args.apply).lower())

    if not args.apply:
        return 0

    for record_id in record_ids:
        api_json(token, "DELETE", f"/zones/{zone_id}/dns_records/{record_id}")

    verify_payload = api_json(token, "GET", f"/zones/{zone_id}/dns_records?{query}")
    remaining = validate_retired_records(verify_payload)
    print("remaining_records=" + str(len(remaining)))
    if remaining:
        raise SystemExit("titan_dns_retirement_incomplete")
    print("titan_dns_retirement=PASS")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
