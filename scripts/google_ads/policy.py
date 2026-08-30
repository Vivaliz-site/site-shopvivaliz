from __future__ import annotations

from typing import Any


CLASSIFICATIONS = {"APPLY", "TEST", "REVIEW", "REJECT"}

ASSET_TYPES = {
    "RESPONSIVE_SEARCH_AD",
    "RESPONSIVE_SEARCH_AD_IMPROVE_AD_STRENGTH",
    "RESPONSIVE_SEARCH_AD_ASSET",
}
KEYWORD_TYPES = {"KEYWORD", "KEYWORD_MATCH_TYPE"}
BROAD_MATCH_TYPES = {"USE_BROAD_MATCH_KEYWORD", "BROAD_MATCH_KEYWORD"}
BUDGET_TYPES = {"CAMPAIGN_BUDGET", "FORECASTING_CAMPAIGN_BUDGET"}
BIDDING_TYPES = {
    "MAXIMIZE_CONVERSIONS_OPT_IN",
    "MAXIMIZE_CONVERSION_VALUE_OPT_IN",
    "TARGET_CPA_OPT_IN",
    "TARGET_ROAS_OPT_IN",
}
IMAGE_TYPES = {
    "DYNAMIC_IMAGE_EXTENSION_OPT_IN",
    "IMAGE_EXTENSION",
    "RESPONSIVE_SEARCH_AD_ADD_ASSETS",
}
PERFORMANCE_MAX_TYPES = {"PERFORMANCE_MAX_OPT_IN", "UPGRADE_SMART_SHOPPING_CAMPAIGN_TO_PERFORMANCE_MAX"}


def _economics_configured(config: dict[str, Any]) -> bool:
    return any(
        config.get(key) is not None
        for key in ("target_cpa_brl", "minimum_roas", "gross_margin_pct")
    )


def _evidence_summary(evidence: dict[str, Any]) -> dict[str, Any]:
    allowed = (
        "tracking_health",
        "recent_conversions",
        "negative_keyword_protection",
        "catalog_intent_match",
        "keyword_overlap",
        "content_quality_verified",
        "destination_relevance_verified",
        "reversible",
    )
    return {key: evidence[key] for key in allowed if key in evidence}


def classify_recommendation(
    recommendation: dict[str, Any],
    evidence: dict[str, Any],
    config: dict[str, Any],
) -> dict[str, Any]:
    recommendation_type = str(recommendation.get("type") or "UNKNOWN").upper()
    classification = "REVIEW"
    reason_codes: list[str] = []
    blocked_by: list[str] = []

    recent_conversions = float(evidence.get("recent_conversions") or 0)
    minimum_conversions = float(config.get("min_recent_conversions_for_scaling") or 15)
    tracking_healthy = evidence.get("tracking_health") == "healthy"

    if recommendation_type in ASSET_TYPES:
        if evidence.get("content_quality_verified") and evidence.get("reversible"):
            classification = "APPLY"
            reason_codes.append("low_risk_asset_improvement_verified")
        else:
            classification = "TEST"
            reason_codes.append("asset_quality_requires_bounded_validation")

    elif recommendation_type in KEYWORD_TYPES:
        if evidence.get("catalog_intent_match") and not evidence.get("keyword_overlap"):
            classification = "TEST"
            reason_codes.append("catalog_intent_supported_keyword_candidate")
        else:
            classification = "REVIEW"
            blocked_by.append("keyword_intent_or_overlap_unverified")

    elif recommendation_type in BROAD_MATCH_TYPES:
        if not tracking_healthy:
            blocked_by.append("tracking_not_healthy")
        if recent_conversions < minimum_conversions:
            blocked_by.append("insufficient_recent_conversions")
        if not evidence.get("negative_keyword_protection"):
            blocked_by.append("negative_keyword_protection_missing")
        if blocked_by:
            classification = "REVIEW"
            reason_codes.append("broad_match_guardrails_not_met")
        else:
            classification = "TEST"
            reason_codes.append("broad_match_requires_controlled_experiment")

    elif recommendation_type in BUDGET_TYPES:
        proposed = recommendation.get("proposed_increase_pct")
        cap = float(config.get("max_budget_increase_pct") or 0.10)
        if not tracking_healthy:
            blocked_by.append("tracking_not_healthy")
        if recent_conversions < minimum_conversions:
            blocked_by.append("insufficient_recent_conversions")
        if proposed is None:
            blocked_by.append("budget_change_pct_unknown")
        elif float(proposed) > cap:
            blocked_by.append("budget_change_exceeds_cap")
        if not _economics_configured(config):
            blocked_by.append("business_thresholds_missing")
        classification = "REVIEW" if blocked_by else "TEST"
        reason_codes.append("budget_change_requires_profitability_review")

    elif recommendation_type in BIDDING_TYPES:
        if not tracking_healthy:
            blocked_by.append("tracking_not_healthy")
        if recent_conversions < minimum_conversions:
            blocked_by.append("insufficient_recent_conversions")
        if not _economics_configured(config):
            blocked_by.append("business_thresholds_missing")
        if blocked_by:
            classification = "REVIEW"
            reason_codes.append("bidding_guardrails_not_met")
        else:
            classification = "TEST"
            reason_codes.append("bidding_migration_requires_experiment")

    elif recommendation_type in IMAGE_TYPES:
        if evidence.get("destination_relevance_verified") and evidence.get("content_quality_verified"):
            classification = "TEST"
            reason_codes.append("image_asset_requires_bounded_validation")
        else:
            classification = "REVIEW"
            blocked_by.append("image_relevance_unverified")

    elif recommendation_type in PERFORMANCE_MAX_TYPES:
        classification = "REVIEW"
        reason_codes.append("performance_max_requires_later_approved_plan")
        blocked_by.append("phase_one_excluded")

    else:
        classification = "REVIEW"
        reason_codes.append("unknown_recommendation_type")
        blocked_by.append("explicit_handler_missing")

    if classification not in CLASSIFICATIONS:
        raise AssertionError("invalid policy classification")
    return {
        "classification": classification,
        "reason_codes": reason_codes,
        "blocked_by": list(dict.fromkeys(blocked_by)),
        "recommendation_type": recommendation_type,
        "resource_name": str(recommendation.get("resource_name") or ""),
        "evidence": _evidence_summary(evidence),
    }
