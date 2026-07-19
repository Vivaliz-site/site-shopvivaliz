#!/usr/bin/env python3
"""Create the live Google Ads Search campaign from the reviewed config.

This script performs real Google Ads API mutations only when called with
--create-paused. It intentionally creates the campaign paused first; enabling
delivery/spend must be a separate, explicit operational decision.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlencode, urlsplit, urlunsplit, parse_qsl


ROOT = Path(__file__).resolve().parents[1]
CONFIG_PATH = ROOT / "scripts" / "google_ads_campaign_live_ready.json"


def load_dotenv(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


def micros(brl: float) -> int:
    return int(round(float(brl) * 1_000_000))


def final_url_with_utm(base_url: str, tracking: dict[str, str]) -> str:
    split = urlsplit(base_url)
    query = dict(parse_qsl(split.query, keep_blank_values=True))
    query.update({
        "utm_source": tracking.get("utm_source", "google"),
        "utm_medium": tracking.get("utm_medium", "cpc"),
        "utm_campaign": tracking.get("utm_campaign", "rodizios_roi10_kits_2026_07"),
        "utm_content": "search_rsa",
        "cupom": tracking.get("coupon", "PRIMEIRA10"),
    })
    return urlunsplit((split.scheme, split.netloc, split.path, urlencode(query), split.fragment))


def run_readiness() -> None:
    result = subprocess.run(
        [sys.executable, str(ROOT / "scripts" / "google_ads_real_readiness.py")],
        cwd=str(ROOT),
        text=True,
        capture_output=True,
    )
    if result.returncode != 0:
        sys.stdout.write(result.stdout)
        sys.stderr.write(result.stderr)
        raise SystemExit(result.returncode)


def build_client():
    from google.ads.googleads.client import GoogleAdsClient

    load_dotenv(ROOT / ".env")
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


def create_budget(client, customer_id: str, campaign_name: str, daily_budget_brl: float) -> str:
    service = client.get_service("CampaignBudgetService")
    operation = client.get_type("CampaignBudgetOperation")
    budget = operation.create
    budget.name = f"{campaign_name} Budget"
    budget.delivery_method = client.enums.BudgetDeliveryMethodEnum.STANDARD
    budget.amount_micros = micros(daily_budget_brl)
    budget.explicitly_shared = False
    response = service.mutate_campaign_budgets(customer_id=customer_id, operations=[operation])
    return response.results[0].resource_name


def create_campaign(client, customer_id: str, config: dict, budget_resource: str) -> str:
    service = client.get_service("CampaignService")
    campaign_service = client.get_service("CampaignService")
    operation = client.get_type("CampaignOperation")
    campaign = operation.create
    campaign.name = config["campaign"]["name"]
    campaign.advertising_channel_type = client.enums.AdvertisingChannelTypeEnum.SEARCH
    campaign.status = client.enums.CampaignStatusEnum.PAUSED
    campaign.campaign_budget = budget_resource
    campaign.manual_cpc.enhanced_cpc_enabled = False
    campaign.network_settings.target_google_search = True
    campaign.network_settings.target_search_network = False
    campaign.network_settings.target_content_network = False
    campaign.network_settings.target_partner_search_network = False
    campaign.tracking_url_template = "{lpurl}?gad_source=1"
    response = service.mutate_campaigns(customer_id=customer_id, operations=[operation])
    resource_name = response.results[0].resource_name
    # Validate resource name construction through the generated helper.
    campaign_service.campaign_path(customer_id, resource_name.rsplit("/", 1)[-1])
    return resource_name


def create_campaign_criteria(client, customer_id: str, campaign_resource: str, negative_keywords: list[str]) -> None:
    service = client.get_service("CampaignCriterionService")
    operations = []

    location_op = client.get_type("CampaignCriterionOperation")
    location = location_op.create
    location.campaign = campaign_resource
    location.location.geo_target_constant = client.get_service("GeoTargetConstantService").geo_target_constant_path("2076")
    operations.append(location_op)

    language_op = client.get_type("CampaignCriterionOperation")
    language = language_op.create
    language.campaign = campaign_resource
    language.language.language_constant = client.get_service("LanguageConstantService").language_constant_path("1014")
    operations.append(language_op)

    for text in negative_keywords:
        op = client.get_type("CampaignCriterionOperation")
        criterion = op.create
        criterion.campaign = campaign_resource
        criterion.negative = True
        criterion.keyword.text = text
        criterion.keyword.match_type = client.enums.KeywordMatchTypeEnum.BROAD
        operations.append(op)

    service.mutate_campaign_criteria(customer_id=customer_id, operations=operations)


def create_ad_group(client, customer_id: str, campaign_resource: str, config: dict) -> str:
    service = client.get_service("AdGroupService")
    operation = client.get_type("AdGroupOperation")
    ad_group = operation.create
    ad_group.name = config["ad_group"]["name"]
    ad_group.campaign = campaign_resource
    ad_group.status = client.enums.AdGroupStatusEnum.PAUSED
    ad_group.type_ = client.enums.AdGroupTypeEnum.SEARCH_STANDARD
    ad_group.cpc_bid_micros = micros(config["ad_group"]["default_cpc_brl"])
    response = service.mutate_ad_groups(customer_id=customer_id, operations=[operation])
    return response.results[0].resource_name


def create_keywords(client, customer_id: str, ad_group_resource: str, keywords: list[dict]) -> None:
    service = client.get_service("AdGroupCriterionService")
    operations = []
    for item in keywords:
        op = client.get_type("AdGroupCriterionOperation")
        criterion = op.create
        criterion.ad_group = ad_group_resource
        criterion.status = client.enums.AdGroupCriterionStatusEnum.ENABLED
        criterion.keyword.text = item["text"]
        criterion.keyword.match_type = getattr(client.enums.KeywordMatchTypeEnum, item["match_type"].upper())
        criterion.cpc_bid_micros = micros(item["cpc_brl"])
        operations.append(op)
    service.mutate_ad_group_criteria(customer_id=customer_id, operations=operations)


def create_responsive_search_ad(client, customer_id: str, ad_group_resource: str, config: dict) -> str:
    service = client.get_service("AdGroupAdService")
    operation = client.get_type("AdGroupAdOperation")
    ad_group_ad = operation.create
    ad_group_ad.ad_group = ad_group_resource
    ad_group_ad.status = client.enums.AdGroupAdStatusEnum.PAUSED
    ad_group_ad.ad.final_urls.append(final_url_with_utm(config["responsive_search_ad"]["final_url"], config["tracking"]))

    rsa = ad_group_ad.ad.responsive_search_ad
    for text in config["responsive_search_ad"]["headlines"]:
        asset = client.get_type("AdTextAsset")
        asset.text = text
        rsa.headlines.append(asset)
    for text in config["responsive_search_ad"]["descriptions"]:
        asset = client.get_type("AdTextAsset")
        asset.text = text
        rsa.descriptions.append(asset)

    response = service.mutate_ad_group_ads(customer_id=customer_id, operations=[operation])
    return response.results[0].resource_name


def main() -> int:
    parser = argparse.ArgumentParser(description="Create ShopVivaliz Google Ads Search campaign.")
    parser.add_argument("--create-paused", action="store_true", help="Actually create the campaign paused in Google Ads.")
    args = parser.parse_args()

    config = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    if not args.create_paused:
        print("DRY_RUN_ONLY")
        print("Use --create-paused to perform real Google Ads API mutations.")
        print(f"campaign={config['campaign']['name']}")
        print(f"daily_budget_brl={config['campaign']['daily_budget_brl']}")
        print(f"keywords={len(config['keywords'])}")
        return 0

    run_readiness()
    client = build_client()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "")

    budget_resource = create_budget(client, customer_id, config["campaign"]["name"], config["campaign"]["daily_budget_brl"])
    campaign_resource = create_campaign(client, customer_id, config, budget_resource)
    create_campaign_criteria(client, customer_id, campaign_resource, config["negative_keywords"])
    ad_group_resource = create_ad_group(client, customer_id, campaign_resource, config)
    create_keywords(client, customer_id, ad_group_resource, config["keywords"])
    ad_resource = create_responsive_search_ad(client, customer_id, ad_group_resource, config)

    print("CREATED_PAUSED")
    print(f"campaign_resource={campaign_resource}")
    print(f"budget_resource={budget_resource}")
    print(f"ad_group_resource={ad_group_resource}")
    print(f"ad_resource={ad_resource}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
