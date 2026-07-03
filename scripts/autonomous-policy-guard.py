#!/usr/bin/env python3
"""Guardiao da politica autonoma ShopVivaliz.

Permite execucao autonoma por padrao e bloqueia somente tarefas
com impacto em preco final cobrado do cliente.
"""

import json
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


def validate_task(title: str, description: str = "") -> tuple[bool, str]:
    policy = load_policy()
    task_text = f"{title}\n{description}"
    if has_pricing_impact(task_text, policy):
        return False, "BLOCKED_PRICING_IMPACT: tarefa pode alterar preco final e precisa de revisao manual."
    return True, "ALLOWED_AUTONOMOUS: tarefa liberada para execucao autonoma."


def main() -> int:
    title = sys.argv[1] if len(sys.argv) > 1 else ""
    description = sys.argv[2] if len(sys.argv) > 2 else ""
    allowed, message = validate_task(title, description)
    print(message)
    return 0 if allowed else 2


if __name__ == "__main__":
    raise SystemExit(main())
