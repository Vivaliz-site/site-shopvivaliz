#!/usr/bin/env python3
from __future__ import annotations

import os
from google.ads.googleads.client import GoogleAdsClient

CAMPAIGN_NAME = "ShopVivaliz-Search-Vasos-Antique-Decore-2026-08"
EXPECTED_GROUPS = {"Vasos Antique Japi", "Vasos Decore Japi"}
EXPECTED_BUDGET_MICROS = 10_000_000


def client_from_env() -> GoogleAdsClient:
    cfg = {
        "developer_token": os.environ["GOOGLE_ADS_DEVELOPER_TOKEN"],
        "client_id": os.environ["GOOGLE_OAUTH_CLIENT_ID"],
        "client_secret": os.environ["GOOGLE_OAUTH_CLIENT_SECRET"],
        "refresh_token": os.environ["GOOGLE_ADS_REFRESH_TOKEN"],
        "use_proto_plus": True,
    }
    login = os.getenv("GOOGLE_ADS_LOGIN_CUSTOMER_ID", "").replace("-", "").strip()
    if login:
        cfg["login_customer_id"] = login
    return GoogleAdsClient.load_from_dict(cfg)


def set_status(client, customer_id, service_name, operation_name, resource_names, status):
    if not resource_names:
        return
    operations = []
    for rn in resource_names:
        op = client.get_type(operation_name)
        op.update.resource_name = rn
        op.update.status = status
        op.update_mask.paths.append("status")
        operations.append(op)
    method = {
        "CampaignService": "mutate_campaigns",
        "AdGroupService": "mutate_ad_groups",
        "AdGroupAdService": "mutate_ad_group_ads",
    }[service_name]
    getattr(client.get_service(service_name), method)(customer_id=customer_id, operations=operations)


def main() -> int:
    required = ["GOOGLE_OAUTH_CLIENT_ID", "GOOGLE_OAUTH_CLIENT_SECRET", "GOOGLE_ADS_REFRESH_TOKEN", "GOOGLE_ADS_DEVELOPER_TOKEN", "GOOGLE_ADS_CUSTOMER_ID"]
    missing = [k for k in required if not os.getenv(k, "").strip()]
    if missing:
        raise SystemExit("MISSING_SECRETS=" + ",".join(missing))

    enable_if_approved = os.getenv("ENABLE_IF_APPROVED", "no").strip().lower() == "yes"
    client = client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")
    safe = CAMPAIGN_NAME.replace("'", "\\'")

    campaigns = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign.id, campaign.resource_name, campaign.name, campaign.status,
             campaign.campaign_budget, campaign_budget.amount_micros,
             campaign.contains_eu_political_advertising,
             metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions
      FROM campaign
      WHERE campaign.name = '{safe}'
        AND campaign.status != 'REMOVED'
        AND segments.date DURING TODAY
    """))
    if len(campaigns) != 1:
        raise SystemExit("CAMPAIGN_INVARIANT_FAILED count=" + str(len(campaigns)))
    c = campaigns[0]
    if int(c.campaign_budget.amount_micros) != EXPECTED_BUDGET_MICROS:
        raise SystemExit("BUDGET_GUARD_FAILED")

    campaign_id = int(c.campaign.id)
    groups = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group.id, ad_group.resource_name, ad_group.name, ad_group.status
      FROM ad_group
      WHERE campaign.id = {campaign_id} AND ad_group.status != 'REMOVED'
    """))
    if len(groups) != 2 or {g.ad_group.name for g in groups} != EXPECTED_GROUPS:
        raise SystemExit("AD_GROUP_INVARIANT_FAILED")

    ads = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group_ad.resource_name, ad_group_ad.ad.id, ad_group_ad.status,
             ad_group_ad.policy_summary.approval_status,
             ad_group_ad.policy_summary.review_status,
             ad_group_ad.ad.final_urls
      FROM ad_group_ad
      WHERE campaign.id = {campaign_id} AND ad_group_ad.status != 'REMOVED'
    """))
    if len(ads) != 2:
        raise SystemExit("AD_INVARIANT_FAILED count=" + str(len(ads)))

    conversion_rows = list(ga.search(customer_id=customer_id, query="""
      SELECT conversion_action.id, conversion_action.name, conversion_action.status,
             conversion_action.primary_for_goal, conversion_action.include_in_conversions_metric,
             conversion_action.type, conversion_action.origin, conversion_action.category
      FROM conversion_action
      WHERE conversion_action.status = 'ENABLED'
    """))
    purchase_like = [r for r in conversion_rows if any(x in r.conversion_action.name.lower() for x in ("purchase", "compra", "pedido", "sale"))]
    if not purchase_like:
        raise SystemExit("PURCHASE_CONVERSION_GUARD_FAILED")
    primary_purchase = [r for r in purchase_like if bool(r.conversion_action.primary_for_goal)]
    if not primary_purchase:
        raise SystemExit("PRIMARY_PURCHASE_CONVERSION_GUARD_FAILED")

    policy = []
    all_approved = True
    any_disapproved = False
    for row in ads:
        ps = row.ad_group_ad.policy_summary
        approval = ps.approval_status.name
        review = ps.review_status.name
        urls = list(row.ad_group_ad.ad.final_urls)
        if not urls or any(not u.startswith("https://shopvivaliz.com.br/") for u in urls):
            raise SystemExit("FINAL_URL_GUARD_FAILED")
        policy.append((str(row.ad_group_ad.ad.id), approval, review, row.ad_group_ad.status.name))
        if approval != "APPROVED":
            all_approved = False
        if approval == "DISAPPROVED":
            any_disapproved = True

    print("campaign_id=" + str(campaign_id))
    print("campaign_status=" + c.campaign.status.name)
    print("daily_budget_brl=10.00")
    print("impressions_today=" + str(c.metrics.impressions))
    print("clicks_today=" + str(c.metrics.clicks))
    print("cost_brl_today=" + f"{c.metrics.cost_micros / 1_000_000:.2f}")
    print("conversions_today=" + str(c.metrics.conversions))
    for r in purchase_like:
        action = r.conversion_action
        print(
            "purchase_conversion=" + action.name
            + ":id=" + str(action.id)
            + ":primary=" + str(bool(action.primary_for_goal)).lower()
            + ":included=" + str(bool(action.include_in_conversions_metric)).lower()
            + ":type=" + action.type.name
            + ":origin=" + action.origin.name
            + ":category=" + action.category.name
        )
    for ad_id, approval, review, status in policy:
        print(f"ad_policy={ad_id}:approval={approval}:review={review}:status={status}")

    if any_disapproved:
        print("RESULT=DISAPPROVED_KEEP_PAUSED")
        return 0
    if not all_approved:
        print("RESULT=POLICY_PENDING_KEEP_PAUSED")
        return 0
    if not enable_if_approved:
        print("RESULT=APPROVED_READY_TO_ENABLE")
        return 0

    set_status(client, customer_id, "AdGroupAdService", "AdGroupAdOperation", [r.ad_group_ad.resource_name for r in ads], client.enums.AdGroupAdStatusEnum.ENABLED)
    set_status(client, customer_id, "AdGroupService", "AdGroupOperation", [r.ad_group.resource_name for r in groups], client.enums.AdGroupStatusEnum.ENABLED)
    set_status(client, customer_id, "CampaignService", "CampaignOperation", [c.campaign.resource_name], client.enums.CampaignStatusEnum.ENABLED)
    print("RESULT=ENABLED_AFTER_APPROVAL")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
