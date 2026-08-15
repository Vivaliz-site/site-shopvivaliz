#!/usr/bin/env python3
from __future__ import annotations

import json
import os
from pathlib import Path

from google.ads.googleads.client import GoogleAdsClient

import google_ads_create_search_campaign as creator

ROOT = Path(__file__).resolve().parents[1]
CONFIG_PATH = ROOT / "scripts" / "google_ads_campaign_live_ready.json"
EXPECTED_NAME = "ShopVivaliz-Search-Vasos-Antique-Decore-2026-08"
BUDGET_NAME = EXPECTED_NAME + " Budget"


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


def validate_config(config: dict) -> None:
    campaign = config.get("campaign", {})
    groups = config.get("ad_groups", [])
    if campaign.get("name") != EXPECTED_NAME:
        raise SystemExit("CONFIG_BLOCKED: unexpected campaign name")
    if float(campaign.get("daily_budget_brl", 0)) != 10.0:
        raise SystemExit("CONFIG_BLOCKED: daily budget must be exactly BRL 10.00")
    if len(groups) != 2:
        raise SystemExit("CONFIG_BLOCKED: expected exactly two ad groups")
    expected = {"Vasos Antique Japi", "Vasos Decore Japi"}
    if {str(g.get("name", "")) for g in groups} != expected:
        raise SystemExit("CONFIG_BLOCKED: unexpected ad groups")
    for group in groups:
        if float(group.get("default_cpc_brl", 0)) > 0.40:
            raise SystemExit("CONFIG_BLOCKED: default CPC over BRL 0.40")
        for kw in group.get("keywords", []):
            if kw.get("match_type") not in {"EXACT", "PHRASE"}:
                raise SystemExit("CONFIG_BLOCKED: broad positive keyword")
            if float(kw.get("cpc_brl", 0)) > 0.40:
                raise SystemExit("CONFIG_BLOCKED: keyword CPC over BRL 0.40")
        ad = group.get("responsive_search_ad", {})
        url = str(ad.get("final_url", ""))
        if not url.startswith("https://shopvivaliz.com.br/catalogo?q="):
            raise SystemExit("CONFIG_BLOCKED: landing page must be current ShopVivaliz catalog search")
        if any(len(str(x)) > 30 for x in ad.get("headlines", [])):
            raise SystemExit("CONFIG_BLOCKED: RSA headline over 30 chars")
        if any(len(str(x)) > 90 for x in ad.get("descriptions", [])):
            raise SystemExit("CONFIG_BLOCKED: RSA description over 90 chars")


def get_or_create_budget(client: GoogleAdsClient, customer_id: str) -> str:
    ga = client.get_service("GoogleAdsService")
    safe = BUDGET_NAME.replace("'", "\\'")
    rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign_budget.resource_name, campaign_budget.amount_micros,
             campaign_budget.reference_count, campaign_budget.status
      FROM campaign_budget
      WHERE campaign_budget.name = '{safe}'
        AND campaign_budget.status != 'REMOVED'
    """))
    for row in rows:
        b = row.campaign_budget
        if int(b.amount_micros) == 10_000_000 and int(b.reference_count) == 0:
            print("REUSING_ORPHAN_BUDGET=" + b.resource_name)
            return b.resource_name
    return creator.create_budget(client, customer_id, EXPECTED_NAME, 10.0)


def create_campaign(client: GoogleAdsClient, customer_id: str, config: dict, budget_resource: str) -> str:
    service = client.get_service("CampaignService")
    operation = client.get_type("CampaignOperation")
    campaign = operation.create
    campaign.name = config["campaign"]["name"]
    campaign.advertising_channel_type = client.enums.AdvertisingChannelTypeEnum.SEARCH
    campaign.status = client.enums.CampaignStatusEnum.PAUSED
    campaign.campaign_budget = budget_resource
    campaign.contains_eu_political_advertising = (
        client.enums.EuPoliticalAdvertisingStatusEnum.DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING
    )
    campaign.manual_cpc.enhanced_cpc_enabled = False
    campaign.network_settings.target_google_search = True
    campaign.network_settings.target_search_network = False
    campaign.network_settings.target_content_network = False
    campaign.network_settings.target_partner_search_network = False
    response = service.mutate_campaigns(customer_id=customer_id, operations=[operation])
    return response.results[0].resource_name


def cleanup_unused_duplicate_budgets(client: GoogleAdsClient, customer_id: str, keep_resource: str) -> None:
    ga = client.get_service("GoogleAdsService")
    safe = BUDGET_NAME.replace("'", "\\'")
    rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign_budget.resource_name, campaign_budget.reference_count, campaign_budget.status
      FROM campaign_budget
      WHERE campaign_budget.name = '{safe}'
        AND campaign_budget.status != 'REMOVED'
    """))
    ops = []
    for row in rows:
        b = row.campaign_budget
        if b.resource_name != keep_resource and int(b.reference_count) == 0:
            op = client.get_type("CampaignBudgetOperation")
            op.remove = b.resource_name
            ops.append(op)
    if ops:
        client.get_service("CampaignBudgetService").mutate_campaign_budgets(
            customer_id=customer_id, operations=ops
        )
        print("REMOVED_UNUSED_DUPLICATE_BUDGETS=" + str(len(ops)))


def enable_resource_status(client: GoogleAdsClient, customer_id: str, service_name: str, operation_name: str, resource_names: list[str], enum_value) -> None:
    service = client.get_service(service_name)
    operations = []
    for resource_name in resource_names:
        op = client.get_type(operation_name)
        op.update.resource_name = resource_name
        op.update.status = enum_value
        op.update_mask.paths.append("status")
        operations.append(op)
    if not operations:
        return
    method_name = {
        "CampaignService": "mutate_campaigns",
        "AdGroupService": "mutate_ad_groups",
        "AdGroupAdService": "mutate_ad_group_ads",
    }[service_name]
    getattr(service, method_name)(customer_id=customer_id, operations=operations)


def main() -> int:
    required = [
        "GOOGLE_OAUTH_CLIENT_ID", "GOOGLE_OAUTH_CLIENT_SECRET", "GOOGLE_ADS_REFRESH_TOKEN",
        "GOOGLE_ADS_DEVELOPER_TOKEN", "GOOGLE_ADS_CUSTOMER_ID",
    ]
    missing = [k for k in required if not os.getenv(k, "").strip()]
    if missing:
        raise SystemExit("MISSING_SECRETS: " + ",".join(missing))

    enable_after_audit = os.getenv("ENABLE_AFTER_AUDIT", "no").strip().lower() == "yes"
    config = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    validate_config(config)
    client = client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()

    creator.assert_campaign_name_available(client, customer_id, EXPECTED_NAME)
    budget = get_or_create_budget(client, customer_id)
    campaign = create_campaign(client, customer_id, config, budget)
    creator.create_campaign_criteria(client, customer_id, campaign, config["negative_keywords"])

    ad_groups: list[str] = []
    ads: list[str] = []
    final_urls: list[str] = []
    for group in config["ad_groups"]:
        ag = creator.create_ad_group(client, customer_id, campaign, group)
        creator.create_keywords(client, customer_id, ag, group["keywords"])
        creator.create_ad_group_negatives(client, customer_id, ag, group.get("negative_keywords", []))
        ad, final_url = creator.create_responsive_search_ad(client, customer_id, ag, group, config["tracking"])
        ad_groups.append(ag)
        ads.append(ad)
        final_urls.append(final_url)

    ga = client.get_service("GoogleAdsService")
    safe = EXPECTED_NAME.replace("'", "\\'")
    rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign.id, campaign.name, campaign.status, campaign_budget.amount_micros,
             campaign.contains_eu_political_advertising
      FROM campaign
      WHERE campaign.name = '{safe}' AND campaign.status != 'REMOVED'
      LIMIT 1
    """))
    if len(rows) != 1:
        raise SystemExit("AUDIT_BLOCKED: campaign not uniquely found")
    row = rows[0]
    if int(row.campaign_budget.amount_micros) != 10_000_000:
        raise SystemExit("AUDIT_BLOCKED: budget is not BRL 10/day")

    group_rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group.id, ad_group.name, ad_group.status
      FROM ad_group
      WHERE campaign.name = '{safe}' AND ad_group.status != 'REMOVED'
    """))
    ad_rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group_ad.ad.id, ad_group_ad.status,
             ad_group_ad.policy_summary.approval_status,
             ad_group_ad.policy_summary.review_status,
             ad_group_ad.ad.final_urls
      FROM ad_group_ad
      WHERE campaign.name = '{safe}' AND ad_group_ad.status != 'REMOVED'
    """))
    if len(group_rows) != 2 or len(ad_rows) != 2:
        raise SystemExit(f"AUDIT_BLOCKED: expected 2 groups/2 ads, got {len(group_rows)}/{len(ad_rows)}")

    cleanup_unused_duplicate_budgets(client, customer_id, budget)

    if enable_after_audit:
        enable_resource_status(client, customer_id, "AdGroupAdService", "AdGroupAdOperation", ads, client.enums.AdGroupAdStatusEnum.ENABLED)
        enable_resource_status(client, customer_id, "AdGroupService", "AdGroupOperation", ad_groups, client.enums.AdGroupStatusEnum.ENABLED)
        enable_resource_status(client, customer_id, "CampaignService", "CampaignOperation", [campaign], client.enums.CampaignStatusEnum.ENABLED)

    conversion_rows = list(ga.search(customer_id=customer_id, query="""
      SELECT conversion_action.id, conversion_action.name, conversion_action.status, conversion_action.primary_for_goal
      FROM conversion_action
      WHERE conversion_action.status = 'ENABLED'
    """))
    purchase_like = [r.conversion_action.name for r in conversion_rows if any(x in r.conversion_action.name.lower() for x in ("purchase", "compra", "pedido", "sale"))]

    final = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign.id, campaign.status, campaign_budget.amount_micros,
             metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions
      FROM campaign
      WHERE campaign.name = '{safe}'
      DURING TODAY
    """))

    print("LAUNCH_OK" if enable_after_audit else "CREATED_PAUSED_FOR_POLICY_REVIEW")
    print("campaign_name=" + EXPECTED_NAME)
    print("campaign_resource=" + campaign)
    print("daily_budget_brl=10.00")
    print("ad_groups=2")
    print("ads=2")
    print("eu_political_ads=DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING")
    print("final_urls=" + " | ".join(final_urls))
    print("purchase_like_conversion_actions=" + (" | ".join(purchase_like) if purchase_like else "none_detected"))
    for r in ad_rows:
        ps = r.ad_group_ad.policy_summary
        print(
            "ad_policy=" + str(r.ad_group_ad.ad.id)
            + ":approval=" + ps.approval_status.name
            + ":review=" + ps.review_status.name
            + ":status=" + r.ad_group_ad.status.name
        )
    if final:
        print("campaign_id=" + str(final[0].campaign.id))
        print("campaign_status=" + final[0].campaign.status.name)
        print("impressions_today=" + str(final[0].metrics.impressions))
        print("clicks_today=" + str(final[0].metrics.clicks))
        print("cost_brl_today=" + f"{final[0].metrics.cost_micros / 1_000_000:.2f}")
        print("conversions_today=" + str(final[0].metrics.conversions))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
