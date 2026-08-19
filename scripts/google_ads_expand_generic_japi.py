#!/usr/bin/env python3
from __future__ import annotations

import os

from google.ads.googleads.client import GoogleAdsClient

import google_ads_create_search_campaign as creator

CAMPAIGN_ID = 24144257692
CAMPAIGN_NAME = "ShopVivaliz-Search-Vasos-Antique-Decore-2026-08"
EXPECTED_BUDGET_MICROS = 10_000_000
GROUP_NAME = "Vasos Japi"
DEFAULT_CPC_BRL = 1.00
MAX_CPC_MICROS = 1_200_000

GROUP = {
    "name": GROUP_NAME,
    "default_cpc_brl": DEFAULT_CPC_BRL,
    "tracking_content": "vasos_japi_generico",
    "keywords": [
        {"text": "vaso japi", "match_type": "EXACT", "cpc_brl": DEFAULT_CPC_BRL},
        {"text": "vaso japi", "match_type": "PHRASE", "cpc_brl": DEFAULT_CPC_BRL},
        {"text": "vasos japi", "match_type": "EXACT", "cpc_brl": DEFAULT_CPC_BRL},
        {"text": "vasos japi", "match_type": "PHRASE", "cpc_brl": DEFAULT_CPC_BRL},
        {"text": "vaso decorativo japi", "match_type": "EXACT", "cpc_brl": DEFAULT_CPC_BRL},
        {"text": "vaso decorativo japi", "match_type": "PHRASE", "cpc_brl": DEFAULT_CPC_BRL},
        {"text": "vasos decorativos japi", "match_type": "PHRASE", "cpc_brl": DEFAULT_CPC_BRL},
        {"text": "vaso para plantas japi", "match_type": "PHRASE", "cpc_brl": DEFAULT_CPC_BRL},
    ],
    "negative_keywords": ["antique", "decore"],
    "responsive_search_ad": {
        "final_url": "https://shopvivaliz.com.br/catalogo/?q=japi",
        "path1": "vasos",
        "path2": "japi",
        "headlines": [
            "Vasos Japi Originais",
            "Compre Vasos Japi Online",
            "Vasos para Casa e Jardim",
            "Modelos Japi em Estoque",
            "10% OFF com VIVALIZ10",
            "Checkout Seguro",
            "Frete Calculado no CEP",
        ],
        "descriptions": [
            "Encontre vasos Japi para casa e jardim. Estoque atual e compra segura na Vivaliz.",
            "Use VIVALIZ10 e ganhe 10% de desconto. Frete calculado pelo seu CEP no checkout.",
            "Compare modelos, tamanhos e acabamentos Japi com preço e disponibilidade atualizados.",
        ],
    },
}
TRACKING = {
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "search_vasos_antique_decore_2026_08",
    "coupon": "VIVALIZ10",
}


def client_from_env() -> GoogleAdsClient:
    required = [
        "GOOGLE_ADS_DEVELOPER_TOKEN",
        "GOOGLE_OAUTH_CLIENT_ID",
        "GOOGLE_OAUTH_CLIENT_SECRET",
        "GOOGLE_ADS_REFRESH_TOKEN",
        "GOOGLE_ADS_CUSTOMER_ID",
    ]
    missing = [key for key in required if not os.getenv(key, "").strip()]
    if missing:
        raise SystemExit("MISSING_ENV=" + ",".join(missing))
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


def search(client: GoogleAdsClient, customer_id: str, query: str):
    return list(client.get_service("GoogleAdsService").search(customer_id=customer_id, query=query))


def enable_ad_group(client: GoogleAdsClient, customer_id: str, resource_name: str) -> None:
    op = client.get_type("AdGroupOperation")
    op.update.resource_name = resource_name
    op.update.status = client.enums.AdGroupStatusEnum.ENABLED
    op.update_mask.paths.append("status")
    client.get_service("AdGroupService").mutate_ad_groups(customer_id=customer_id, operations=[op])


def enable_ad(client: GoogleAdsClient, customer_id: str, resource_name: str) -> None:
    op = client.get_type("AdGroupAdOperation")
    op.update.resource_name = resource_name
    op.update.status = client.enums.AdGroupAdStatusEnum.ENABLED
    op.update_mask.paths.append("status")
    client.get_service("AdGroupAdService").mutate_ad_group_ads(customer_id=customer_id, operations=[op])


def main() -> int:
    client = client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()

    campaign_rows = search(client, customer_id, f"""
        SELECT campaign.id, campaign.resource_name, campaign.name, campaign.status,
               campaign_budget.amount_micros
        FROM campaign
        WHERE campaign.id = {CAMPAIGN_ID}
          AND campaign.status != 'REMOVED'
    """)
    if len(campaign_rows) != 1:
        raise SystemExit("GUARDRAIL_CAMPAIGN_NOT_UNIQUE")
    campaign = campaign_rows[0]
    if campaign.campaign.name != CAMPAIGN_NAME:
        raise SystemExit("GUARDRAIL_CAMPAIGN_NAME_MISMATCH")
    if campaign.campaign.status.name != "ENABLED":
        raise SystemExit("GUARDRAIL_CAMPAIGN_NOT_ENABLED")
    if int(campaign.campaign_budget.amount_micros) != EXPECTED_BUDGET_MICROS:
        raise SystemExit("GUARDRAIL_BUDGET_CHANGED")

    known_groups = search(client, customer_id, f"""
        SELECT ad_group.resource_name, ad_group.id, ad_group.name, ad_group.status,
               ad_group.cpc_bid_micros
        FROM ad_group
        WHERE campaign.id = {CAMPAIGN_ID}
          AND ad_group.status != 'REMOVED'
    """)
    by_name = {row.ad_group.name: row.ad_group for row in known_groups}
    for required_name in ("Vasos Antique Japi", "Vasos Decore Japi"):
        if required_name not in by_name:
            raise SystemExit("GUARDRAIL_MISSING_EXISTING_GROUP=" + required_name)

    group = by_name.get(GROUP_NAME)
    if group is None:
        group_resource = creator.create_ad_group(
            client, customer_id, campaign.campaign.resource_name, GROUP
        )
        enable_ad_group(client, customer_id, group_resource)
        print("CREATED_GROUP=" + group_resource)
    else:
        group_resource = group.resource_name
        if int(group.cpc_bid_micros or 0) > MAX_CPC_MICROS:
            raise SystemExit("GUARDRAIL_EXISTING_GROUP_CPC_OVER_CAP")
        if group.status.name == "PAUSED":
            enable_ad_group(client, customer_id, group_resource)
        elif group.status.name != "ENABLED":
            raise SystemExit("GUARDRAIL_EXISTING_GROUP_STATUS=" + group.status.name)
        print("REUSING_GROUP=" + group_resource)

    group_id = int(group_resource.rsplit("/", 1)[-1])
    criterion_rows = search(client, customer_id, f"""
        SELECT ad_group_criterion.keyword.text,
               ad_group_criterion.keyword.match_type,
               ad_group_criterion.negative,
               ad_group_criterion.status,
               ad_group_criterion.cpc_bid_micros
        FROM ad_group_criterion
        WHERE ad_group.id = {group_id}
          AND ad_group_criterion.status != 'REMOVED'
    """)
    positive = set()
    negatives = set()
    for row in criterion_rows:
        criterion = row.ad_group_criterion
        text = criterion.keyword.text.strip().casefold()
        if not text:
            continue
        if criterion.negative:
            negatives.add(text)
        else:
            if criterion.keyword.match_type.name == "BROAD":
                raise SystemExit("GUARDRAIL_POSITIVE_BROAD_FOUND=" + text)
            if int(criterion.cpc_bid_micros or 0) > MAX_CPC_MICROS:
                raise SystemExit("GUARDRAIL_KEYWORD_CPC_OVER_CAP=" + text)
            positive.add((text, criterion.keyword.match_type.name))

    missing_keywords = [
        item for item in GROUP["keywords"]
        if (item["text"].casefold(), item["match_type"]) not in positive
    ]
    if missing_keywords:
        creator.create_keywords(client, customer_id, group_resource, missing_keywords)
        print("CREATED_KEYWORDS=" + str(len(missing_keywords)))
    else:
        print("CREATED_KEYWORDS=0")

    missing_negatives = [
        text for text in GROUP["negative_keywords"] if text.casefold() not in negatives
    ]
    if missing_negatives:
        creator.create_ad_group_negatives(client, customer_id, group_resource, missing_negatives)
        print("CREATED_NEGATIVES=" + str(len(missing_negatives)))

    ad_rows = search(client, customer_id, f"""
        SELECT ad_group_ad.resource_name, ad_group_ad.status,
               ad_group_ad.ad.final_urls,
               ad_group_ad.policy_summary.approval_status,
               ad_group_ad.policy_summary.review_status
        FROM ad_group_ad
        WHERE ad_group.id = {group_id}
          AND ad_group_ad.status != 'REMOVED'
    """)
    expected_url = creator.final_url_with_utm(
        GROUP["responsive_search_ad"]["final_url"], TRACKING, GROUP["tracking_content"]
    )
    matching_ad = None
    for row in ad_rows:
        urls = list(row.ad_group_ad.ad.final_urls)
        if expected_url in urls:
            matching_ad = row.ad_group_ad
            break
    if matching_ad is None:
        ad_resource, _ = creator.create_responsive_search_ad(
            client, customer_id, group_resource, GROUP, TRACKING
        )
        enable_ad(client, customer_id, ad_resource)
        print("CREATED_AD=" + ad_resource)
    else:
        if matching_ad.status.name == "PAUSED":
            enable_ad(client, customer_id, matching_ad.resource_name)
        elif matching_ad.status.name != "ENABLED":
            raise SystemExit("GUARDRAIL_EXISTING_AD_STATUS=" + matching_ad.status.name)
        print("REUSING_AD=" + matching_ad.resource_name)

    post_campaign = search(client, customer_id, f"""
        SELECT campaign.status, campaign_budget.amount_micros
        FROM campaign WHERE campaign.id = {CAMPAIGN_ID}
    """)[0]
    if int(post_campaign.campaign_budget.amount_micros) != EXPECTED_BUDGET_MICROS:
        raise SystemExit("POST_GUARDRAIL_BUDGET_CHANGED")

    post_groups = search(client, customer_id, f"""
        SELECT ad_group.id, ad_group.name, ad_group.status, ad_group.cpc_bid_micros
        FROM ad_group
        WHERE campaign.id = {CAMPAIGN_ID}
          AND ad_group.status != 'REMOVED'
    """)
    post = [row.ad_group for row in post_groups if row.ad_group.name == GROUP_NAME]
    if len(post) != 1 or post[0].status.name != "ENABLED":
        raise SystemExit("POST_GUARDRAIL_GENERIC_GROUP_INVALID")
    if int(post[0].cpc_bid_micros or 0) > MAX_CPC_MICROS:
        raise SystemExit("POST_GUARDRAIL_GROUP_CPC_OVER_CAP")

    post_keywords = search(client, customer_id, f"""
        SELECT ad_group_criterion.keyword.text,
               ad_group_criterion.keyword.match_type,
               ad_group_criterion.negative,
               ad_group_criterion.cpc_bid_micros
        FROM ad_group_criterion
        WHERE ad_group.id = {group_id}
          AND ad_group_criterion.status != 'REMOVED'
    """)
    positive_post = {
        (row.ad_group_criterion.keyword.text.strip().casefold(), row.ad_group_criterion.keyword.match_type.name)
        for row in post_keywords if not row.ad_group_criterion.negative
    }
    for text, match in positive_post:
        if match == "BROAD":
            raise SystemExit("POST_GUARDRAIL_POSITIVE_BROAD=" + text)
    for item in GROUP["keywords"]:
        if (item["text"].casefold(), item["match_type"]) not in positive_post:
            raise SystemExit("POST_GUARDRAIL_KEYWORD_MISSING=" + item["text"])

    print("POST_GUARDRAILS_OK budget_micros=10000000 max_cpc_micros=1200000")
    print("GENERIC_GROUP_NAME=" + GROUP_NAME)
    print("GENERIC_FINAL_URL=" + expected_url)
    print("RESULT=GENERIC_JAPI_EXPANSION_APPLIED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
