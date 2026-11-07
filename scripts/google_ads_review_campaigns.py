#!/usr/bin/env python3
"""Read-only Google Ads campaign review with sanitized auth diagnostics.

This script performs no mutations. It lists recent campaign delivery and
configuration signals so the account can be reviewed without opening the UI.
"""

from __future__ import annotations

import os
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
REQUIRED_ENV = [
    "GOOGLE_OAUTH_CLIENT_ID",
    "GOOGLE_OAUTH_CLIENT_SECRET",
    "GOOGLE_ADS_CUSTOMER_ID",
    "GOOGLE_ADS_DEVELOPER_TOKEN",
    "GOOGLE_ADS_REFRESH_TOKEN",
]


def load_dotenv(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


def is_missing_or_placeholder(value: str) -> bool:
    lowered = value.strip().lower()
    return (
        lowered == ""
        or lowered in {"placeholder", "changeme", "xxx"}
        or lowered.startswith(("obter", "your_"))
        or "developer_token" in lowered
    )


def classify_auth_error(exc: Exception) -> str:
    text = str(exc).casefold()
    if "invalid_client" in text or "oauth client was not found" in text:
        return "OAUTH_CLIENT_INVALID_OR_DELETED"
    if "invalid_grant" in text:
        return "OAUTH_REFRESH_TOKEN_INVALID_OR_REVOKED"
    if "developer_token" in text and ("not approved" in text or "unauthorized" in text):
        return "DEVELOPER_TOKEN_ACCESS_NOT_APPROVED"
    if "authentication" in text or "unauthenticated" in text:
        return "GOOGLE_ADS_AUTHENTICATION_FAILED"
    return "GOOGLE_ADS_CLIENT_INIT_FAILED"


def build_client():
    from google.ads.googleads.client import GoogleAdsClient

    config = {
        "developer_token": os.environ["GOOGLE_ADS_DEVELOPER_TOKEN"],
        "client_id": os.environ["GOOGLE_OAUTH_CLIENT_ID"],
        "client_secret": os.environ["GOOGLE_OAUTH_CLIENT_SECRET"],
        "refresh_token": os.environ["GOOGLE_ADS_REFRESH_TOKEN"],
        "use_proto_plus": True,
    }
    login_customer_id = os.getenv("GOOGLE_ADS_LOGIN_CUSTOMER_ID", "").replace("-", "").strip()
    if login_customer_id:
        config["login_customer_id"] = login_customer_id
    return GoogleAdsClient.load_from_dict(config)


def zero_delivery_classification(status: str, impressions: int, keyword_states: list[str]) -> dict | None:
    """Read-only evidence signal, not a causal diagnosis or a bid recommendation.

    A serving/eligible campaign can still record zero impressions; never
    equate enabled status with traffic, and never assume the CPC cap is causal.
    """
    if status != "ENABLED" or impressions > 0:
        return None
    states = [state.upper() for state in keyword_states]
    return {
        "status": "ZERO_IMPRESSIONS_REVIEW_ONLY",
        "enabled_keywords": len(states),
        "keyword_eligible": states.count("ELIGIBLE"),
        "keyword_not_eligible": states.count("NOT_ELIGIBLE"),
        "keyword_unknown": states.count("UNKNOWN"),
        "cause_certified": False,
        "commercial_mutation_authorized": False,
    }


def inspect_zero_delivery(service, customer_id: str, campaign_id: int, status: str, impressions: int) -> dict | None:
    """Use only official GAQL SELECT queries with bounded responses."""
    if status != "ENABLED" or impressions > 0:
        return None
    keyword_query = (
        "SELECT ad_group_criterion.primary_status,ad_group_criterion.primary_status_reasons "
        "FROM keyword_view "
        f"WHERE campaign.id = {int(campaign_id)} "
        "AND ad_group_criterion.status = ENABLED LIMIT 100"
    )
    states = []
    reason_counts = {}
    for row in service.search(customer_id=customer_id, query=keyword_query):
        states.append(row.ad_group_criterion.primary_status.name)
        for reason in row.ad_group_criterion.primary_status_reasons:
            label = reason.name
            reason_counts[label] = reason_counts.get(label, 0) + 1
    output = zero_delivery_classification(status, impressions, states)
    output["keyword_reason_rarely_served"] = reason_counts.get("AD_GROUP_CRITERION_RARELY_SERVED", 0)
    output["keyword_reason_low_quality"] = reason_counts.get("AD_GROUP_CRITERION_LOW_QUALITY", 0)
    campaign_query = (
        "SELECT campaign.serving_status,campaign.primary_status,campaign.bidding_strategy_type,"
        "campaign.target_spend.cpc_bid_ceiling_micros "
        f"FROM campaign WHERE campaign.id = {int(campaign_id)}"
    )
    for row in service.search(customer_id=customer_id, query=campaign_query):
        output["serving_status"] = row.campaign.serving_status.name
        output["primary_status"] = row.campaign.primary_status.name
        output["bidding_strategy"] = row.campaign.bidding_strategy_type.name
        if row.campaign.bidding_strategy_type.name == "TARGET_SPEND":
            output["cpc_ceiling_brl"] = round(row.campaign.target_spend.cpc_bid_ceiling_micros / 1_000_000, 2)
        break
    return output


def main() -> int:
    load_dotenv(ROOT / ".env")
    missing = [key for key in REQUIRED_ENV if is_missing_or_placeholder(os.getenv(key, ""))]
    if missing:
        print("NOT_READY")
        print("missing_or_placeholder_env=" + ",".join(missing))
        return 1

    try:
        client = build_client()
    except Exception as exc:
        print("AUTH_NOT_READY")
        print("reason=" + classify_auth_error(exc))
        print("action=repair OAuth client/refresh token in the secure secret store, then rerun")
        return 1

    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    service = client.get_service("GoogleAdsService")
    query = """
        SELECT
          campaign.id,
          campaign.name,
          campaign.status,
          campaign.advertising_channel_type,
          campaign_budget.amount_micros,
          metrics.impressions,
          metrics.clicks,
          metrics.cost_micros,
          metrics.conversions,
          metrics.conversions_value
        FROM campaign
        WHERE campaign.status != 'REMOVED'
        AND segments.date DURING LAST_30_DAYS
        ORDER BY metrics.cost_micros DESC
        LIMIT 50
    """

    try:
        rows = service.search(customer_id=customer_id, query=query)
        print("READ_ONLY_REVIEW")
        flagged_campaigns = 0
        for row in rows:
            budget_brl = row.campaign_budget.amount_micros / 1_000_000
            cost_brl = row.metrics.cost_micros / 1_000_000
            if row.campaign.status.name == "ENABLED" and row.metrics.impressions == 0:
                flagged_campaigns += 1
                try:
                    detail = inspect_zero_delivery(
                        service, customer_id, int(row.campaign.id),
                        row.campaign.status.name, row.metrics.impressions,
                    )
                    print("delivery_diagnostic=" + __import__("json").dumps(
                        {"campaign_id": int(row.campaign.id), **detail},
                        ensure_ascii=True, sort_keys=True,
                    ))
                except Exception as exc:
                    print("delivery_diagnostic=INCOMPLETE")
                    print("delivery_diagnostic_error=" + type(exc).__name__)
            print(
                "campaign="
                + str(row.campaign.id)
                + "\tname="
                + row.campaign.name
                + "\tstatus="
                + row.campaign.status.name
                + "\tchannel="
                + row.campaign.advertising_channel_type.name
                + f"\tbudget_brl={budget_brl:.2f}"
                + f"\timpressions={row.metrics.impressions}"
                + f"\tclicks={row.metrics.clicks}"
                + f"\tcost_brl={cost_brl:.2f}"
                + f"\tconversions={row.metrics.conversions:.2f}"
                + f"\tconversion_value={row.metrics.conversions_value:.2f}"
            )
        if flagged_campaigns:
            print(f"zero_impression_enabled_campaigns={flagged_campaigns}")
    except Exception as exc:
        print("API_REVIEW_FAILED")
        print("reason=" + classify_auth_error(exc))
        print("exception_type=" + type(exc).__name__)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
