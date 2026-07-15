#!/usr/bin/env python3
"""Guardiao de todas as fronteiras de governanca autonoma ShopVivaliz."""

import json
import argparse
import sys
from pathlib import Path

POLICY_PATH = Path("config/autonomous-policy.json")


def load_policy() -> dict:
    if not POLICY_PATH.exists():
        return {
            "pricing_impact_keywords": [
                "preco", "preço", "price", "pricing", "desconto", "discount",
                "cupom", "coupon", "margem", "markup", "sale_price",
                "regular_price", "promotional_price"
            ]
        }
    return json.loads(POLICY_PATH.read_text(encoding="utf-8"))


def normalize(text: str) -> str:
    return (text or "").lower()


def has_pricing_impact(text: str, policy: dict) -> bool:
    haystack = normalize(text)
    keywords = policy.get("pricing_impact_keywords", [])
    return any(normalize(keyword) in haystack for keyword in keywords)


def blocked_governance_action(text: str, policy: dict) -> str | None:
    haystack = normalize(text)
    groups = {
        "deploy_or_rollback": ["deploy", "rollback", "produção", "producao"],
        "campaign_publish_or_activation": ["publicar campanha", "ativar campanha", "publish campaign", "activate campaign"],
        "budget_increase": ["aumentar orçamento", "aumentar orcamento", "increase budget"],
        "financial_action": ["ação financeira", "acao financeira", "financial action", "transferência", "transferencia"],
    }
    enabled = set(policy.get("blocked_without_manual_review", []))
    for action, keywords in groups.items():
        if action in enabled and any(keyword in haystack for keyword in keywords):
            return action
    return None


def validate_task(title: str, description: str = "") -> tuple[bool, str]:
    policy = load_policy()
    task_text = f"{title}\n{description}"
    if has_pricing_impact(task_text, policy):
        return False, "BLOCKED_PRICING_IMPACT: tarefa pode alterar preco final e precisa de revisao manual."
    blocked = blocked_governance_action(task_text, policy)
    if blocked:
        return False, f"BLOCKED_GOVERNANCE_ACTION: {blocked} precisa de aprovacao humana."
    return True, "ALLOWED_AUTONOMOUS: tarefa liberada para execucao autonoma."


def main() -> int:
    parser = argparse.ArgumentParser(description="Guardiao da politica autonoma ShopVivaliz")
    parser.add_argument("--title", default="", help="Titulo da tarefa")
    parser.add_argument("--description", default="", help="Descricao da tarefa")
    args = parser.parse_args()

    title = args.title
    description = args.description
    allowed, message = validate_task(title, description)
    print(message)
    return 0 if allowed else 2


if __name__ == "__main__":
    raise SystemExit(main())
