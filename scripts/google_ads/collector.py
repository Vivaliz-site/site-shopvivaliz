from __future__ import annotations

import re
from datetime import datetime, timedelta, timezone
from typing import Any

from scripts.google_ads.client import GoogleAdsClient, GoogleAdsError


WINDOWS = {
    "1d": "YESTERDAY",
    "3d": "LAST_3_DAYS",
    "7d": "LAST_7_DAYS",
    "30d": "LAST_30_DAYS",
}

DATASETS = ("campaigns", "ad_groups", "keywords", "search_terms", "ads")


def _snake_key(value: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "_", value).lower()


def _normalize(value: Any) -> Any:
    if isinstance(value, dict):
        return {_snake_key(str(key)): _normalize(item) for key, item in value.items()}
    if isinstance(value, list):
        return [_normalize(item) for item in value]
    return value


def _number(value: Any) -> float:
    try:
        return float(value or 0)
    except (TypeError, ValueError):
        return 0.0


def _date_filter(preset: str) -> str:
    if preset != "LAST_3_DAYS":
        return f"segments.date DURING {preset}"
    yesterday = datetime.now(timezone.utc).date() - timedelta(days=1)
    start = yesterday - timedelta(days=2)
    return f"segments.date BETWEEN '{start.isoformat()}' AND '{yesterday.isoformat()}'"


def _metric_row(row: dict[str, Any]) -> dict[str, Any]:
    normalized = _normalize(row)
    metrics = normalized.get("metrics", {})
    if not isinstance(metrics, dict):
        metrics = {}
    normalized.update(
        {
            "impressions": _number(metrics.get("impressions")),
            "clicks": _number(metrics.get("clicks")),
            "cost": _number(metrics.get("cost_micros")) / 1_000_000,
            "conversions": _number(metrics.get("conversions")),
            "conversion_value": _number(metrics.get("conversions_value")),
        }
    )
    return normalized


def _customer(rows: list[dict[str, Any]], fallback_id: str) -> dict[str, Any]:
    raw = rows[0].get("customer", {}) if rows else {}
    score = raw.get("optimizationScore")
    return {
        "id": str(raw.get("id") or fallback_id),
        "descriptive_name": str(raw.get("descriptiveName") or ""),
        "optimization_score": _number(score) if score is not None else None,
        "score_available": score is not None,
    }


def _recommendations(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    for row in rows:
        raw = row.get("recommendation", {})
        if not isinstance(raw, dict):
            continue
        result.append(
            {
                "resource_name": str(raw.get("resourceName") or ""),
                "type": str(raw.get("type") or "UNKNOWN"),
                "campaign": str(raw.get("campaign") or ""),
                "ad_group": str(raw.get("adGroup") or ""),
                "impact": _normalize(raw.get("impact") or {}),
            }
        )
    return result


def _conversion_actions(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    for row in rows:
        raw = row.get("conversionAction") or row.get("conversion_action") or {}
        if isinstance(raw, dict):
            result.append(_normalize(raw))
    return result


CUSTOMER_QUERY = """
SELECT customer.id, customer.descriptive_name, customer.optimization_score
FROM customer
LIMIT 1
"""

RECOMMENDATIONS_QUERY = """
SELECT recommendation.resource_name, recommendation.type,
       recommendation.campaign, recommendation.ad_group, recommendation.impact
FROM recommendation
"""

CONVERSION_ACTIONS_QUERY = """
SELECT conversion_action.id, conversion_action.name, conversion_action.status,
       conversion_action.type, conversion_action.category,
       conversion_action.primary_for_goal,
       conversion_action.include_in_conversions_metric,
       conversion_action.counting_type,
       conversion_action.value_settings.default_value,
       conversion_action.value_settings.always_use_default_value
FROM conversion_action
WHERE conversion_action.status != 'REMOVED'
"""

WINDOW_QUERY_FIELDS = {
    "campaigns": """
SELECT campaign.id, campaign.name, campaign.status,
       campaign.advertising_channel_type, campaign.bidding_strategy_type,
       campaign_budget.id, campaign_budget.name, campaign_budget.amount_micros,
       metrics.impressions, metrics.clicks, metrics.cost_micros,
       metrics.conversions, metrics.conversions_value
FROM campaign
WHERE campaign.status != 'REMOVED' AND {date_filter}
ORDER BY metrics.cost_micros DESC
LIMIT 500
""",
    "ad_groups": """
SELECT campaign.id, ad_group.id, ad_group.name, ad_group.status, ad_group.type,
       metrics.impressions, metrics.clicks, metrics.cost_micros,
       metrics.conversions, metrics.conversions_value
FROM ad_group
WHERE ad_group.status != 'REMOVED' AND {date_filter}
ORDER BY metrics.cost_micros DESC
LIMIT 1000
""",
    "keywords": """
SELECT campaign.id, ad_group.id, ad_group_criterion.criterion_id,
       ad_group_criterion.status, ad_group_criterion.keyword.text,
       ad_group_criterion.keyword.match_type,
       metrics.impressions, metrics.clicks, metrics.cost_micros,
       metrics.conversions, metrics.conversions_value
FROM keyword_view
WHERE ad_group_criterion.status != 'REMOVED' AND {date_filter}
ORDER BY metrics.cost_micros DESC
LIMIT 2000
""",
    "search_terms": """
SELECT campaign.id, ad_group.id, search_term_view.search_term,
       search_term_view.status, segments.search_term_match_type,
       metrics.impressions, metrics.clicks, metrics.cost_micros,
       metrics.conversions, metrics.conversions_value
FROM search_term_view
WHERE {date_filter}
ORDER BY metrics.cost_micros DESC
LIMIT 2000
""",
    "ads": """
SELECT campaign.id, ad_group.id, ad_group_ad.ad.id, ad_group_ad.status,
       ad_group_ad.ad.type, ad_group_ad.ad.final_urls,
       metrics.impressions, metrics.clicks, metrics.cost_micros,
       metrics.conversions, metrics.conversions_value
FROM ad_group_ad
WHERE ad_group_ad.status != 'REMOVED' AND {date_filter}
ORDER BY metrics.cost_micros DESC
LIMIT 1000
""",
}


def collect_account(client: GoogleAdsClient) -> dict[str, Any]:
    output: dict[str, Any] = {
        "api_version": client.api_version,
        "customer": {
            "id": client.customer_id,
            "descriptive_name": "",
            "optimization_score": None,
            "score_available": False,
        },
        "recommendations": [],
        "conversion_actions": [],
        "windows": {
            window: {dataset: [] for dataset in DATASETS} for window in WINDOWS
        },
        "errors": [],
        "partial": False,
    }

    fixed_queries = (
        ("customer", CUSTOMER_QUERY, _customer),
        ("recommendations", RECOMMENDATIONS_QUERY, _recommendations),
        ("conversion_actions", CONVERSION_ACTIONS_QUERY, _conversion_actions),
    )
    for label, gaql, normalizer in fixed_queries:
        try:
            rows = client.query(label, gaql)
            if label == "customer":
                output[label] = normalizer(rows, client.customer_id)
            else:
                output[label] = normalizer(rows)
        except Exception as error:
            reason = error.status if isinstance(error, GoogleAdsError) else "QUERY_ERROR"
            output["partial"] = True
            output["errors"].append(
                {"dataset": label, "window": None, "status": "failed", "reason": reason}
            )

    for window, preset in WINDOWS.items():
        date_filter = _date_filter(preset)
        for dataset in DATASETS:
            label = f"{dataset}_{window}"
            gaql = WINDOW_QUERY_FIELDS[dataset].format(date_filter=date_filter)
            try:
                output["windows"][window][dataset] = [
                    _metric_row(row) for row in client.query(label, gaql)
                ]
            except Exception as error:
                reason = error.status if isinstance(error, GoogleAdsError) else "QUERY_ERROR"
                output["partial"] = True
                output["errors"].append(
                    {
                        "dataset": dataset,
                        "window": window,
                        "status": "failed",
                        "reason": reason,
                    }
                )
    return output
