#!/usr/bin/env python3
"""Static lint for Google Ads campaign config without credentials or API calls.

This catches structural/relevance regressions in CI before a real readiness
check is allowed to touch Google Ads.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "scripts" / "google_ads_campaign_live_ready.json"
ALLOWED_HOST = "shopvivaliz.com.br"


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


def main() -> int:
    cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    errors: list[str] = []

    campaign = cfg.get("campaign", {})
    guardrails = cfg.get("guardrails", {})
    groups = cfg.get("ad_groups", [])

    if campaign.get("channel") != "SEARCH":
        errors.append("campaign_channel_must_be_search")
    if campaign.get("status_on_create") != "PAUSED":
        errors.append("campaign_must_create_paused")
    if float(campaign.get("daily_budget_brl", 0)) <= 0:
        errors.append("daily_budget_must_be_positive")
    if float(campaign.get("daily_budget_brl", 0)) > float(guardrails.get("max_daily_budget_brl", 0)):
        errors.append("daily_budget_exceeds_guardrail")
    if float(campaign.get("bidding", {}).get("max_cpc_brl", 0)) > float(guardrails.get("max_cpc_brl", 0)):
        errors.append("campaign_cpc_exceeds_guardrail")

    if len(groups) < 2:
        errors.append("need_at_least_two_focused_ad_groups")
    names = [str(group.get("name", "")) for group in groups]
    if duplicates(names):
        errors.append("duplicate_ad_group_names")

    all_keyword_pairs: list[str] = []
    all_keyword_texts: list[str] = []
    for group in groups:
        name = str(group.get("name", "")).strip() or "unnamed"
        prefix = f"group[{name}]"
        keywords = group.get("keywords", [])
        if len(keywords) < 4:
            errors.append(f"{prefix}:too_few_keywords")
        if float(group.get("default_cpc_brl", 0)) <= 0:
            errors.append(f"{prefix}:default_cpc_invalid")
        if float(group.get("default_cpc_brl", 0)) > float(guardrails.get("max_cpc_brl", 0)):
            errors.append(f"{prefix}:default_cpc_exceeds_guardrail")

        pairs = []
        positive_texts = []
        for item in keywords:
            text = norm(item.get("text", ""))
            match = str(item.get("match_type", "")).upper()
            cpc = float(item.get("cpc_brl", 0))
            if not text:
                errors.append(f"{prefix}:empty_keyword")
            if match not in {"EXACT", "PHRASE"}:
                errors.append(f"{prefix}:invalid_match_type={match}")
            if cpc <= 0 or cpc > float(guardrails.get("max_cpc_brl", 0)):
                errors.append(f"{prefix}:keyword_cpc_outside_guardrail={text}")
            pairs.append(f"{text}|{match}")
            positive_texts.append(text)
            all_keyword_pairs.append(f"{text}|{match}")
            all_keyword_texts.append(text)
        if duplicates(pairs):
            errors.append(f"{prefix}:duplicate_keyword_pair")

        negatives = [norm(value) for value in group.get("negative_keywords", [])]
        if set(negatives).intersection(positive_texts):
            errors.append(f"{prefix}:negative_conflicts_with_positive")

        ad = group.get("responsive_search_ad", {})
        headlines = [str(value) for value in ad.get("headlines", [])]
        descriptions = [str(value) for value in ad.get("descriptions", [])]
        if len(headlines) != 15:
            errors.append(f"{prefix}:expected_15_headlines={len(headlines)}")
        if len(descriptions) != 4:
            errors.append(f"{prefix}:expected_4_descriptions={len(descriptions)}")
        if duplicates(headlines):
            errors.append(f"{prefix}:duplicate_headlines")
        if duplicates(descriptions):
            errors.append(f"{prefix}:duplicate_descriptions")
        for headline in headlines:
            if len(headline) > 30:
                errors.append(f"{prefix}:headline_too_long={len(headline)}:{headline}")
        for description in descriptions:
            if len(description) > 90:
                errors.append(f"{prefix}:description_too_long={len(description)}:{description}")

        final_url = str(ad.get("final_url", ""))
        split = urlsplit(final_url)
        if split.scheme != "https" or split.hostname != ALLOWED_HOST:
            errors.append(f"{prefix}:invalid_final_url")
        decoded_url = norm(unquote(final_url))
        if "carrinho" in norm(name) and "carrinho" not in decoded_url:
            errors.append(f"{prefix}:carrinho_group_url_not_specific")
        if "caixa" in norm(name) and "caixa" not in decoded_url:
            errors.append(f"{prefix}:caixa_group_url_not_specific")

        if not norm(group.get("tracking_content", "")):
            errors.append(f"{prefix}:tracking_content_missing")

    if duplicates(all_keyword_pairs):
        errors.append("duplicate_keyword_pair_across_groups")
    if duplicates(all_keyword_texts):
        errors.append("same_keyword_text_reused_across_groups")

    campaign_negatives = [norm(value) for value in cfg.get("negative_keywords", [])]
    if set(campaign_negatives).intersection(all_keyword_texts):
        errors.append("campaign_negative_conflicts_with_positive")

    if float(guardrails.get("target_roi", 0)) < 10:
        errors.append("target_roi_below_10")
    if not guardrails.get("create_paused_first"):
        errors.append("create_paused_first_guardrail_required")
    if not guardrails.get("require_human_review_before_enable"):
        errors.append("human_review_guardrail_required")

    if errors:
        print("GOOGLE_ADS_CONFIG_NOT_READY")
        for error in errors:
            print(f"ERROR={error}")
        return 1

    print("GOOGLE_ADS_CONFIG_STATIC_READY")
    print(f"campaign={campaign.get('name', '')}")
    print(f"ad_groups={len(groups)}")
    print(f"keywords={len(all_keyword_texts)}")
    print(f"headlines={sum(len(group.get('responsive_search_ad', {}).get('headlines', [])) for group in groups)}")
    print(f"descriptions={sum(len(group.get('responsive_search_ad', {}).get('descriptions', [])) for group in groups)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
