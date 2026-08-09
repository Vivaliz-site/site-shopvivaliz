#!/usr/bin/env python3
"""Audit or apply the Google Ads dynamic-image recommendation.

Default mode is read-only. Applying requires BOTH --apply and the secure runtime
flag GOOGLE_ADS_ALLOW_DYNAMIC_IMAGE_APPLY=YES. The tool never changes budgets,
bids, keywords, targeting, or campaign status.
"""

from __future__ import annotations

import argparse
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


def missing_env() -> list[str]:
    return [key for key in REQUIRED_ENV if not os.getenv(key, "").strip()]


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


def find_recommendations(client, customer_id: str) -> list[str]:
    service = client.get_service("GoogleAdsService")
    query = """
        SELECT
          recommendation.resource_name,
          recommendation.campaign
        FROM recommendation
        WHERE recommendation.type = DYNAMIC_IMAGE_EXTENSION_OPT_IN
    """
    rows = service.search(customer_id=customer_id, query=query)
    resources: list[str] = []
    for row in rows:
        resources.append(row.recommendation.resource_name)
        campaign = row.recommendation.campaign or "account-level"
        print(
            "DYNAMIC_IMAGE_RECOMMENDATION"
            + "\tresource=" + row.recommendation.resource_name
            + "\ttarget=" + campaign
        )
    return resources


def apply_recommendations(client, customer_id: str, resources: list[str]) -> int:
    if not resources:
        print("NO_DYNAMIC_IMAGE_RECOMMENDATION_TO_APPLY")
        return 0

    operations = []
    for resource_name in resources:
        operation = client.get_type("ApplyRecommendationOperation")
        operation.resource_name = resource_name
        operations.append(operation)

    service = client.get_service("RecommendationService")
    response = service.apply_recommendation(
        customer_id=customer_id,
        operations=operations,
    )
    print("APPLIED_DYNAMIC_IMAGE_RECOMMENDATIONS=" + str(len(response.results)))
    return len(response.results)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Apply only DYNAMIC_IMAGE_EXTENSION_OPT_IN recommendations.",
    )
    args = parser.parse_args()

    load_dotenv(ROOT / ".env")
    missing = missing_env()
    if missing:
        print("NOT_READY")
        print("missing_env=" + ",".join(missing))
        return 1

    try:
        client = build_client()
    except Exception as exc:
        text = str(exc).casefold()
        if "invalid_client" in text or "oauth client was not found" in text:
            reason = "OAUTH_CLIENT_INVALID_OR_DELETED"
        elif "invalid_grant" in text:
            reason = "OAUTH_REFRESH_TOKEN_INVALID_OR_REVOKED"
        else:
            reason = "GOOGLE_ADS_CLIENT_INIT_FAILED"
        print("AUTH_NOT_READY")
        print("reason=" + reason)
        return 1

    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    try:
        resources = find_recommendations(client, customer_id)
    except Exception as exc:
        print("RECOMMENDATION_AUDIT_FAILED")
        print("exception_type=" + type(exc).__name__)
        return 1

    print("DYNAMIC_IMAGE_RECOMMENDATIONS_FOUND=" + str(len(resources)))
    if not args.apply:
        print("READ_ONLY_NO_CHANGES")
        return 0

    if os.getenv("GOOGLE_ADS_ALLOW_DYNAMIC_IMAGE_APPLY", "").strip().upper() != "YES":
        print("APPLY_BLOCKED")
        print("reason=GOOGLE_ADS_ALLOW_DYNAMIC_IMAGE_APPLY_must_equal_YES")
        return 2

    try:
        apply_recommendations(client, customer_id, resources)
    except Exception as exc:
        print("DYNAMIC_IMAGE_APPLY_FAILED")
        print("exception_type=" + type(exc).__name__)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
