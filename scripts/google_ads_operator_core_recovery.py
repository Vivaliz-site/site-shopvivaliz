#!/usr/bin/env python3
from __future__ import annotations

import os

import google_ads_antique_decore_status_v2 as monitor

EXPECTED_CAMPAIGN_ID = int(os.getenv("EXPECTED_CAMPAIGN_ID", "24144257692"))
CORE_MAX_CPC_MICROS = 400_000


def fail(message: str) -> None:
    raise SystemExit("RECOVERY_BLOCKED=" + message)


def main() -> int:
    required = [
        "GOOGLE_OAUTH_CLIENT_ID",
        "GOOGLE_OAUTH_CLIENT_SECRET",
        "GOOGLE_ADS_REFRESH_TOKEN",
        "GOOGLE_ADS_DEVELOPER_TOKEN",
        "GOOGLE_ADS_CUSTOMER_ID",
    ]
    missing = [key for key in required if not os.getenv(key, "").strip()]
    if missing:
        fail("MISSING_SECRETS:" + ",".join(missing))

    legacy = monitor.legacy
    client = legacy.client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")

    safe_name = legacy.CAMPAIGN_NAME.replace("'", "\\'")
    campaigns = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT campaign.id, campaign.resource_name, campaign.name, campaign.status,
                     campaign.campaign_budget, campaign_budget.amount_micros
              FROM campaign
              WHERE campaign.name = '{safe_name}'
                AND campaign.status != 'REMOVED'
            """,
        )
    )
    if len(campaigns) != 1:
        fail("CAMPAIGN_COUNT:" + str(len(campaigns)))
    campaign_row = campaigns[0]
    campaign = campaign_row.campaign
    if int(campaign.id) != EXPECTED_CAMPAIGN_ID:
        fail("CAMPAIGN_ID_MISMATCH")
    if int(campaign_row.campaign_budget.amount_micros) != monitor.EXPECTED_BUDGET_MICROS:
        fail("BUDGET_NOT_EXACTLY_BRL_10")

    groups = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT ad_group.id, ad_group.resource_name, ad_group.name,
                     ad_group.status, ad_group.cpc_bid_micros
              FROM ad_group
              WHERE campaign.id = {EXPECTED_CAMPAIGN_ID}
                AND ad_group.status != 'REMOVED'
            """,
        )
    )
    ads = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT ad_group.id, ad_group.name,
                     ad_group_ad.resource_name, ad_group_ad.ad.id,
                     ad_group_ad.status,
                     ad_group_ad.policy_summary.approval_status,
                     ad_group_ad.policy_summary.review_status,
                     ad_group_ad.ad.final_urls
              FROM ad_group_ad
              WHERE campaign.id = {EXPECTED_CAMPAIGN_ID}
                AND ad_group_ad.status != 'REMOVED'
            """,
        )
    )

    group_names = {row.ad_group.name for row in groups}
    unknown = group_names - monitor.ALLOWED_GROUPS
    if unknown:
        fail("UNKNOWN_AD_GROUPS:" + ",".join(sorted(unknown)))
    if not monitor.CORE_GROUPS.issubset(group_names):
        fail("CORE_GROUPS_MISSING")

    core_groups = [row for row in groups if row.ad_group.name in monitor.CORE_GROUPS]
    core_ads = [row for row in ads if row.ad_group.name in monitor.CORE_GROUPS]
    if len(core_groups) != 2 or len(core_ads) != 2:
        fail(f"CORE_INVARIANT groups={len(core_groups)} ads={len(core_ads)}")
    for row in core_groups:
        if int(row.ad_group.cpc_bid_micros or 0) > CORE_MAX_CPC_MICROS:
            fail("CORE_GROUP_CPC_OVER_CAP:" + row.ad_group.name)

    expected_urls = {legacy.normalized_url(url) for url in legacy.load_expected_urls()}
    actual_urls = set()
    for row in core_ads:
        policy = row.ad_group_ad.policy_summary
        if policy.approval_status.name != "APPROVED" or policy.review_status.name != "REVIEWED":
            fail("CORE_AD_POLICY_INVALID:" + str(row.ad_group_ad.ad.id))
        urls = list(row.ad_group_ad.ad.final_urls)
        if len(urls) != 1:
            fail("CORE_FINAL_URL_COUNT_INVALID")
        actual_urls.add(legacy.normalized_url(urls[0]))
    if actual_urls != expected_urls:
        fail("CORE_FINAL_URL_SET_INVALID")

    compra_rows = legacy.fetch_compra_action(ga, customer_id)
    if len(compra_rows) != 1:
        fail("PURCHASE_CONVERSION_COUNT:" + str(len(compra_rows)))
    compra = compra_rows[0].conversion_action
    if (
        compra.status.name != "ENABLED"
        or not bool(compra.primary_for_goal)
        or not bool(compra.include_in_conversions_metric)
        or compra.type.name != "GOOGLE_ANALYTICS_4_PURCHASE"
        or compra.category.name != "PURCHASE"
    ):
        fail("PURCHASE_CONVERSION_INVALID")

    purchase_goals = list(
        ga.search(
            customer_id=customer_id,
            query="""
              SELECT customer_conversion_goal.category,
                     customer_conversion_goal.origin,
                     customer_conversion_goal.biddable
              FROM customer_conversion_goal
              WHERE customer_conversion_goal.category = 'PURCHASE'
            """,
        )
    )
    if not any(bool(row.customer_conversion_goal.biddable) for row in purchase_goals):
        fail("PURCHASE_DEFAULT_GOAL_NOT_BIDDABLE")

    expansion_group = next((row for row in groups if row.ad_group.name == monitor.EXPANSION_GROUP), None)
    expansion_ads = [row for row in ads if row.ad_group.name == monitor.EXPANSION_GROUP]
    if expansion_group is not None:
        monitor.pause_expansion(
            client,
            customer_id,
            expansion_group,
            expansion_ads,
            "OPERATOR_CORE_RECOVERY_ISOLATES_EXPANSION",
        )

    changed = monitor.enable_core(client, customer_id, campaign, core_groups, core_ads)

    print("TARGET_CAMPAIGN_GUARD=PASS")
    print("OPERATOR_LANDING_PROOF=ANTIQUE_BROWSER_AND_DECORE_PRODUCTION_VM")
    print("campaign_id=" + str(EXPECTED_CAMPAIGN_ID))
    print("campaign_status_before=" + campaign.status.name)
    print("campaign_status_final=ENABLED")
    print("daily_budget_brl=10.00")
    print("core_groups=2")
    print("core_ads=2")
    print("expansion_isolated=" + str(expansion_group is not None).lower())
    print("ACTION=" + ("ENABLED_GUARDED_CORE" if changed else "CORE_ALREADY_ENABLED"))
    print("RESULT=OPERATOR_APPROVED_CORE_RECOVERY")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
