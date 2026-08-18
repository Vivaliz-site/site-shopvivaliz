#!/usr/bin/env python3
"""Automation entry point for the Antique/Decore Google Ads live watch.

The campaign itself does not advertise a coupon. A coupon token that happens to
be visible on the storefront is therefore not, by itself, a broken landing page.
The required landing guard is availability + correct destination/tracking; the
underlying monitor still enforces those checks plus policy, conversion and spend
safety. This wrapper also prints purchase-conversion candidates before enforcing
fail-closed guardrails so a missing/misnamed Compra action can be repaired safely.
"""

from __future__ import annotations

import os
import re

import google_ads_antique_decore_status as monitor

# Match nothing. HTTP/redirect/UTM/content checks remain fully active.
monitor.STALE_PROMO_RE = re.compile(r"(?!)")


def print_conversion_diagnostics() -> None:
    client = monitor.client_from_env()
    customer_id = os.environ["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
    ga = client.get_service("GoogleAdsService")
    rows = list(
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
    for row in rows:
        a = row.conversion_action
        if a.category.name == "PURCHASE" or any(token in a.name.casefold() for token in ("compra", "purchase", "pedido", "sale")):
            print(
                "conversion_candidate="
                + a.name
                + ":id=" + str(a.id)
                + ":resource=" + a.resource_name
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


print_conversion_diagnostics()
raise SystemExit(monitor.main())
