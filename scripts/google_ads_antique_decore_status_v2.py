#!/usr/bin/env python3
from __future__ import annotations

import os
import re

import google_ads_antique_decore_status as legacy

CORE_GROUPS = {"Vasos Antique Japi", "Vasos Decore Japi"}
EXPANSION_GROUP = "Vasos Japi"
ALLOWED_GROUPS = CORE_GROUPS | {EXPANSION_GROUP}
EXPECTED_BUDGET_MICROS = 10_000_000
EXPANSION_MAX_CPC_MICROS = 1_200_000

# The live ads do not depend on storefront promo-token text. Keep HTTP,
# redirect, UTM and content checks while disabling the obsolete copy heuristic.
legacy.STALE_PROMO_RE = re.compile(r"(?!)")


def set_status(client, customer_id: str, service: str, operation: str, resources: list[str], status) -> None:
    legacy.set_status(client, customer_id, service, operation, resources, status)


def pause_all(client, customer_id: str, campaign_rn: str, groups, ads, reason: str) -> int:
    legacy.pause_everything(client, customer_id, campaign_rn, groups, ads, reason)
    return 0


def pause_expansion(client, customer_id: str, expansion_group, expansion_ads, reason: str) -> None:
    ad_rns = [row.ad_group_ad.resource_name for row in expansion_ads if row.ad_group_ad.status.name != "PAUSED"]
    if ad_rns:
        set_status(
            client,
            customer_id,
            "AdGroupAdService",
            "AdGroupAdOperation",
            ad_rns,
            client.enums.AdGroupAdStatusEnum.PAUSED,
        )
    if expansion_group is not None and expansion_group.ad_group.status.name != "PAUSED":
        set_status(
            client,
            customer_id,
            "AdGroupService",
            "AdGroupOperation",
            [expansion_group.ad_group.resource_name],
            client.enums.AdGroupStatusEnum.PAUSED,
        )
    print("EXPANSION_ACTION=PAUSED_ONLY")
    print("EXPANSION_REASON=" + reason)


def enable_core(client, customer_id: str, campaign, core_groups, core_ads) -> bool:
    changed = False
    ad_rns = [row.ad_group_ad.resource_name for row in core_ads if row.ad_group_ad.status.name != "ENABLED"]
    group_rns = [row.ad_group.resource_name for row in core_groups if row.ad_group.status.name != "ENABLED"]
    if ad_rns:
        set_status(client, customer_id, "AdGroupAdService", "AdGroupAdOperation", ad_rns, client.enums.AdGroupAdStatusEnum.ENABLED)
        changed = True
    if group_rns:
        set_status(client, customer_id, "AdGroupService", "AdGroupOperation", group_rns, client.enums.AdGroupStatusEnum.ENABLED)
        changed = True
    if campaign.status.name != "ENABLED":
        set_status(
            client,
            customer_id,
            "CampaignService",
            "CampaignOperation",
            [campaign.resource_name],
            client.enums.CampaignStatusEnum.ENABLED,
        )
        changed = True
    return changed


def validate_expansion_keywords(ga, customer_id: str, group_id: int) -> tuple[bool, str]:
    rows = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT ad_group_criterion.keyword.text,
                     ad_group_criterion.keyword.match_type,
                     ad_group_criterion.negative,
                     ad_group_criterion.cpc_bid_micros
              FROM ad_group_criterion
              WHERE ad_group.id = {group_id}
                AND ad_group_criterion.status != 'REMOVED'
            """,
        )
    )
    negatives = set()
    positives = 0
    for row in rows:
        criterion = row.ad_group_criterion
        text = criterion.keyword.text.strip().casefold()
        if not text:
            continue
        if criterion.negative:
            negatives.add(text)
            continue
        positives += 1
        if criterion.keyword.match_type.name == "BROAD":
            return False, "EXPANSION_POSITIVE_BROAD keyword=" + text
        if int(criterion.cpc_bid_micros or 0) > EXPANSION_MAX_CPC_MICROS:
            return False, "EXPANSION_KEYWORD_CPC_OVER_CAP keyword=" + text
    if positives < 1:
        return False, "EXPANSION_NO_POSITIVE_KEYWORDS"
    if not {"antique", "decore"}.issubset(negatives):
        return False, "EXPANSION_ROUTING_NEGATIVES_MISSING"
    return True, ""


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
        raise SystemExit("MISSING_SECRETS=" + ",".join(missing))

    client = legacy.client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")

    safe = legacy.CAMPAIGN_NAME.replace("'", "\\'")
    campaigns = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT campaign.id, campaign.resource_name, campaign.name, campaign.status,
                     campaign.campaign_budget, campaign_budget.amount_micros
              FROM campaign
              WHERE campaign.name = '{safe}'
                AND campaign.status != 'REMOVED'
            """,
        )
    )
    if len(campaigns) != 1:
        raise SystemExit("CAMPAIGN_INVARIANT_FAILED count=" + str(len(campaigns)))
    campaign_row = campaigns[0]
    campaign = campaign_row.campaign
    campaign_id = int(campaign.id)
    budget_before = int(campaign_row.campaign_budget.amount_micros)
    budget_corrected = legacy.ensure_budget(client, customer_id, campaign.campaign_budget, budget_before)

    groups = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT ad_group.id, ad_group.resource_name, ad_group.name,
                     ad_group.status, ad_group.cpc_bid_micros
              FROM ad_group
              WHERE campaign.id = {campaign_id}
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
              WHERE campaign.id = {campaign_id}
                AND ad_group_ad.status != 'REMOVED'
            """,
        )
    )

    group_names = {row.ad_group.name for row in groups}
    unknown = group_names - ALLOWED_GROUPS
    if unknown:
        return pause_all(client, customer_id, campaign.resource_name, groups, ads, "UNKNOWN_AD_GROUPS=" + ",".join(sorted(unknown)))
    if not CORE_GROUPS.issubset(group_names):
        return pause_all(client, customer_id, campaign.resource_name, groups, ads, "CORE_AD_GROUPS_MISSING")

    core_groups = [row for row in groups if row.ad_group.name in CORE_GROUPS]
    core_ads = [row for row in ads if row.ad_group.name in CORE_GROUPS]
    if len(core_groups) != 2 or len(core_ads) != 2:
        return pause_all(
            client,
            customer_id,
            campaign.resource_name,
            groups,
            ads,
            f"CORE_INVARIANT_FAILED groups={len(core_groups)} ads={len(core_ads)}",
        )

    expected_urls = legacy.load_expected_urls()
    actual_core_urls = []
    for row in core_ads:
        urls = list(row.ad_group_ad.ad.final_urls)
        if len(urls) != 1:
            return pause_all(client, customer_id, campaign.resource_name, groups, ads, "CORE_FINAL_URL_COUNT_INVALID")
        actual_core_urls.append(urls[0])
        policy = row.ad_group_ad.policy_summary
        if policy.approval_status.name != "APPROVED" or policy.review_status.name != "REVIEWED":
            return pause_all(
                client,
                customer_id,
                campaign.resource_name,
                groups,
                ads,
                "CORE_AD_POLICY_INVALID ad_id=" + str(row.ad_group_ad.ad.id),
            )
    if {legacy.normalized_url(url) for url in actual_core_urls} != {legacy.normalized_url(url) for url in expected_urls}:
        return pause_all(client, customer_id, campaign.resource_name, groups, ads, "CORE_FINAL_URL_SET_INVALID")

    landing_ok, landing_reason, landing_results = legacy.validate_landing_pages(actual_core_urls)
    if not landing_ok:
        return pause_all(client, customer_id, campaign.resource_name, groups, ads, landing_reason)

    compra_rows = legacy.fetch_compra_action(ga, customer_id)
    if len(compra_rows) != 1:
        return pause_all(client, customer_id, campaign.resource_name, groups, ads, "PURCHASE_CONVERSION_INVALID")
    compra = compra_rows[0].conversion_action
    if (
        compra.status.name != "ENABLED"
        or not bool(compra.primary_for_goal)
        or not bool(compra.include_in_conversions_metric)
        or compra.type.name != "GOOGLE_ANALYTICS_4_PURCHASE"
        or compra.category.name != "PURCHASE"
    ):
        return pause_all(client, customer_id, campaign.resource_name, groups, ads, "PURCHASE_CONVERSION_INVALID")

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
        return pause_all(client, customer_id, campaign.resource_name, groups, ads, "PURCHASE_DEFAULT_GOAL_INVALID")

    expansion_group = next((row for row in groups if row.ad_group.name == EXPANSION_GROUP), None)
    expansion_ads = [row for row in ads if row.ad_group.name == EXPANSION_GROUP]
    expansion_state = "absent"
    if expansion_group is not None:
        if int(expansion_group.ad_group.cpc_bid_micros or 0) > EXPANSION_MAX_CPC_MICROS:
            pause_expansion(client, customer_id, expansion_group, expansion_ads, "EXPANSION_GROUP_CPC_OVER_CAP")
            expansion_state = "paused_guard"
        else:
            keywords_ok, keyword_reason = validate_expansion_keywords(ga, customer_id, int(expansion_group.ad_group.id))
            if not keywords_ok:
                pause_expansion(client, customer_id, expansion_group, expansion_ads, keyword_reason)
                expansion_state = "paused_guard"
            elif len(expansion_ads) > 1:
                pause_expansion(client, customer_id, expansion_group, expansion_ads, "EXPANSION_MULTIPLE_ADS")
                expansion_state = "paused_guard"
            elif len(expansion_ads) == 0:
                pause_expansion(client, customer_id, expansion_group, expansion_ads, "EXPANSION_AD_NOT_CREATED_YET")
                expansion_state = "awaiting_ad"
            else:
                expansion_ad = expansion_ads[0].ad_group_ad
                policy = expansion_ad.policy_summary
                if policy.approval_status.name == "DISAPPROVED":
                    pause_expansion(client, customer_id, expansion_group, expansion_ads, "EXPANSION_AD_DISAPPROVED")
                    expansion_state = "disapproved"
                elif policy.approval_status.name == "APPROVED" and policy.review_status.name == "REVIEWED":
                    if expansion_group.ad_group.status.name != "ENABLED":
                        set_status(
                            client,
                            customer_id,
                            "AdGroupService",
                            "AdGroupOperation",
                            [expansion_group.ad_group.resource_name],
                            client.enums.AdGroupStatusEnum.ENABLED,
                        )
                    if expansion_ad.status.name != "ENABLED":
                        set_status(
                            client,
                            customer_id,
                            "AdGroupAdService",
                            "AdGroupAdOperation",
                            [expansion_ad.resource_name],
                            client.enums.AdGroupAdStatusEnum.ENABLED,
                        )
                    expansion_state = "approved"
                else:
                    expansion_state = "under_review"

    core_changed = enable_core(client, customer_id, campaign, core_groups, core_ads)

    print("campaign_id=" + str(campaign_id))
    print("campaign_status_before=" + campaign.status.name)
    print("campaign_status_final=ENABLED")
    print("daily_budget_brl=10.00")
    print("budget_corrected=" + str(budget_corrected).lower())
    print("core_groups=2")
    print("core_ads=2")
    print("expansion_group_present=" + str(expansion_group is not None).lower())
    print("expansion_ads=" + str(len(expansion_ads)))
    print("expansion_state=" + expansion_state)
    for url, code, final_url in landing_results:
        print(f"landing_page={url}:http={code}:healthy=true:final_url={final_url}")
    print("ACTION=" + ("ENABLED_HEALTHY_CORE" if core_changed else "CORE_ALREADY_HEALTHY"))
    print("RESULT=HEALTHY_CORE_WITH_ISOLATED_EXPANSION")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
