#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import re
import urllib.request
from datetime import datetime
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from zoneinfo import ZoneInfo

from google.ads.googleads.client import GoogleAdsClient

CAMPAIGN_NAME = "ShopVivaliz-Search-Vasos-Antique-Decore-2026-08"
CAMPAIGN_LAUNCH_DATE = "2026-08-15"
EXPECTED_GROUPS = {"Vasos Antique Japi", "Vasos Decore Japi"}
EXPECTED_DAILY_BUDGET_MICROS = 10_000_000
NO_CONVERSION_PAUSE_THRESHOLD_BRL = 12.00
CONFIG_PATH = Path(__file__).with_name("google_ads_campaign_live_ready.json")
ALLOWED_HOST = "shopvivaliz.com.br"
STALE_PROMO_RE = re.compile(
    r"\b(?:VOLTEI5|VIVALIZ10|PRIMEIRA10)\b|"
    r"10%\s*(?:OFF|DE DESCONTO).*?(?:PRIMEIRA|1.?)\s*COMPRA|"
    r"5%\s*OFF\s*NA\s*1|5%\s*(?:OFF|DE DESCONTO).*?(?:PRIMEIRA|1.?)\s*COMPRA",
    re.IGNORECASE | re.DOTALL,
)


class VisibleTextParser(HTMLParser):
    """Extract only browser-visible text; ignore script/style/template source."""

    HIDDEN = {"script", "style", "noscript", "template", "svg"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hidden_depth = 0
        self.parts: list[str] = []

    def handle_starttag(self, tag: str, attrs) -> None:
        if tag.lower() in self.HIDDEN:
            self.hidden_depth += 1

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() in self.HIDDEN and self.hidden_depth > 0:
            self.hidden_depth -= 1

    def handle_data(self, data: str) -> None:
        if self.hidden_depth == 0:
            text = data.strip()
            if text:
                self.parts.append(text)

    def text(self) -> str:
        return " ".join(self.parts)


def visible_text(html: str) -> str:
    parser = VisibleTextParser()
    parser.feed(html)
    return parser.text()


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


def set_status(client, customer_id: str, service_name: str, operation_name: str, resource_names: list[str], status) -> None:
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


def pause_everything(client, customer_id: str, campaign_rn: str, groups, ads, reason: str) -> None:
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


def enable_everything(client, customer_id: str, campaign_rn: str, campaign_status: str, groups, ads) -> bool:
    changed = False
    ad_rns = [r.ad_group_ad.resource_name for r in ads if r.ad_group_ad.status.name != "ENABLED"]
    group_rns = [r.ad_group.resource_name for r in groups if r.ad_group.status.name != "ENABLED"]
    if ad_rns:
        set_status(client, customer_id, "AdGroupAdService", "AdGroupAdOperation", ad_rns, client.enums.AdGroupAdStatusEnum.ENABLED)
        changed = True
    if group_rns:
        set_status(client, customer_id, "AdGroupService", "AdGroupOperation", group_rns, client.enums.AdGroupStatusEnum.ENABLED)
        changed = True
    if campaign_status != "ENABLED":
        set_status(client, customer_id, "CampaignService", "CampaignOperation", [campaign_rn], client.enums.CampaignStatusEnum.ENABLED)
        changed = True
    return changed


def ensure_budget(client, customer_id: str, budget_rn: str, actual_micros: int) -> bool:
    if actual_micros == EXPECTED_DAILY_BUDGET_MICROS:
        return False
    op = client.get_type("CampaignBudgetOperation")
    op.update.resource_name = budget_rn
    op.update.amount_micros = EXPECTED_DAILY_BUDGET_MICROS
    op.update_mask.paths.append("amount_micros")
    client.get_service("CampaignBudgetService").mutate_campaign_budgets(customer_id=customer_id, operations=[op])
    print(f"ACTION=BUDGET_CORRECTED old_brl={actual_micros / 1_000_000:.2f} new_brl=10.00")
    return True


def effective_url(base_url: str, tracking: dict, content: str) -> str:
    split = urlsplit(base_url)
    query = dict(parse_qsl(split.query, keep_blank_values=True))
    query.update(
        {
            "utm_source": tracking.get("utm_source", "google"),
            "utm_medium": tracking.get("utm_medium", "cpc"),
            "utm_campaign": tracking.get("utm_campaign", ""),
            "utm_content": content,
            "cupom": tracking.get("coupon", ""),
        }
    )
    return urlunsplit((split.scheme, split.netloc, split.path, urlencode(query), split.fragment))


def normalized_url(url: str) -> tuple[str, str, str, tuple[tuple[str, str], ...]]:
    split = urlsplit(url)
    path = split.path.rstrip("/") or "/"
    return split.scheme.lower(), (split.hostname or "").lower(), path, tuple(sorted(parse_qsl(split.query, keep_blank_values=True)))


def load_expected_urls() -> list[str]:
    cfg = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    tracking = cfg["tracking"]
    return [
        effective_url(group["responsive_search_ad"]["final_url"], tracking, group["tracking_content"])
        for group in cfg["ad_groups"]
    ]


def validate_landing_pages(urls: list[str]) -> tuple[bool, str, list[tuple[str, int, str]]]:
    results: list[tuple[str, int, str]] = []
    for url in urls:
        expected_query = dict(parse_qsl(urlsplit(url).query, keep_blank_values=True))
        req = urllib.request.Request(
            url,
            headers={
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140 Safari/537.36",
                "Accept": "text/html,application/xhtml+xml",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=25) as resp:
                code = int(resp.status)
                final_url = resp.geturl()
                body = resp.read(2_000_000).decode("utf-8", errors="replace")
        except Exception as exc:
            return False, f"LANDING_PAGE_GUARD_FAILED url={url} error={type(exc).__name__}:{exc}", results
        if code != 200:
            return False, f"LANDING_PAGE_GUARD_FAILED url={url} http={code}", results
        final_split = urlsplit(final_url)
        final_query = dict(parse_qsl(final_split.query, keep_blank_values=True))
        if final_split.scheme != "https" or final_split.hostname != ALLOWED_HOST:
            return False, f"LANDING_PAGE_REDIRECT_GUARD_FAILED url={url} final={final_url}", results
        for key in ("q", "utm_source", "utm_medium", "utm_campaign", "utm_content", "cupom"):
            if final_query.get(key, "") != expected_query.get(key, ""):
                return False, f"LANDING_PAGE_TRACKING_GUARD_FAILED url={url} key={key} final={final_url}", results
        text = visible_text(body)
        if STALE_PROMO_RE.search(text):
            return False, f"STALE_VISIBLE_PROMO_CLAIM_GUARD_FAILED url={url}", results
        if "Vivaliz" not in text:
            return False, f"LANDING_PAGE_CONTENT_GUARD_FAILED url={url}", results
        results.append((url, code, final_url))
    return True, "", results


def fetch_compra_action(ga, customer_id: str):
    rows = list(
        ga.search(
            customer_id=customer_id,
            query="""
              SELECT conversion_action.id, conversion_action.resource_name,
                     conversion_action.name, conversion_action.status,
                     conversion_action.primary_for_goal, conversion_action.include_in_conversions_metric,
                     conversion_action.type, conversion_action.origin, conversion_action.category
              FROM conversion_action
              WHERE conversion_action.status != 'REMOVED'
            """,
        )
    )
    return [r for r in rows if r.conversion_action.name.strip().casefold() == "compra"]


def fetch_purchase_goal(ga, customer_id: str):
    return list(
        ga.search(
            customer_id=customer_id,
            query="""
              SELECT customer_conversion_goal.category,
                     customer_conversion_goal.origin,
                     customer_conversion_goal.biddable
              FROM customer_conversion_goal
              WHERE customer_conversion_goal.category = 'PURCHASE'
                AND customer_conversion_goal.origin = 'GOOGLE_ANALYTICS_4'
            """,
        )
    )


def fetch_perf_metrics(ga, customer_id: str, campaign_id: int, start_date: str, end_date: str) -> dict:
    rows = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT campaign.id, metrics.impressions, metrics.clicks, metrics.cost_micros
              FROM campaign
              WHERE campaign.id = {campaign_id}
                AND segments.date BETWEEN '{start_date}' AND '{end_date}'
            """,
        )
    )
    if not rows:
        return {"impressions": 0, "clicks": 0, "cost_micros": 0}
    row = rows[0]
    return {
        "impressions": int(row.metrics.impressions),
        "clicks": int(row.metrics.clicks),
        "cost_micros": int(row.metrics.cost_micros),
    }


def fetch_compra_metrics(ga, customer_id: str, campaign_id: int, start_date: str, end_date: str) -> tuple[float, float]:
    rows = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT segments.conversion_action_name, metrics.conversions, metrics.conversions_value
              FROM campaign
              WHERE campaign.id = {campaign_id}
                AND segments.date BETWEEN '{start_date}' AND '{end_date}'
            """,
        )
    )
    conversions = 0.0
    value = 0.0
    for row in rows:
        if str(row.segments.conversion_action_name).strip().casefold() == "compra":
            conversions += float(row.metrics.conversions)
            value += float(row.metrics.conversions_value)
    return conversions, value


def derived(perf: dict, conversions: float, conversion_value: float) -> dict:
    impressions = perf["impressions"]
    clicks = perf["clicks"]
    cost_brl = perf["cost_micros"] / 1_000_000
    return {
        "ctr_pct": (clicks / impressions * 100.0) if impressions else 0.0,
        "avg_cpc_brl": (cost_brl / clicks) if clicks else 0.0,
        "cpa_brl": (cost_brl / conversions) if conversions > 0 else None,
        "roas": (conversion_value / cost_brl) if cost_brl > 0 and conversion_value > 0 else None,
        "cost_brl": cost_brl,
    }


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

    expected_urls = load_expected_urls()
    client = client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")

    customer_rows = list(
        ga.search(
            customer_id=customer_id,
            query="SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone FROM customer LIMIT 1",
        )
    )
    if not customer_rows:
        raise SystemExit("CUSTOMER_NOT_FOUND")
    customer = customer_rows[0].customer
    timezone_name = customer.time_zone or "America/Sao_Paulo"
    today = datetime.now(ZoneInfo(timezone_name)).date().isoformat()

    safe = CAMPAIGN_NAME.replace("'", "\\'")
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
    c = campaigns[0]
    campaign_id = int(c.campaign.id)
    budget_before = int(c.campaign_budget.amount_micros)
    budget_corrected = ensure_budget(client, customer_id, c.campaign.campaign_budget, budget_before)

    groups = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT ad_group.id, ad_group.resource_name, ad_group.name, ad_group.status
              FROM ad_group
              WHERE campaign.id = {campaign_id} AND ad_group.status != 'REMOVED'
            """,
        )
    )
    ads = list(
        ga.search(
            customer_id=customer_id,
            query=f"""
              SELECT ad_group_ad.resource_name, ad_group_ad.ad.id, ad_group_ad.status,
                     ad_group_ad.policy_summary.approval_status,
                     ad_group_ad.policy_summary.review_status,
                     ad_group_ad.ad.final_urls
              FROM ad_group_ad
              WHERE campaign.id = {campaign_id} AND ad_group_ad.status != 'REMOVED'
            """,
        )
    )

    def fail(reason: str) -> int:
        pause_everything(client, customer_id, c.campaign.resource_name, groups, ads, reason)
        return 0

    if len(groups) != 2 or {g.ad_group.name for g in groups} != EXPECTED_GROUPS:
        return fail("AD_GROUP_INVARIANT_FAILED count=" + str(len(groups)))
    if len(ads) != 2:
        return fail("AD_INVARIANT_FAILED count=" + str(len(ads)))

    actual_urls: list[str] = []
    for row in ads:
        urls = list(row.ad_group_ad.ad.final_urls)
        if len(urls) != 1:
            return fail("FINAL_URL_GUARD_FAILED ad_id=" + str(row.ad_group_ad.ad.id) + " count=" + str(len(urls)))
        actual_urls.append(urls[0])
    if {normalized_url(u) for u in actual_urls} != {normalized_url(u) for u in expected_urls}:
        return fail("FINAL_URL_SET_GUARD_FAILED")

    landing_ok, landing_reason, landing_results = validate_landing_pages(actual_urls)
    if not landing_ok:
        return fail(landing_reason)

    compra_rows = fetch_compra_action(ga, customer_id)
    if len(compra_rows) != 1:
        return fail("PURCHASE_CONVERSION_GUARD_FAILED compra_count=" + str(len(compra_rows)))
    compra = compra_rows[0].conversion_action
    if compra.status.name != "ENABLED":
        return fail("PURCHASE_CONVERSION_GUARD_FAILED status=" + compra.status.name)
    if not bool(compra.primary_for_goal) or not bool(compra.include_in_conversions_metric):
        return fail(
            "PRIMARY_PURCHASE_CONVERSION_GUARD_FAILED primary="
            + str(bool(compra.primary_for_goal)).lower()
            + " included="
            + str(bool(compra.include_in_conversions_metric)).lower()
        )
    if compra.type.name != "GOOGLE_ANALYTICS_4_PURCHASE" or compra.category.name != "PURCHASE":
        return fail("PRIMARY_PURCHASE_CONVERSION_GUARD_FAILED type=" + compra.type.name + " category=" + compra.category.name)

    purchase_goals = fetch_purchase_goal(ga, customer_id)
    if len(purchase_goals) != 1 or not bool(purchase_goals[0].customer_conversion_goal.biddable):
        return fail("PURCHASE_DEFAULT_GOAL_GUARD_FAILED")

    for row in ads:
        ps = row.ad_group_ad.policy_summary
        if ps.approval_status.name != "APPROVED" or ps.review_status.name != "REVIEWED":
            return fail(
                "AD_POLICY_GUARD_FAILED ad_id="
                + str(row.ad_group_ad.ad.id)
                + " approval="
                + ps.approval_status.name
                + " review="
                + ps.review_status.name
            )

    today_perf = fetch_perf_metrics(ga, customer_id, campaign_id, today, today)
    lifetime_perf = fetch_perf_metrics(ga, customer_id, campaign_id, CAMPAIGN_LAUNCH_DATE, today)
    today_conversions, today_value = fetch_compra_metrics(ga, customer_id, campaign_id, today, today)
    lifetime_conversions, lifetime_value = fetch_compra_metrics(ga, customer_id, campaign_id, CAMPAIGN_LAUNCH_DATE, today)
    today_d = derived(today_perf, today_conversions, today_value)
    lifetime_d = derived(lifetime_perf, lifetime_conversions, lifetime_value)

    print("account_name=" + str(customer.descriptive_name))
    print("account_currency=" + str(customer.currency_code))
    print("account_timezone=" + timezone_name)
    print("campaign_id=" + str(campaign_id))
    print("campaign_status_before=" + c.campaign.status.name)
    print("daily_budget_brl=10.00")
    print("budget_corrected=" + str(budget_corrected).lower())
    print("today=" + today)
    print("impressions_today=" + str(today_perf["impressions"]))
    print("clicks_today=" + str(today_perf["clicks"]))
    print("ctr_pct_today=" + f"{today_d['ctr_pct']:.4f}")
    print("avg_cpc_brl_today=" + f"{today_d['avg_cpc_brl']:.4f}")
    print("cost_brl_today=" + f"{today_d['cost_brl']:.2f}")
    print("purchase_conversions_today=" + f"{today_conversions:.4f}")
    print("purchase_value_brl_today=" + f"{today_value:.2f}")
    print("cpa_brl_today=" + (f"{today_d['cpa_brl']:.2f}" if today_d["cpa_brl"] is not None else "NA"))
    print("roas_today=" + (f"{today_d['roas']:.4f}" if today_d["roas"] is not None else "NA"))
    print("impressions_lifetime=" + str(lifetime_perf["impressions"]))
    print("clicks_lifetime=" + str(lifetime_perf["clicks"]))
    print("ctr_pct_lifetime=" + f"{lifetime_d['ctr_pct']:.4f}")
    print("avg_cpc_brl_lifetime=" + f"{lifetime_d['avg_cpc_brl']:.4f}")
    print("cost_brl_lifetime=" + f"{lifetime_d['cost_brl']:.2f}")
    print("purchase_conversions_lifetime=" + f"{lifetime_conversions:.4f}")
    print("purchase_value_brl_lifetime=" + f"{lifetime_value:.2f}")
    print("cpa_brl_lifetime=" + (f"{lifetime_d['cpa_brl']:.2f}" if lifetime_d["cpa_brl"] is not None else "NA"))
    print("roas_lifetime=" + (f"{lifetime_d['roas']:.4f}" if lifetime_d["roas"] is not None else "NA"))
    print("no_conversion_pause_threshold_brl=12.00")
    print(
        "purchase_conversion="
        + compra.name
        + ":id="
        + str(compra.id)
        + ":status="
        + compra.status.name
        + ":primary="
        + str(bool(compra.primary_for_goal)).lower()
        + ":included="
        + str(bool(compra.include_in_conversions_metric)).lower()
        + ":type="
        + compra.type.name
        + ":origin="
        + compra.origin.name
        + ":category="
        + compra.category.name
        + ":default_goal_biddable=true"
    )
    for url, code, final_url in landing_results:
        print(f"landing_page={url}:http={code}:healthy=true:final_url={final_url}")
    for row in ads:
        ps = row.ad_group_ad.policy_summary
        print(
            f"ad_policy={row.ad_group_ad.ad.id}:approval={ps.approval_status.name}:"
            f"review={ps.review_status.name}:status={row.ad_group_ad.status.name}:final_url={list(row.ad_group_ad.ad.final_urls)[0]}"
        )

    # The user's guardrail is STRICTLY greater than BRL 12.00, never >=.
    if lifetime_d["cost_brl"] > NO_CONVERSION_PAUSE_THRESHOLD_BRL and lifetime_conversions <= 0:
        return fail(
            "NO_CONVERSION_SPEND_GUARD_FAILED spend_brl="
            + f"{lifetime_d['cost_brl']:.2f}"
            + " threshold_brl=12.00 purchase_conversions="
            + f"{lifetime_conversions:.4f}"
        )

    changed = enable_everything(client, customer_id, c.campaign.resource_name, c.campaign.status.name, groups, ads)
    if changed:
        print("ACTION=ENABLED_HEALTHY")
        print("campaign_status_final=ENABLED")
        print("RESULT=HEALTHY_ENABLED")
    else:
        print("campaign_status_final=ENABLED")
        print("RESULT=HEALTHY_NO_CHANGE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
