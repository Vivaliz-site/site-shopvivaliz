"""Fail closed for retired campaign writers under the owner's current policy.

Only the existing Vedante campaign at BRL 5 average/day is authorized. The
Antique/Decore writers target another campaign and cannot inherit that approval.
There is deliberately no environment-variable or CLI override for old requests.
"""
from __future__ import annotations

from typing import NoReturn


def refuse_legacy_ads_execution(entrypoint: str) -> NoReturn:
    print("RESULT=BLOCKED_CURRENT_BUDGET_POLICY")
    print("policy=SHOPVIVALIZ_GOOGLE_ADS_BUDGET_20261029")
    print("entrypoint=" + entrypoint)
    print("reason=only_existing_vedante_campaign_at_brl_5_average_per_day_is_authorized")
    print("budget_increase_requires=approved_real_sales_and_new_explicit_owner_authorization")
    print("policy_document=docs/knowledge/google-ads-budget-policy.md")
    print("NO_CHANGES_MADE")
    raise SystemExit(2)
