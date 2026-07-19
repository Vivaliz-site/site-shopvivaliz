#!/usr/bin/env python3
"""Read-only Google Ads campaign review.

This script performs no mutations. It lists recent campaign delivery and
configuration signals so the account can be reviewed without opening the UI.
"""

from __future__ import annotations

import os
import sys
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


def main() -> int:
    load_dotenv(ROOT / ".env")
    missing = [key for key in REQUIRED_ENV if is_missing_or_placeholder(os.getenv(key, ""))]
    if missing:
        print("NOT_READY")
        print("missing_or_placeholder_env=" + ",".join(missing))
        return 1

    client = build_client()
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
        for row in rows:
            budget_brl = row.campaign_budget.amount_micros / 1_000_000
            cost_brl = row.metrics.cost_micros / 1_000_000
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
    except Exception as exc:
        print("API_REVIEW_FAILED")
        print(type(exc).__name__ + ": " + str(exc))
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
