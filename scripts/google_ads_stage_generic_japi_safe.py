#!/usr/bin/env python3
from __future__ import annotations

import os
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

import google_ads_expand_generic_japi as legacy

CAMPAIGN_ID = legacy.CAMPAIGN_ID
CAMPAIGN_NAME = legacy.CAMPAIGN_NAME
EXPECTED_BUDGET_MICROS = legacy.EXPECTED_BUDGET_MICROS
GROUP_NAME = legacy.GROUP_NAME
MAX_CPC_MICROS = legacy.MAX_CPC_MICROS

SAFE_RSA = {
    "final_url": "https://shopvivaliz.com.br/catalogo/?q=japi",
    "path1": "vasos",
    "path2": "japi",
    "headlines": [
        "Vasos Japi para Casa",
        "Compre Vasos Japi Online",
        "Vasos para Casa e Jardim",
        "Modelos Japi na Vivaliz",
        "Compra Segura na Vivaliz",
        "Frete Calculado no CEP",
        "Veja Opções Japi",
    ],
    "descriptions": [
        "Encontre vasos Japi para casa e jardim e confira a disponibilidade atual na Vivaliz.",
        "Compare modelos, tamanhos e acabamentos Japi. Frete calculado pelo seu CEP no checkout.",
        "Compre online com pagamento seguro e suporte comercial antes e depois do pedido.",
    ],
}


def fail(message: str) -> None:
    raise SystemExit("JAPI_SAFE_BLOCKED=" + message)


def safe_final_url() -> str:
    split = urlsplit(SAFE_RSA["final_url"])
    if split.scheme != "https" or split.hostname != "shopvivaliz.com.br":
        fail("FINAL_URL_HOST")
    query = dict(parse_qsl(split.query, keep_blank_values=True))
    query.update({
        "utm_source": "google",
        "utm_medium": "cpc",
        "utm_campaign": "search_vasos_antique_decore_2026_08",
        "utm_content": "vasos_japi_generico_safe",
    })
    query.pop("cupom", None)
    return urlunsplit((split.scheme, split.netloc, split.path, urlencode(query), split.fragment))


def pause_group(client, customer_id: str, resource_name: str) -> None:
    op = client.get_type("AdGroupOperation")
    op.update.resource_name = resource_name
    op.update.status = client.enums.AdGroupStatusEnum.PAUSED
    op.update_mask.paths.append("status")
    client.get_service("AdGroupService").mutate_ad_groups(customer_id=customer_id, operations=[op])


def pause_ad(client, customer_id: str, resource_name: str) -> None:
    op = client.get_type("AdGroupAdOperation")
    op.update.resource_name = resource_name
    op.update.status = client.enums.AdGroupAdStatusEnum.PAUSED
    op.update_mask.paths.append("status")
    client.get_service("AdGroupAdService").mutate_ad_group_ads(customer_id=customer_id, operations=[op])


def create_paused_rsa(client, customer_id: str, ad_group_resource: str, final_url: str) -> str:
    op = client.get_type("AdGroupAdOperation")
    aga = op.create
    aga.ad_group = ad_group_resource
    aga.status = client.enums.AdGroupAdStatusEnum.PAUSED
    aga.ad.final_urls.append(final_url)
    rsa = aga.ad.responsive_search_ad
    rsa.path1 = SAFE_RSA["path1"]
    rsa.path2 = SAFE_RSA["path2"]
    for text in SAFE_RSA["headlines"]:
        asset = client.get_type("AdTextAsset")
        asset.text = text
        rsa.headlines.append(asset)
    for text in SAFE_RSA["descriptions"]:
        asset = client.get_type("AdTextAsset")
        asset.text = text
        rsa.descriptions.append(asset)
    response = client.get_service("AdGroupAdService").mutate_ad_group_ads(
        customer_id=customer_id,
        operations=[op],
    )
    return response.results[0].resource_name


def main() -> int:
    required = [
        "GOOGLE_ADS_DEVELOPER_TOKEN",
        "GOOGLE_OAUTH_CLIENT_ID",
        "GOOGLE_OAUTH_CLIENT_SECRET",
        "GOOGLE_ADS_REFRESH_TOKEN",
        "GOOGLE_ADS_CUSTOMER_ID",
    ]
    missing = [key for key in required if not os.getenv(key, "").strip()]
    if missing:
        fail("MISSING_ENV:" + ",".join(missing))

    client = legacy.client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()

    campaigns = legacy.search(client, customer_id, f"""
        SELECT campaign.id, campaign.name, campaign.status, campaign_budget.amount_micros
        FROM campaign
        WHERE campaign.id = {CAMPAIGN_ID}
          AND campaign.status != 'REMOVED'
    """)
    if len(campaigns) != 1:
        fail("CAMPAIGN_NOT_UNIQUE")
    campaign = campaigns[0]
    if campaign.campaign.name != CAMPAIGN_NAME:
        fail("CAMPAIGN_NAME_MISMATCH")
    if campaign.campaign.status.name != "ENABLED":
        fail("CORE_CAMPAIGN_NOT_ENABLED")
    if int(campaign.campaign_budget.amount_micros) != EXPECTED_BUDGET_MICROS:
        fail("BUDGET_CHANGED")

    groups = legacy.search(client, customer_id, f"""
        SELECT ad_group.id, ad_group.resource_name, ad_group.name, ad_group.status, ad_group.cpc_bid_micros
        FROM ad_group
        WHERE campaign.id = {CAMPAIGN_ID}
          AND ad_group.status != 'REMOVED'
    """)
    by_name = {row.ad_group.name: row.ad_group for row in groups}
    for core in ("Vasos Antique Japi", "Vasos Decore Japi"):
        if core not in by_name or by_name[core].status.name != "ENABLED":
            fail("CORE_GROUP_INVALID:" + core)
    group = by_name.get(GROUP_NAME)
    if group is None:
        fail("JAPI_GROUP_MISSING")
    if int(group.cpc_bid_micros or 0) > MAX_CPC_MICROS:
        fail("JAPI_GROUP_CPC_OVER_CAP")

    criteria = legacy.search(client, customer_id, f"""
        SELECT ad_group_criterion.keyword.text,
               ad_group_criterion.keyword.match_type,
               ad_group_criterion.negative,
               ad_group_criterion.status,
               ad_group_criterion.cpc_bid_micros
        FROM ad_group_criterion
        WHERE ad_group.id = {int(group.id)}
          AND ad_group_criterion.status != 'REMOVED'
    """)
    positive = set()
    negatives = set()
    for row in criteria:
        criterion = row.ad_group_criterion
        text = criterion.keyword.text.strip().casefold()
        if not text:
            continue
        if criterion.negative:
            negatives.add(text)
            continue
        match = criterion.keyword.match_type.name
        if match == "BROAD":
            fail("POSITIVE_BROAD:" + text)
        if int(criterion.cpc_bid_micros or 0) > MAX_CPC_MICROS:
            fail("KEYWORD_CPC_OVER_CAP:" + text)
        positive.add((text, match))

    for item in legacy.GROUP["keywords"]:
        if (item["text"].casefold(), item["match_type"]) not in positive:
            fail("KEYWORD_MISSING:" + item["text"])
    for negative in ("antique", "decore"):
        if negative not in negatives:
            fail("NEGATIVE_MISSING:" + negative)

    ads = legacy.search(client, customer_id, f"""
        SELECT ad_group_ad.resource_name, ad_group_ad.status,
               ad_group_ad.ad.id, ad_group_ad.ad.final_urls,
               ad_group_ad.policy_summary.approval_status,
               ad_group_ad.policy_summary.review_status
        FROM ad_group_ad
        WHERE ad_group.id = {int(group.id)}
          AND ad_group_ad.status != 'REMOVED'
    """)

    if group.status.name != "PAUSED":
        pause_group(client, customer_id, group.resource_name)
    for row in ads:
        if row.ad_group_ad.status.name == "ENABLED":
            pause_ad(client, customer_id, row.ad_group_ad.resource_name)

    final_url = safe_final_url()
    matching = next(
        (row.ad_group_ad for row in ads if final_url in list(row.ad_group_ad.ad.final_urls)),
        None,
    )
    if matching is None:
        resource = create_paused_rsa(client, customer_id, group.resource_name, final_url)
        print("JAPI_SAFE_RSA_CREATED=" + resource)
        print("JAPI_SAFE_FINAL_URL=" + final_url)
        print("ACTION=STAGED_PAUSED")
        print("RESULT=WAITING_GOOGLE_REVIEW")
        return 0

    approval = matching.policy_summary.approval_status.name
    review = matching.policy_summary.review_status.name
    print("JAPI_SAFE_RSA_ID=" + str(matching.ad.id))
    print("JAPI_SAFE_RSA_APPROVAL=" + approval)
    print("JAPI_SAFE_RSA_REVIEW=" + review)
    print("JAPI_SAFE_FINAL_URL=" + final_url)

    if approval == "APPROVED" and review == "REVIEWED":
        legacy.enable_ad_group(client, customer_id, group.resource_name)
        legacy.enable_ad(client, customer_id, matching.resource_name)
        print("ACTION=ENABLED_APPROVED_JAPI_ONLY")
        print("RESULT=SAFE_JAPI_EXPANSION_LIVE")
        return 0

    if approval == "DISAPPROVED":
        print("ACTION=KEPT_PAUSED")
        print("RESULT=JAPI_RSA_DISAPPROVED")
        return 0

    print("ACTION=KEPT_PAUSED")
    print("RESULT=WAITING_GOOGLE_REVIEW")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
