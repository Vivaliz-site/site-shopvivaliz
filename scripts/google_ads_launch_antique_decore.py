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
    if {str(g.get("name", "")) for g in groups} != {"Vasos Antique Japi", "Vasos Decore Japi"}:
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
            raise SystemExit("CONFIG_BLOCKED: invalid landing page")
        if any(len(str(x)) > 30 for x in ad.get("headlines", [])):
            raise SystemExit("CONFIG_BLOCKED: RSA headline over 30 chars")
        if any(len(str(x)) > 90 for x in ad.get("descriptions", [])):
            raise SystemExit("CONFIG_BLOCKED: RSA description over 90 chars")


def campaign_rows(client: GoogleAdsClient, customer_id: str):
    safe = EXPECTED_NAME.replace("'", "\\'")
    return list(client.get_service("GoogleAdsService").search(customer_id=customer_id, query=f"""
      SELECT campaign.id, campaign.resource_name, campaign.name, campaign.status,
             campaign.campaign_budget, campaign_budget.amount_micros,
             campaign.contains_eu_political_advertising
      FROM campaign
      WHERE campaign.name = '{safe}' AND campaign.status != 'REMOVED'
    """))


def get_or_create_budget(client: GoogleAdsClient, customer_id: str) -> str:
    ga = client.get_service("GoogleAdsService")
    safe = BUDGET_NAME.replace("'", "\\'")
    rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign_budget.resource_name, campaign_budget.amount_micros,
             campaign_budget.reference_count, campaign_budget.status
      FROM campaign_budget
      WHERE campaign_budget.name = '{safe}' AND campaign_budget.status != 'REMOVED'
    """))
    for row in rows:
        b = row.campaign_budget
        if int(b.amount_micros) == 10_000_000 and int(b.reference_count) == 0:
            print("REUSING_ORPHAN_BUDGET=" + b.resource_name)
            return b.resource_name
    return creator.create_budget(client, customer_id, EXPECTED_NAME, 10.0)


def create_campaign(client: GoogleAdsClient, customer_id: str, config: dict, budget_resource: str) -> str:
    operation = client.get_type("CampaignOperation")
    campaign = operation.create
    campaign.name = EXPECTED_NAME
    campaign.advertising_channel_type = client.enums.AdvertisingChannelTypeEnum.SEARCH
    campaign.status = client.enums.CampaignStatusEnum.PAUSED
    campaign.campaign_budget = budget_resource
    campaign.contains_eu_political_advertising = client.enums.EuPoliticalAdvertisingStatusEnum.DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING
    campaign.manual_cpc.enhanced_cpc_enabled = False
    campaign.network_settings.target_google_search = True
    campaign.network_settings.target_search_network = False
    campaign.network_settings.target_content_network = False
    campaign.network_settings.target_partner_search_network = False
    response = client.get_service("CampaignService").mutate_campaigns(customer_id=customer_id, operations=[operation])
    return response.results[0].resource_name


def get_or_create_campaign(client: GoogleAdsClient, customer_id: str, config: dict) -> tuple[str, str, int]:
    rows = campaign_rows(client, customer_id)
    if len(rows) > 1:
        raise SystemExit("DUPLICATE_CAMPAIGN_BLOCKED")
    if rows:
        row = rows[0]
        if int(row.campaign_budget.amount_micros) != 10_000_000:
            raise SystemExit("EXISTING_CAMPAIGN_BUDGET_BLOCKED")
        print("RESUMING_EXISTING_CAMPAIGN=" + str(row.campaign.id))
        return row.campaign.resource_name, row.campaign.campaign_budget, int(row.campaign.id)
    budget = get_or_create_budget(client, customer_id)
    campaign = create_campaign(client, customer_id, config, budget)
    campaign_id = int(campaign.rsplit("/", 1)[-1])
    return campaign, budget, campaign_id


def ensure_campaign_criteria(client: GoogleAdsClient, customer_id: str, campaign_resource: str, campaign_id: int, negatives: list[str]) -> None:
    ga = client.get_service("GoogleAdsService")
    rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign_criterion.type, campaign_criterion.negative,
             campaign_criterion.keyword.text,
             campaign_criterion.location.geo_target_constant,
             campaign_criterion.language.language_constant
      FROM campaign_criterion
      WHERE campaign.id = {campaign_id}
    """))
    has_location = False
    has_language = False
    existing_negative = set()
    for row in rows:
        c = row.campaign_criterion
        if str(c.location.geo_target_constant).endswith("/2076"):
            has_location = True
        if str(c.language.language_constant).endswith("/1014"):
            has_language = True
        if c.negative and c.keyword.text:
            existing_negative.add(c.keyword.text.casefold())

    ops = []
    if not has_location:
        op = client.get_type("CampaignCriterionOperation")
        c = op.create
        c.campaign = campaign_resource
        c.location.geo_target_constant = "geoTargetConstants/2076"
        ops.append(op)
    if not has_language:
        op = client.get_type("CampaignCriterionOperation")
        c = op.create
        c.campaign = campaign_resource
        c.language.language_constant = "languageConstants/1014"
        ops.append(op)
    for text in negatives:
        if text.casefold() in existing_negative:
            continue
        op = client.get_type("CampaignCriterionOperation")
        c = op.create
        c.campaign = campaign_resource
        c.negative = True
        c.keyword.text = text
        c.keyword.match_type = client.enums.KeywordMatchTypeEnum.BROAD
        ops.append(op)
    if ops:
        client.get_service("CampaignCriterionService").mutate_campaign_criteria(customer_id=customer_id, operations=ops)
        print("CAMPAIGN_CRITERIA_CREATED=" + str(len(ops)))


def existing_ad_groups(client: GoogleAdsClient, customer_id: str, campaign_id: int) -> dict[str, str]:
    rows = client.get_service("GoogleAdsService").search(customer_id=customer_id, query=f"""
      SELECT ad_group.resource_name, ad_group.name
      FROM ad_group
      WHERE campaign.id = {campaign_id} AND ad_group.status != 'REMOVED'
    """)
    return {row.ad_group.name: row.ad_group.resource_name for row in rows}


def ensure_keywords(client: GoogleAdsClient, customer_id: str, ad_group_resource: str, group: dict) -> None:
    ad_group_id = int(ad_group_resource.rsplit("/", 1)[-1])
    rows = client.get_service("GoogleAdsService").search(customer_id=customer_id, query=f"""
      SELECT ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type,
             ad_group_criterion.negative
      FROM ad_group_criterion
      WHERE ad_group.id = {ad_group_id} AND ad_group_criterion.status != 'REMOVED'
    """)
    existing_pos = set()
    existing_neg = set()
    for row in rows:
        c = row.ad_group_criterion
        if not c.keyword.text:
            continue
        if c.negative:
            existing_neg.add(c.keyword.text.casefold())
        else:
            existing_pos.add((c.keyword.text.casefold(), c.keyword.match_type.name))
    missing_pos = [kw for kw in group["keywords"] if (kw["text"].casefold(), kw["match_type"]) not in existing_pos]
    missing_neg = [text for text in group.get("negative_keywords", []) if text.casefold() not in existing_neg]
    if missing_pos:
        creator.create_keywords(client, customer_id, ad_group_resource, missing_pos)
    if missing_neg:
        creator.create_ad_group_negatives(client, customer_id, ad_group_resource, missing_neg)


def ensure_ad(client: GoogleAdsClient, customer_id: str, ad_group_resource: str, group: dict, tracking: dict) -> tuple[str, str]:
    ad_group_id = int(ad_group_resource.rsplit("/", 1)[-1])
    rows = list(client.get_service("GoogleAdsService").search(customer_id=customer_id, query=f"""
      SELECT ad_group_ad.resource_name, ad_group_ad.ad.final_urls
      FROM ad_group_ad
      WHERE ad_group.id = {ad_group_id} AND ad_group_ad.status != 'REMOVED'
    """))
    expected_url = creator.final_url_with_utm(group["responsive_search_ad"]["final_url"], tracking, group["tracking_content"])
    if rows:
        return rows[0].ad_group_ad.resource_name, expected_url
    return creator.create_responsive_search_ad(client, customer_id, ad_group_resource, group, tracking)


def cleanup_unused_duplicate_budgets(client: GoogleAdsClient, customer_id: str, keep_resource: str) -> None:
    safe = BUDGET_NAME.replace("'", "\\'")
    rows = client.get_service("GoogleAdsService").search(customer_id=customer_id, query=f"""
      SELECT campaign_budget.resource_name, campaign_budget.reference_count, campaign_budget.status
      FROM campaign_budget
      WHERE campaign_budget.name = '{safe}' AND campaign_budget.status != 'REMOVED'
    """)
    ops = []
    for row in rows:
        b = row.campaign_budget
        if b.resource_name != keep_resource and int(b.reference_count) == 0:
            op = client.get_type("CampaignBudgetOperation")
            op.remove = b.resource_name
            ops.append(op)
    if ops:
        client.get_service("CampaignBudgetService").mutate_campaign_budgets(customer_id=customer_id, operations=ops)
        print("REMOVED_UNUSED_DUPLICATE_BUDGETS=" + str(len(ops)))


def main() -> int:
    required = ["GOOGLE_OAUTH_CLIENT_ID", "GOOGLE_OAUTH_CLIENT_SECRET", "GOOGLE_ADS_REFRESH_TOKEN", "GOOGLE_ADS_DEVELOPER_TOKEN", "GOOGLE_ADS_CUSTOMER_ID"]
    missing = [k for k in required if not os.getenv(k, "").strip()]
    if missing:
        raise SystemExit("MISSING_SECRETS: " + ",".join(missing))

    config = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    validate_config(config)
    client = client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    campaign_resource, budget_resource, campaign_id = get_or_create_campaign(client, customer_id, config)
    ensure_campaign_criteria(client, customer_id, campaign_resource, campaign_id, config["negative_keywords"])

    existing_groups = existing_ad_groups(client, customer_id, campaign_id)
    ad_group_resources = []
    ad_resources = []
    final_urls = []
    for group in config["ad_groups"]:
        ag = existing_groups.get(group["name"])
        if not ag:
            ag = creator.create_ad_group(client, customer_id, campaign_resource, group)
        ensure_keywords(client, customer_id, ag, group)
        ad, final_url = ensure_ad(client, customer_id, ag, group, config["tracking"])
        ad_group_resources.append(ag)
        ad_resources.append(ad)
        final_urls.append(final_url)

    ga = client.get_service("GoogleAdsService")
    safe = EXPECTED_NAME.replace("'", "\\'")
    groups = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group.id, ad_group.name, ad_group.status FROM ad_group
      WHERE campaign.id = {campaign_id} AND ad_group.status != 'REMOVED'
    """))
    ads = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group_ad.ad.id, ad_group_ad.status,
             ad_group_ad.policy_summary.approval_status,
             ad_group_ad.policy_summary.review_status,
             ad_group_ad.ad.final_urls
      FROM ad_group_ad
      WHERE campaign.id = {campaign_id} AND ad_group_ad.status != 'REMOVED'
    """))
    if len(groups) != 2 or len(ads) != 2:
        raise SystemExit(f"AUDIT_BLOCKED: expected 2 groups/2 ads, got {len(groups)}/{len(ads)}")
    rows = campaign_rows(client, customer_id)
    if len(rows) != 1 or int(rows[0].campaign_budget.amount_micros) != 10_000_000:
        raise SystemExit("AUDIT_BLOCKED: campaign/budget invariant failed")
    cleanup_unused_duplicate_budgets(client, customer_id, budget_resource)

    conversion_rows = list(ga.search(customer_id=customer_id, query="""
      SELECT conversion_action.id, conversion_action.name, conversion_action.status,
             conversion_action.primary_for_goal
      FROM conversion_action WHERE conversion_action.status = 'ENABLED'
    """))
    purchase_like = [r.conversion_action.name for r in conversion_rows if any(x in r.conversion_action.name.lower() for x in ("purchase", "compra", "pedido", "sale"))]

    print("CREATED_PAUSED_FOR_POLICY_REVIEW")
    print("campaign_name=" + EXPECTED_NAME)
    print("campaign_id=" + str(campaign_id))
    print("campaign_status=" + rows[0].campaign.status.name)
    print("daily_budget_brl=10.00")
    print("ad_groups=2")
    print("ads=2")
    print("eu_political_ads=DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING")
    print("final_urls=" + " | ".join(final_urls))
    print("purchase_like_conversion_actions=" + (" | ".join(purchase_like) if purchase_like else "none_detected"))
    for r in ads:
        ps = r.ad_group_ad.policy_summary
        print("ad_policy=" + str(r.ad_group_ad.ad.id) + ":approval=" + ps.approval_status.name + ":review=" + ps.review_status.name + ":status=" + r.ad_group_ad.status.name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
