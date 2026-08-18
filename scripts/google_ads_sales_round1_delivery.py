#!/usr/bin/env python3
from __future__ import annotations

import json
import os
from pathlib import Path

from google.ads.googleads.client import GoogleAdsClient

CONFIG_PATH = Path(__file__).with_name("google_ads_campaign_live_ready.json")


def client_from_env() -> GoogleAdsClient:
    refresh = os.getenv("GOOGLE_ADS_REFRESH_TOKEN", "").strip() or os.getenv("GOOGLE_OAUTH_REFRESH_TOKEN", "").strip()
    cfg = {
        "developer_token": os.environ["GOOGLE_ADS_DEVELOPER_TOKEN"],
        "client_id": os.environ["GOOGLE_OAUTH_CLIENT_ID"],
        "client_secret": os.environ["GOOGLE_OAUTH_CLIENT_SECRET"],
        "refresh_token": refresh,
        "use_proto_plus": True,
    }
    login = os.getenv("GOOGLE_ADS_LOGIN_CUSTOMER_ID", "").replace("-", "").strip()
    if login:
        cfg["login_customer_id"] = login
    return GoogleAdsClient.load_from_dict(cfg)


def norm(value: str) -> str:
    return " ".join(value.strip().casefold().split())


def main() -> int:
    required = [
        "GOOGLE_OAUTH_CLIENT_ID",
        "GOOGLE_OAUTH_CLIENT_SECRET",
        "GOOGLE_ADS_DEVELOPER_TOKEN",
        "GOOGLE_ADS_CUSTOMER_ID",
    ]
    missing = [key for key in required if not os.getenv(key, "").strip()]
    if not (os.getenv("GOOGLE_ADS_REFRESH_TOKEN", "").strip() or os.getenv("GOOGLE_OAUTH_REFRESH_TOKEN", "").strip()):
        missing.append("GOOGLE_ADS_REFRESH_TOKEN_OR_GOOGLE_OAUTH_REFRESH_TOKEN")
    if missing:
        raise SystemExit("MISSING_SECRETS=" + ",".join(missing))

    cfg = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    campaign_cfg = cfg["campaign"]
    guardrails = cfg["guardrails"]
    daily_budget_micros = int(round(float(campaign_cfg["daily_budget_brl"]) * 1_000_000))
    if daily_budget_micros > int(round(float(guardrails["max_daily_budget_brl"]) * 1_000_000)):
        raise SystemExit("GUARDRAIL_DAILY_BUDGET_EXCEEDED")
    if float(campaign_cfg["bidding"]["max_cpc_brl"]) > float(guardrails["max_cpc_brl"]):
        raise SystemExit("GUARDRAIL_MAX_CPC_EXCEEDED")

    client = client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")
    campaign_name = str(campaign_cfg["name"]).replace("'", "\\'")
    campaigns = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT campaign.id, campaign.name, campaign.status,
                     campaign.campaign_budget, campaign_budget.amount_micros
              FROM campaign
              WHERE campaign.name = '{campaign_name}'
                AND campaign.status != 'REMOVED'
            """,
        )
    )
    if len(campaigns) != 1:
        raise SystemExit("CAMPAIGN_INVARIANT_FAILED count=" + str(len(campaigns)))
    campaign = campaigns[0]
    campaign_id = int(campaign.campaign.id)
    if int(campaign.campaign_budget.amount_micros) != daily_budget_micros:
        raise SystemExit(
            "BUDGET_GUARD_FAILED actual_brl="
            + f"{int(campaign.campaign_budget.amount_micros) / 1_000_000:.2f}"
            + " expected_brl="
            + f"{daily_budget_micros / 1_000_000:.2f}"
        )

    rows = list(
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
    live_groups = {row.ad_group.name: row for row in rows}
    expected_names = {group["name"] for group in cfg["ad_groups"]}
    if set(live_groups) != expected_names:
        raise SystemExit("AD_GROUP_INVARIANT_FAILED")

    ad_group_ops = []
    for group_cfg in cfg["ad_groups"]:
        row = live_groups[group_cfg["name"]]
        desired = int(round(float(group_cfg["default_cpc_brl"]) * 1_000_000))
        if desired > int(round(float(guardrails["max_cpc_brl"]) * 1_000_000)):
            raise SystemExit("GROUP_CPC_GUARD_FAILED group=" + group_cfg["name"])
        if int(row.ad_group.cpc_bid_micros) != desired:
            op = client.get_type("AdGroupOperation")
            op.update.resource_name = row.ad_group.resource_name
            op.update.cpc_bid_micros = desired
            op.update_mask.paths.append("cpc_bid_micros")
            ad_group_ops.append(op)
    if ad_group_ops:
        client.get_service("AdGroupService").mutate_ad_groups(customer_id=customer_id, operations=ad_group_ops)

    criteria = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT ad_group.id, ad_group_criterion.resource_name,
                     ad_group_criterion.status, ad_group_criterion.negative,
                     ad_group_criterion.keyword.text,
                     ad_group_criterion.keyword.match_type,
                     ad_group_criterion.cpc_bid_micros
              FROM keyword_view
              WHERE campaign.id = {campaign_id}
                AND ad_group_criterion.status != 'REMOVED'
            """,
        )
    )
    existing: dict[tuple[int, str, str], object] = {}
    for row in criteria:
        criterion = row.ad_group_criterion
        existing[(int(row.ad_group.id), norm(criterion.keyword.text), criterion.keyword.match_type.name)] = row

    criterion_ops = []
    created = 0
    rebid = 0
    for group_cfg in cfg["ad_groups"]:
        group_row = live_groups[group_cfg["name"]]
        group_id = int(group_row.ad_group.id)
        for keyword in group_cfg["keywords"]:
            text = str(keyword["text"]).strip()
            match_type = str(keyword["match_type"]).upper()
            if match_type not in {"EXACT", "PHRASE"}:
                raise SystemExit("BROAD_MATCH_GUARD_FAILED keyword=" + text)
            desired = int(round(float(keyword["cpc_brl"]) * 1_000_000))
            if desired > int(round(float(guardrails["max_cpc_brl"]) * 1_000_000)):
                raise SystemExit("KEYWORD_CPC_GUARD_FAILED keyword=" + text)
            key = (group_id, norm(text), match_type)
            current = existing.get(key)
            if current is None:
                op = client.get_type("AdGroupCriterionOperation")
                op.create.ad_group = group_row.ad_group.resource_name
                op.create.status = client.enums.AdGroupCriterionStatusEnum.ENABLED
                op.create.keyword.text = text
                op.create.keyword.match_type = getattr(client.enums.KeywordMatchTypeEnum, match_type)
                op.create.cpc_bid_micros = desired
                criterion_ops.append(op)
                created += 1
                continue
            criterion = current.ad_group_criterion
            if bool(criterion.negative):
                raise SystemExit("POSITIVE_KEYWORD_COLLIDES_WITH_NEGATIVE keyword=" + text)
            if int(criterion.cpc_bid_micros) != desired or criterion.status.name != "ENABLED":
                op = client.get_type("AdGroupCriterionOperation")
                op.update.resource_name = criterion.resource_name
                op.update.cpc_bid_micros = desired
                op.update.status = client.enums.AdGroupCriterionStatusEnum.ENABLED
                op.update_mask.paths.extend(["cpc_bid_micros", "status"])
                criterion_ops.append(op)
                rebid += 1

    if criterion_ops:
        client.get_service("AdGroupCriterionService").mutate_ad_group_criteria(
            customer_id=customer_id,
            operations=criterion_ops,
        )

    print("campaign_id=" + str(campaign_id))
    print("daily_budget_brl=" + f"{daily_budget_micros / 1_000_000:.2f}")
    print("max_cpc_guard_brl=" + f"{float(guardrails['max_cpc_brl']):.2f}")
    print("ad_groups_rebid=" + str(len(ad_group_ops)))
    print("keywords_created=" + str(created))
    print("keywords_rebid_or_enabled=" + str(rebid))
    print("RESULT=SALES_ROUND_1_DELIVERY_TUNED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
