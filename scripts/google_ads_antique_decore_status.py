#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import re
import urllib.request
from datetime import date
from pathlib import Path

from google.ads.googleads.client import GoogleAdsClient

CAMPAIGN_NAME = "ShopVivaliz-Search-Vasos-Antique-Decore-2026-08"
CAMPAIGN_LAUNCH_DATE = "2026-08-15"
EXPECTED_GROUPS = {"Vasos Antique Japi", "Vasos Decore Japi"}
CONFIG_PATH = Path(__file__).with_name("google_ads_campaign_live_ready.json")
STALE_PROMO_RE = re.compile(
    r"VOLTEI5|VIVALIZ10|PRIMEIRA10|10%\s*(?:OFF|DE DESCONTO).*?(?:PRIMEIRA|1.?)\s*COMPRA|"
    r"5%\s*OFF\s*NA\s*1|5%\s*(?:OFF|DE DESCONTO).*?(?:PRIMEIRA|1.?)\s*COMPRA",
    re.IGNORECASE | re.DOTALL,
)


def load_guardrails():
    cfg = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    guardrails = cfg["guardrails"]
    budget_brl = float(guardrails["max_daily_budget_brl"])
    no_conversion_threshold_brl = float(guardrails["pause_if_spend_without_conversion_brl"])
    landing_urls = [g["responsive_search_ad"]["final_url"] for g in cfg["ad_groups"]]
    return budget_brl, no_conversion_threshold_brl, landing_urls


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


def pause_everything(client, customer_id, campaign_rn, groups, ads, reason):
    # Pause bottom-up so traffic cannot continue if a higher-level mutation is delayed.
    set_status(
        client,
        customer_id,
        "AdGroupAdService",
        "AdGroupAdOperation",
        [r.ad_group_ad.resource_name for r in ads],
        client.enums.AdGroupAdStatusEnum.PAUSED,
    )
    set_status(
        client,
        customer_id,
        "AdGroupService",
        "AdGroupOperation",
        [r.ad_group.resource_name for r in groups],
        client.enums.AdGroupStatusEnum.PAUSED,
    )
    set_status(
        client,
        customer_id,
        "CampaignService",
        "CampaignOperation",
        [campaign_rn],
        client.enums.CampaignStatusEnum.PAUSED,
    )
    print("ACTION=PAUSED")
    print("PAUSE_REASON=" + reason)
    print("RESULT=PAUSED_GUARD_FAILED")


def validate_landing_pages(urls):
    results = []
    for url in urls:
        req = urllib.request.Request(url, headers={"User-Agent": "ShopVivaliz-GoogleAds-Guard/1.0"})
        try:
            with urllib.request.urlopen(req, timeout=20) as resp:
                code = int(resp.status)
                body = resp.read(2_000_000).decode("utf-8", errors="replace")
        except Exception as exc:
            return False, f"LANDING_PAGE_GUARD_FAILED url={url} error={type(exc).__name__}:{exc}", results
        if code != 200:
            return False, f"LANDING_PAGE_GUARD_FAILED url={url} http={code}", results
        if STALE_PROMO_RE.search(body):
            return False, f"STALE_PROMO_CLAIM_GUARD_FAILED url={url}", results
        results.append((url, code))
    return True, "", results


def fetch_purchase_conversions(ga, customer_id):
    rows = list(ga.search(customer_id=customer_id, query="""
      SELECT conversion_action.id, conversion_action.resource_name,
             conversion_action.name, conversion_action.status,
             conversion_action.primary_for_goal, conversion_action.include_in_conversions_metric,
             conversion_action.type, conversion_action.origin, conversion_action.category
      FROM conversion_action
      WHERE conversion_action.status = 'ENABLED'
    """))
    return [r for r in rows if any(x in r.conversion_action.name.lower() for x in ("purchase", "compra", "pedido", "sale"))]


def metric_tuple(row):
    if row is None:
        return 0, 0, 0, 0.0
    return (
        int(row.metrics.impressions),
        int(row.metrics.clicks),
        int(row.metrics.cost_micros),
        float(row.metrics.conversions),
    )


def main() -> int:
    required = [
        "GOOGLE_OAUTH_CLIENT_ID",
        "GOOGLE_OAUTH_CLIENT_SECRET",
        "GOOGLE_ADS_REFRESH_TOKEN",
        "GOOGLE_ADS_DEVELOPER_TOKEN",
        "GOOGLE_ADS_CUSTOMER_ID",
    ]
    missing = [k for k in required if not os.getenv(k, "").strip()]
    if missing:
        raise SystemExit("MISSING_SECRETS=" + ",".join(missing))

    expected_budget_brl, no_conversion_threshold_brl, landing_urls = load_guardrails()
    expected_budget_micros = int(round(expected_budget_brl * 1_000_000))
    enable_if_approved = os.getenv("ENABLE_IF_APPROVED", "no").strip().lower() == "yes"

    client = client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")
    safe = CAMPAIGN_NAME.replace("'", "\\'")

    campaigns = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign.id, campaign.resource_name, campaign.name, campaign.status,
             campaign.campaign_budget, campaign_budget.amount_micros,
             campaign.contains_eu_political_advertising
      FROM campaign
      WHERE campaign.name = '{safe}'
        AND campaign.status != 'REMOVED'
    """))
    if len(campaigns) != 1:
        raise SystemExit("CAMPAIGN_INVARIANT_FAILED count=" + str(len(campaigns)))
    c = campaigns[0]
    campaign_id = int(c.campaign.id)

    groups = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group.id, ad_group.resource_name, ad_group.name, ad_group.status
      FROM ad_group
      WHERE campaign.id = {campaign_id} AND ad_group.status != 'REMOVED'
    """))
    ads = list(ga.search(customer_id=customer_id, query=f"""
      SELECT ad_group_ad.resource_name, ad_group_ad.ad.id, ad_group_ad.status,
             ad_group_ad.policy_summary.approval_status,
             ad_group_ad.policy_summary.review_status,
             ad_group_ad.ad.final_urls
      FROM ad_group_ad
      WHERE campaign.id = {campaign_id} AND ad_group_ad.status != 'REMOVED'
    """))

    def fail(reason):
        pause_everything(client, customer_id, c.campaign.resource_name, groups, ads, reason)
        return 0

    if int(c.campaign_budget.amount_micros) != expected_budget_micros:
        return fail(
            "BUDGET_GUARD_FAILED expected_brl=" + f"{expected_budget_brl:.2f}"
            + " actual_brl=" + f"{int(c.campaign_budget.amount_micros) / 1_000_000:.2f}"
        )
    if len(groups) != 2 or {g.ad_group.name for g in groups} != EXPECTED_GROUPS:
        return fail("AD_GROUP_INVARIANT_FAILED count=" + str(len(groups)))
    if len(ads) != 2:
        return fail("AD_INVARIANT_FAILED count=" + str(len(ads)))

    landing_ok, landing_reason, landing_results = validate_landing_pages(landing_urls)
    if not landing_ok:
        return fail(landing_reason)

    purchase_like = fetch_purchase_conversions(ga, customer_id)
    if not purchase_like:
        return fail("PURCHASE_CONVERSION_GUARD_FAILED no_enabled_purchase_conversion")

    primary_purchase = [r for r in purchase_like if bool(r.conversion_action.primary_for_goal)]
    if len(primary_purchase) != 1:
        return fail("PRIMARY_PURCHASE_CONVERSION_GUARD_FAILED count=" + str(len(primary_purchase)))
    primary = primary_purchase[0].conversion_action
    if not bool(primary.include_in_conversions_metric):
        return fail("PRIMARY_PURCHASE_CONVERSION_GUARD_FAILED included=false")
    if primary.type.name != "GOOGLE_ANALYTICS_4_PURCHASE" or primary.category.name != "PURCHASE":
        return fail(
            "PRIMARY_PURCHASE_CONVERSION_GUARD_FAILED type=" + primary.type.name
            + " category=" + primary.category.name
        )
    extra_counted = [
        r for r in purchase_like
        if r.conversion_action.resource_name != primary.resource_name
        and (bool(r.conversion_action.primary_for_goal) or bool(r.conversion_action.include_in_conversions_metric))
    ]
    if extra_counted:
        return fail("PURCHASE_CONVERSION_GUARD_FAILED extra_counted_purchase_actions=" + str(len(extra_counted)))

    policy = []
    for row in ads:
        ps = row.ad_group_ad.policy_summary
        approval = ps.approval_status.name
        review = ps.review_status.name
        urls = list(row.ad_group_ad.ad.final_urls)
        if not urls or any(not u.startswith("https://shopvivaliz.com.br/") for u in urls):
            return fail("FINAL_URL_GUARD_FAILED ad_id=" + str(row.ad_group_ad.ad.id))
        policy.append((str(row.ad_group_ad.ad.id), approval, review, row.ad_group_ad.status.name))
        if approval != "APPROVED" or review != "REVIEWED":
            return fail(
                "AD_POLICY_GUARD_FAILED ad_id=" + str(row.ad_group_ad.ad.id)
                + " approval=" + approval + " review=" + review
            )

    today_rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign.id, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions
      FROM campaign
      WHERE campaign.id = {campaign_id}
        AND segments.date DURING TODAY
    """))
    today_row = today_rows[0] if today_rows else None
    today_impressions, today_clicks, today_cost_micros, today_conversions = metric_tuple(today_row)

    start_date = CAMPAIGN_LAUNCH_DATE
    end_date = date.today().isoformat()
    lifetime_rows = list(ga.search(customer_id=customer_id, query=f"""
      SELECT campaign.id, metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions
      FROM campaign
      WHERE campaign.id = {campaign_id}
        AND segments.date BETWEEN '{start_date}' AND '{end_date}'
    """))
    lifetime_row = lifetime_rows[0] if lifetime_rows else None
    lifetime_impressions, lifetime_clicks, lifetime_cost_micros, lifetime_conversions = metric_tuple(lifetime_row)
    lifetime_cost_brl = lifetime_cost_micros / 1_000_000

    print("campaign_id=" + str(campaign_id))
    print("campaign_status_before=" + c.campaign.status.name)
    print("daily_budget_brl=" + f"{expected_budget_brl:.2f}")
    print("impressions_today=" + str(today_impressions))
    print("clicks_today=" + str(today_clicks))
    print("cost_brl_today=" + f"{today_cost_micros / 1_000_000:.2f}")
    print("purchase_conversions_today=" + str(today_conversions))
    print("impressions_lifetime=" + str(lifetime_impressions))
    print("clicks_lifetime=" + str(lifetime_clicks))
    print("cost_brl_lifetime=" + f"{lifetime_cost_brl:.2f}")
    print("purchase_conversions_lifetime=" + str(lifetime_conversions))
    print("no_conversion_pause_threshold_brl=" + f"{no_conversion_threshold_brl:.2f}")
    for url, code in landing_results:
        print(f"landing_page={url}:http={code}:healthy=true")
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

    if lifetime_cost_brl >= no_conversion_threshold_brl and lifetime_conversions <= 0:
        return fail(
            "NO_CONVERSION_SPEND_GUARD_FAILED spend_brl=" + f"{lifetime_cost_brl:.2f}"
            + " threshold_brl=" + f"{no_conversion_threshold_brl:.2f}"
            + " purchase_conversions=" + str(lifetime_conversions)
        )

    if not enable_if_approved:
        print("RESULT=HEALTHY_READY_TO_ENABLE")
        return 0

    set_status(
        client,
        customer_id,
        "AdGroupAdService",
        "AdGroupAdOperation",
        [r.ad_group_ad.resource_name for r in ads],
        client.enums.AdGroupAdStatusEnum.ENABLED,
    )
    set_status(
        client,
        customer_id,
        "AdGroupService",
        "AdGroupOperation",
        [r.ad_group.resource_name for r in groups],
        client.enums.AdGroupStatusEnum.ENABLED,
    )
    set_status(
        client,
        customer_id,
        "CampaignService",
        "CampaignOperation",
        [c.campaign.resource_name],
        client.enums.CampaignStatusEnum.ENABLED,
    )
    print("campaign_status_final=ENABLED")
    print("RESULT=HEALTHY_ENABLED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
