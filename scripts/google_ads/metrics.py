from __future__ import annotations

from collections.abc import Iterable
from typing import Any


def _number(value: Any) -> float:
    if value in (None, ""):
        return 0.0
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


def safe_div(numerator: Any, denominator: Any) -> float | None:
    denominator_value = _number(denominator)
    if denominator_value == 0.0:
        return None
    return _number(numerator) / denominator_value


def trend(current: float | None, baseline: float | None) -> float | None:
    if current is None or baseline in (None, 0, 0.0):
        return None
    return (float(current) - float(baseline)) / float(baseline)


def derive_metrics(row: dict[str, Any]) -> dict[str, float | None]:
    clicks = _number(row.get("clicks"))
    impressions = _number(row.get("impressions"))
    cost = _number(row.get("cost"))
    conversions = _number(row.get("conversions"))
    conversion_value = _number(row.get("conversion_value"))
    return {
        "ctr": safe_div(clicks, impressions),
        "cpc": safe_div(cost, clicks),
        "cpa": safe_div(cost, conversions),
        "roas": safe_div(conversion_value, cost),
        "conversion_rate": safe_div(conversions, clicks),
    }


def _records(value: Any) -> Iterable[dict[str, Any]]:
    if isinstance(value, list):
        for item in value:
            if isinstance(item, dict):
                yield item
    elif isinstance(value, dict):
        for item in value.values():
            yield from _records(item)


def tracking_health(
    conversion_actions: list[dict[str, Any]],
    windows: dict[str, Any],
) -> dict[str, Any]:
    purchase_actions: list[dict[str, Any]] = []
    for raw in conversion_actions:
        action = raw.get("conversion_action") or raw.get("conversionAction") or raw
        category = str(action.get("category", "")).upper()
        status = str(action.get("status", "")).upper()
        included = action.get(
            "include_in_conversions_metric",
            action.get("includeInConversionsMetric", True),
        )
        primary = action.get("primary_for_goal", action.get("primaryForGoal", True))
        if (
            category in {"PURCHASE", "SALE"}
            and status == "ENABLED"
            and included is not False
            and primary is not False
        ):
            purchase_actions.append(action)

    reasons: list[str] = []
    if not purchase_actions:
        reasons.append("purchase_conversion_action_missing")
        return {"status": "unknown", "reasons": reasons, "purchase_actions": 0}

    if len(purchase_actions) > 1:
        reasons.append("multiple_primary_purchase_actions")
        return {
            "status": "unhealthy",
            "reasons": reasons,
            "purchase_actions": len(purchase_actions),
        }

    recent = windows.get("7d", [])
    conversions = 0.0
    conversion_value = 0.0
    for record in _records(recent):
        metrics = record.get("metrics") if isinstance(record.get("metrics"), dict) else record
        conversions += _number(metrics.get("conversions"))
        conversion_value += _number(
            metrics.get("conversion_value", metrics.get("conversionsValue"))
        )
    if conversions <= 0:
        reasons.append("recent_purchase_conversions_missing")
    elif conversion_value <= 0:
        reasons.append("recent_conversion_value_missing")

    return {
        "status": "healthy" if not reasons else "unknown",
        "reasons": reasons,
        "purchase_actions": len(purchase_actions),
        "recent_conversions": conversions,
        "recent_conversion_value": conversion_value,
    }
