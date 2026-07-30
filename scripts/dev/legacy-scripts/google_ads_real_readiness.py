#!/usr/bin/env python3
"""Fail-closed readiness check for a real Google Ads campaign launch.

This script does not create or activate ads. It verifies that the campaign
configuration is policy-safe enough to attempt creation and that required
credentials are present. It exits non-zero instead of simulating success.
"""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "scripts" / "google_ads_campaign_live_ready.json"
REQUIRED_ENV = [
    "GOOGLE_OAUTH_CLIENT_ID",
    "GOOGLE_OAUTH_CLIENT_SECRET",
    "GOOGLE_ADS_CUSTOMER_ID",
    "GOOGLE_ADS_DEVELOPER_TOKEN",
    "GOOGLE_ADS_REFRESH_TOKEN",
]
MANUAL_CONVERSION_ENV = ["GOOGLE_ADS_ID", "GOOGLE_ADS_CONVERSION_LABEL"]
GA4_IMPORT_ENV = ["GOOGLE_ANALYTICS_ID"]


def load_dotenv(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


def is_placeholder(value: str) -> bool:
    lowered = value.strip().lower()
    return (
        lowered == ""
        or lowered in {"placeholder", "changeme", "xxx"}
        or lowered.startswith(("obter", "g-xx", "your_"))
        or "developer_token" in lowered
    )


def main() -> int:
    load_dotenv(ROOT / ".env")
    errors: list[str] = []

    try:
        config = json.loads(CONFIG.read_text(encoding="utf-8"))
    except Exception as exc:
        print(f"CONFIG_ERROR: {exc}", file=sys.stderr)
        return 1

    missing = [key for key in REQUIRED_ENV if is_placeholder(os.getenv(key, ""))]
    conversion_source = os.getenv("GOOGLE_ADS_CONVERSION_SOURCE", "MANUAL_GTAG").strip().upper()
    if conversion_source == "GA4_IMPORT":
        missing.extend(key for key in GA4_IMPORT_ENV if is_placeholder(os.getenv(key, "")))
    else:
        missing.extend(key for key in MANUAL_CONVERSION_ENV if is_placeholder(os.getenv(key, "")))
    if missing:
        errors.append("missing_or_placeholder_env=" + ",".join(missing))

    try:
        import google.ads.googleads  # noqa: F401
    except Exception:
        errors.append("python_package_missing=google-ads")

    campaign = config.get("campaign", {})
    guardrails = config.get("guardrails", {})
    if campaign.get("status_on_create") != "PAUSED":
        errors.append("campaign_must_create_paused_first")
    if float(campaign.get("daily_budget_brl", 0)) > float(guardrails.get("max_daily_budget_brl", 0)):
        errors.append("daily_budget_exceeds_guardrail")
    if float(campaign.get("bidding", {}).get("max_cpc_brl", 0)) > float(guardrails.get("max_cpc_brl", 0)):
        errors.append("max_cpc_exceeds_roi_guardrail")

    target_aov = float(guardrails.get("target_average_order_value_brl", 0))
    target_conversion_rate = float(guardrails.get("target_conversion_rate_percent", 0)) / 100
    max_cpc = float(campaign.get("bidding", {}).get("max_cpc_brl", 0))
    target_roi = float(guardrails.get("target_roi", 0))
    if target_aov <= 0 or target_conversion_rate <= 0 or target_roi < 10:
        errors.append("roi10_assumptions_missing")
    elif max_cpc > 0:
        break_even_roi_cpc = (target_aov * target_conversion_rate) / target_roi
        if max_cpc > break_even_roi_cpc:
            errors.append(
                "max_cpc_above_roi10_math="
                + f"max_cpc:{max_cpc:.2f},roi10_cpc:{break_even_roi_cpc:.2f}"
            )

    keywords = config.get("keywords", [])
    if any(str(item.get("match_type", "")).upper() == "BROAD" for item in keywords):
        errors.append("broad_match_not_allowed")
    high_intent_tokens = [
        "kit",
        "comprar",
        "freio",
        "12",
        "10",
        "carrinho",
        "ferramentas",
        "fercar",
        "caixa",
        "japi",
        "cachepot",
        "vaso",
        "floreira",
    ]
    weak_keywords = [
        item.get("text", "")
        for item in keywords
        if not any(token in str(item.get("text", "")).lower() for token in high_intent_tokens)
    ]
    if weak_keywords:
        errors.append(f"low_intent_keyword_count={len(weak_keywords)}")
    expensive_keywords = [
        item.get("text", "")
        for item in keywords
        if float(item.get("cpc_brl", 0)) > float(guardrails.get("max_cpc_brl", 0))
    ]
    if expensive_keywords:
        errors.append(f"keyword_cpc_exceeds_guardrail_count={len(expensive_keywords)}")

    ad = config.get("responsive_search_ad", {})
    long_headlines = [text for text in ad.get("headlines", []) if len(text) > 30]
    long_descriptions = [text for text in ad.get("descriptions", []) if len(text) > 90]
    if long_headlines:
        errors.append(f"headline_too_long_count={len(long_headlines)}")
    if long_descriptions:
        errors.append(f"description_too_long_count={len(long_descriptions)}")
    if len(ad.get("headlines", [])) < 8:
        errors.append("rsa_needs_at_least_8_headlines")
    if len(ad.get("descriptions", [])) < 3:
        errors.append("rsa_needs_at_least_3_descriptions")

    if errors:
        print("NOT_READY")
        for error in errors:
            print(error)
        return 1

    print("READY_FOR_REAL_GOOGLE_ADS_CREATE_PAUSED")
    print("campaign=" + str(campaign.get("name", "")))
    print("daily_budget_brl=" + str(campaign.get("daily_budget_brl", "")))
    print("max_cpc_brl=" + str(campaign.get("bidding", {}).get("max_cpc_brl", "")))
    print("keywords=" + str(len(keywords)))
    print("headlines=" + str(len(ad.get("headlines", []))))
    print("descriptions=" + str(len(ad.get("descriptions", []))))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
