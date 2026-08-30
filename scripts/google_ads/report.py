from __future__ import annotations

from collections import Counter
from datetime import datetime, timezone
from typing import Any

from scripts.google_ads.metrics import derive_metrics, tracking_health
from scripts.google_ads.policy import classify_recommendation


FORBIDDEN_KEY_PARTS = (
    "access_token",
    "refresh_token",
    "client_secret",
    "developer_token",
    "authorization",
    "private_key",
    "ssh_key",
)


def sanitize(value: Any, secret_values: tuple[str, ...] = ()) -> Any:
    secrets = tuple(secret for secret in secret_values if secret)
    if isinstance(value, dict):
        cleaned: dict[str, Any] = {}
        for key, item in value.items():
            normalized_key = str(key).lower()
            if any(part in normalized_key for part in FORBIDDEN_KEY_PARTS):
                continue
            cleaned[str(key)] = sanitize(item, secrets)
        return cleaned
    if isinstance(value, list):
        return [sanitize(item, secrets) for item in value]
    if isinstance(value, tuple):
        return [sanitize(item, secrets) for item in value]
    if isinstance(value, str):
        cleaned = value
        for secret in secrets:
            cleaned = cleaned.replace(secret, "[REDACTED]")
        return cleaned
    return value


def _masked_customer(customer: dict[str, Any]) -> dict[str, Any]:
    customer_id = str(customer.get("id") or "")
    masked = "*" * max(0, len(customer_id) - 4) + customer_id[-4:]
    return {
        "id": masked,
        "descriptive_name": str(customer.get("descriptive_name") or ""),
    }


def _enriched_windows(windows: dict[str, Any]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for window, datasets in windows.items():
        result[window] = {}
        for dataset, records in datasets.items():
            result[window][dataset] = []
            for record in records:
                enriched = dict(record)
                enriched["derived_metrics"] = derive_metrics(record)
                result[window][dataset].append(enriched)
    return result


def _term_text(row: dict[str, Any]) -> str:
    view = row.get("search_term_view")
    if isinstance(view, dict):
        return str(view.get("search_term") or "")
    return str(row.get("search_term") or "")


def _findings(windows: dict[str, Any], config: dict[str, Any]) -> list[dict[str, Any]]:
    findings: list[dict[str, Any]] = []
    protected = tuple(str(term).casefold() for term in config.get("protected_terms") or [])
    minimum_clicks = float(config.get("min_search_term_clicks_for_negative_candidate") or 20)
    minimum_spend = float(config.get("min_search_term_spend_brl_for_negative_candidate") or 30)
    search_terms = windows.get("30d", {}).get("search_terms", [])
    for row in search_terms:
        term = _term_text(row)
        conversions = float(row.get("conversions") or 0)
        conversion_value = float(row.get("conversion_value") or 0)
        clicks = float(row.get("clicks") or 0)
        cost = float(row.get("cost") or 0)
        protected_term = any(item and item in term.casefold() for item in protected)
        if (
            conversions <= 0
            and conversion_value <= 0
            and clicks >= minimum_clicks
            and cost >= minimum_spend
            and not protected_term
        ):
            findings.append(
                {
                    "type": "search_term_waste_candidate",
                    "classification": "REVIEW",
                    "search_term": term,
                    "evidence": {"window": "30d", "clicks": clicks, "cost_brl": cost},
                    "blocked_by": [
                        "catalog_intent_not_reconciled",
                        "negative_scope_not_verified",
                    ],
                }
            )
        if conversions > 0 or conversion_value > 0:
            findings.append(
                {
                    "type": "search_term_growth_evidence",
                    "classification": "TEST",
                    "search_term": term,
                    "evidence": {
                        "window": "30d",
                        "conversions": conversions,
                        "conversion_value": conversion_value,
                    },
                    "blocked_by": ["catalog_and_keyword_overlap_review_required"],
                }
            )

    for row in windows.get("30d", {}).get("campaigns", []):
        if float(row.get("cost") or 0) >= minimum_spend and float(row.get("conversions") or 0) <= 0:
            campaign = row.get("campaign") if isinstance(row.get("campaign"), dict) else {}
            findings.append(
                {
                    "type": "campaign_spend_without_conversion",
                    "classification": "REVIEW",
                    "resource_id": str(campaign.get("id") or ""),
                    "evidence": {"window": "30d", "cost_brl": float(row.get("cost") or 0)},
                    "blocked_by": ["tracking_and_business_context_review_required"],
                }
            )
    return findings


def build_report(collected: dict[str, Any], config: dict[str, Any]) -> dict[str, Any]:
    windows = collected.get("windows") or {}
    health = tracking_health(
        collected.get("conversion_actions") or [],
        {"7d": windows.get("7d", {}).get("campaigns", [])},
    )
    recent_conversions = sum(
        float(row.get("conversions") or 0)
        for row in windows.get("7d", {}).get("campaigns", [])
    )
    evidence = {
        "tracking_health": health["status"],
        "recent_conversions": recent_conversions,
        "negative_keyword_protection": False,
        "catalog_intent_match": False,
        "keyword_overlap": None,
        "content_quality_verified": False,
        "destination_relevance_verified": False,
        "reversible": False,
    }
    recommendations = collected.get("recommendations") or []
    decisions = [
        classify_recommendation(recommendation, evidence, config)
        for recommendation in recommendations
    ]
    recommendation_types = Counter(
        str(recommendation.get("type") or "UNKNOWN") for recommendation in recommendations
    )
    customer = collected.get("customer") or {}
    report = {
        "schema_version": 1,
        "mode": "readonly",
        "api_version": str(collected.get("api_version") or "v25"),
        "generated_at": str(
            collected.get("generated_at")
            or datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
        ),
        "customer": _masked_customer(customer),
        "optimization": {
            "score_available": bool(customer.get("score_available")),
            "score": customer.get("optimization_score"),
            "recommendation_count": len(recommendations),
            "recommendation_types": dict(sorted(recommendation_types.items())),
        },
        "tracking_health": health,
        "windows": _enriched_windows(windows),
        "findings": _findings(windows, config),
        "decisions": decisions,
        "guardrails": sorted(
            {
                guardrail
                for decision in decisions
                for guardrail in decision.get("blocked_by", [])
            }
        ),
        "errors": collected.get("errors") or [],
        "partial": bool(collected.get("partial")),
    }
    return sanitize(report)
