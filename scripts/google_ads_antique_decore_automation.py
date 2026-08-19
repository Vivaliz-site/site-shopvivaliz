#!/usr/bin/env python3
"""Automation entry point for the Antique/Decore Google Ads live watch.

This adapter is read-only by default. It keeps the requested guardrails by:
- not treating unrelated storefront coupon text as a landing-page failure;
- treating the account's biddable PURCHASE customer goal as the default purchase goal;
- printing conversion diagnostics without secret values.

The optional conversion-action rename is a real Google Ads mutation and therefore
requires --apply-conversion-name-fix explicitly.
"""

from __future__ import annotations

import argparse
import os
import re

import google_ads_antique_decore_status as monitor

# The ads make no coupon promise. Keep the required HTTP/redirect/UTM/content
# landing checks while disabling the unrelated legacy promo-token heuristic.
monitor.STALE_PROMO_RE = re.compile(r"(?!)")


def purchase_rows(ga, customer_id: str):
    return list(
        ga.search(
            customer_id=customer_id,
            query="""
              SELECT conversion_action.id, conversion_action.resource_name,
                     conversion_action.name, conversion_action.status,
                     conversion_action.primary_for_goal,
                     conversion_action.include_in_conversions_metric,
                     conversion_action.type, conversion_action.origin,
                     conversion_action.category
              FROM conversion_action
              WHERE conversion_action.status != 'REMOVED'
            """,
        )
    )


def normalize_primary_purchase_name(client, ga, customer_id: str, *, apply_fix: bool) -> None:
    rows = purchase_rows(ga, customer_id)
    exact_compra = [r for r in rows if r.conversion_action.name.strip().casefold() == "compra"]
    verified_primary = [
        r
        for r in rows
        if r.conversion_action.status.name == "ENABLED"
        and bool(r.conversion_action.primary_for_goal)
        and bool(r.conversion_action.include_in_conversions_metric)
        and r.conversion_action.type.name == "GOOGLE_ANALYTICS_4_PURCHASE"
        and r.conversion_action.category.name == "PURCHASE"
    ]

    if exact_compra or len(verified_primary) != 1:
        return

    action = verified_primary[0].conversion_action
    if not apply_fix:
        print("ACTION=PRIMARY_PURCHASE_RENAME_AVAILABLE")
        print("rename_requires=--apply-conversion-name-fix")
        print("target_conversion_id=" + str(action.id))
        return

    op = client.get_type("ConversionActionOperation")
    op.update.resource_name = action.resource_name
    op.update.name = "Compra"
    op.update_mask.paths.append("name")
    client.get_service("ConversionActionService").mutate_conversion_actions(
        customer_id=customer_id,
        operations=[op],
    )
    print("ACTION=RENAMED_PRIMARY_PURCHASE_TO_COMPRA id=" + str(action.id))


def print_conversion_diagnostics(ga, customer_id: str) -> None:
    for row in purchase_rows(ga, customer_id):
        a = row.conversion_action
        if a.category.name == "PURCHASE" or any(token in a.name.casefold() for token in ("compra", "purchase", "pedido", "sale")):
            print(
                "conversion_candidate="
                + a.name
                + ":id=" + str(a.id)
                + ":status=" + a.status.name
                + ":primary=" + str(bool(a.primary_for_goal)).lower()
                + ":included=" + str(bool(a.include_in_conversions_metric)).lower()
                + ":type=" + a.type.name
                + ":origin=" + a.origin.name
                + ":category=" + a.category.name
            )
    goals = list(
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
    for row in goals:
        g = row.customer_conversion_goal
        print(
            "purchase_customer_goal=category=" + g.category.name
            + ":origin=" + g.origin.name
            + ":biddable=" + str(bool(g.biddable)).lower()
        )


def fetch_default_purchase_goal(ga, customer_id: str):
    return list(
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


def main() -> int:
    parser = argparse.ArgumentParser(description="Antique/Decore Google Ads live watch adapter.")
    parser.add_argument(
        "--apply-conversion-name-fix",
        action="store_true",
        help="Actually rename the single verified primary GA4 purchase conversion action to Compra.",
    )
    args = parser.parse_args()

    client = monitor.client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")
    normalize_primary_purchase_name(client, ga, customer_id, apply_fix=args.apply_conversion_name_fix)
    print_conversion_diagnostics(ga, customer_id)
    monitor.fetch_purchase_goal = fetch_default_purchase_goal
    return monitor.main()


if __name__ == "__main__":
    raise SystemExit(main())
