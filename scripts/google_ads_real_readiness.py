#!/usr/bin/env python3
"""Fail-closed readiness check for the reviewed Google Ads Search campaign."""

from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from urllib.parse import urlsplit

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
ALLOWED_HOST = "shopvivaliz.com.br"


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


def norm(value: str) -> str:
    return " ".join(str(value).strip().casefold().split())


def duplicates(values: list[str]) -> list[str]:
    seen: set[str] = set()
    dupes: list[str] = []
    for value in values:
        key = norm(value)
        if key in seen and key not in dupes:
            dupes.append(key)
        seen.add(key)
    return dupes


def validate_ad_group(group: dict, guardrails: dict) -> list[str]:
    errors: list[str] = []
    name = str(group.get("name", "")).strip() or "unnamed"
    prefix = f"ad_group[{name}]"

    default_cpc = float(group.get("default_cpc_brl", 0))
    max_cpc_guardrail = float(guardrails.get("max_cpc_brl", 0))
    if default_cpc <= 0:
        errors.append(f"{prefix}:default_cpc_must_be_positive")
    if default_cpc > max_cpc_guardrail:
        errors.append(f"{prefix}:default_cpc_exceeds_guardrail")

    keywords = group.get("keywords", [])
    if len(keywords) < 4:
        errors.append(f"{prefix}:needs_at_least_4_keywords")
    if any(str(item.get("match_type", "")).upper() not in {"EXACT", "PHRASE"} for item in keywords):
        errors.append(f"{prefix}:only_exact_and_phrase_match_allowed")
    keyword_keys = [
        f"{norm(item.get('text', ''))}|{str(item.get('match_type', '')).upper()}"
        for item in keywords
    ]
    if duplicates(keyword_keys):
        errors.append(f"{prefix}:duplicate_keyword_match_pairs")
    if any(float(item.get("cpc_brl", 0)) <= 0 for item in keywords):
        errors.append(f"{prefix}:keyword_cpc_must_be_positive")
    if any(float(item.get("cpc_brl", 0)) > max_cpc_guardrail for item in keywords):
        errors.append(f"{prefix}:keyword_cpc_exceeds_guardrail")

    group_negatives = [norm(value) for value in group.get("negative_keywords", [])]
    positive_texts = [norm(item.get("text", "")) for item in keywords]
    if set(group_negatives).intersection(positive_texts):
        errors.append(f"{prefix}:direct_negative_keyword_conflict")

    ad = group.get("responsive_search_ad", {})
    headlines = ad.get("headlines", [])
    descriptions = ad.get("descriptions", [])
    if len(headlines) < 12:
        errors.append(f"{prefix}:rsa_needs_at_least_12_headlines")
    if len(descriptions) < 4:
        errors.append(f"{prefix}:rsa_needs_4_descriptions")
    if any(len(text) > 30 for text in headlines):
        errors.append(f"{prefix}:headline_too_long")
    if any(len(text) > 90 for text in descriptions):
        errors.append(f"{prefix}:description_too_long")
    if duplicates(headlines):
        errors.append(f"{prefix}:duplicate_headlines")
    if duplicates(descriptions):
        errors.append(f"{prefix}:duplicate_descriptions")

    name_norm = norm(name)
    if "carrinho" in name_norm:
        relevance_tokens = ("carrinho", "fercar", "ferramentas")
        url_token = "carrinho"
    elif "caixa" in name_norm:
        relevance_tokens = ("caixa", "fercar", "ferramentas")
        url_token = "caixa"
    else:
        relevance_tokens = ("fercar", "ferramentas")
        url_token = "fercar"

    relevant_headlines = [
        text for text in headlines
        if any(token in norm(text) for token in relevance_tokens)
    ]
    if len(relevant_headlines) < 7:
        errors.append(f"{prefix}:needs_more_keyword_relevant_headlines")
    if sum("comprar" in norm(text) or "compre" in norm(text) for text in headlines) < 2:
        errors.append(f"{prefix}:needs_more_purchase_intent_headlines")
    if sum(any(token in norm(text) for token in relevance_tokens) for text in descriptions) < 3:
        errors.append(f"{prefix}:needs_more_relevant_descriptions")

    final_url = str(ad.get("final_url", "")).strip()
    split = urlsplit(final_url)
    if split.scheme != "https" or split.hostname != ALLOWED_HOST:
        errors.append(f"{prefix}:final_url_must_use_shopvivaliz_https")
    if url_token not in norm(final_url.replace("%20", " ")):
        errors.append(f"{prefix}:final_url_not_specific_to_group")

    tracking_content = norm(group.get("tracking_content", ""))
    if not tracking_content:
        errors.append(f"{prefix}:tracking_content_missing")

    return errors


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
        roi10_cpc = (target_aov * target_conversion_rate) / target_roi
        if max_cpc > roi10_cpc:
            errors.append(f"max_cpc_above_roi10_math=max_cpc:{max_cpc:.2f},roi10_cpc:{roi10_cpc:.2f}")

    ad_groups = config.get("ad_groups", [])
    if len(ad_groups) < 2:
        errors.append("needs_at_least_2_focused_ad_groups")
    group_names = [str(group.get("name", "")) for group in ad_groups]
    if duplicates(group_names):
        errors.append("duplicate_ad_group_names")

    all_keyword_texts: list[str] = []
    for group in ad_groups:
        errors.extend(validate_ad_group(group, guardrails))
        all_keyword_texts.extend(norm(item.get("text", "")) for item in group.get("keywords", []))
    if duplicates(all_keyword_texts):
        errors.append("same_positive_keyword_used_across_ad_groups")

    campaign_negatives = [norm(value) for value in config.get("negative_keywords", [])]
    if set(campaign_negatives).intersection(all_keyword_texts):
        errors.append("campaign_negative_conflicts_with_positive_keyword")

    if errors:
        print("NOT_READY")
        for error in errors:
            print(error)
        return 1

    print("READY_FOR_REAL_GOOGLE_ADS_CREATE_PAUSED")
    print("campaign=" + str(campaign.get("name", "")))
    print("daily_budget_brl=" + str(campaign.get("daily_budget_brl", "")))
    print("max_cpc_brl=" + str(campaign.get("bidding", {}).get("max_cpc_brl", "")))
    print("ad_groups=" + str(len(ad_groups)))
    print("keywords=" + str(len(all_keyword_texts)))
    for group in ad_groups:
        print(
            "group=" + str(group.get("name", ""))
            + " keywords=" + str(len(group.get("keywords", [])))
            + " headlines=" + str(len(group.get("responsive_search_ad", {}).get("headlines", [])))
            + " descriptions=" + str(len(group.get("responsive_search_ad", {}).get("descriptions", [])))
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
