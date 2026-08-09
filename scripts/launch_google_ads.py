#!/usr/bin/env python3
"""Read-only launcher summary for the current reviewed Google Ads config.

This script intentionally contains no account ID, no credentials and no live
mutation path. It reads the canonical reviewed configuration and prints the
safe next steps.
"""

from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "scripts" / "google_ads_campaign_live_ready.json"


def main() -> int:
    data = json.loads(CONFIG.read_text(encoding="utf-8"))
    campaign = data["campaign"]
    groups = data.get("ad_groups", [])
    guardrails = data.get("guardrails", {})

    print("GOOGLE_ADS_REVIEWED_CONFIG_SUMMARY")
    print(f"campaign={campaign.get('name', '')}")
    print(f"status_on_create={campaign.get('status_on_create', '')}")
    print(f"daily_budget_brl={campaign.get('daily_budget_brl', '')}")
    print(f"max_cpc_brl={campaign.get('bidding', {}).get('max_cpc_brl', '')}")
    print(f"ad_groups={len(groups)}")
    print(f"keywords={sum(len(group.get('keywords', [])) for group in groups)}")
    print(f"target_roi={guardrails.get('target_roi', '')}")
    print("customer_id_source=secure_environment_only")
    print("credentials_source=secure_secret_store_only")
    print("NO_CHANGES_MADE")
    print("next=python scripts/google_ads_auth_preflight.py")
    print("next=python scripts/google_ads_real_readiness.py")
    print("next=python scripts/google_ads_create_search_campaign.py")
    print("real_creation_requires=--create-paused_after_explicit_review")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
