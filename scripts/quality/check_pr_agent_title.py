#!/usr/bin/env python3
"""Verifica identificação explícita do agente no título de PRs novas.

Execução sem credenciais e sem chamadas de rede. Não tenta inferir modelo pelo
login GitHub: a conta fredmourao-ai pode hospedar agentes diferentes.
"""
from __future__ import annotations

import argparse
import re
import sys

AGENTS = (
    "GPT",
    "Claude",
    "Gemini",
    "Codex",
    "Copilot",
    "Ollama",
    "Auto-Healer",
)
PREFIX = re.compile(
    r"^\[(?:" + "|".join(re.escape(v) for v in AGENTS)
    + r"|Agente: [\wÀ-ÿ .-]{2,40})\][ ]+\S",
    re.UNICODE,
)


def valid_title(title: str, author_login: str) -> tuple[bool, str]:
    """Validates prospective PRs; a human-only PR needs no agent prefix."""
    login = author_login.strip().lower()
    if login == "shopvivaliz-human":
        return True, "A identidade humana exclusiva nao exige prefixo de agente."
    if PREFIX.search(title.strip()):
        return True, "Titulo possui prefixo de agente."
    return (
        False,
        "O titulo deve comecar pelo agente responsavel: "
        "[GPT], [Claude], [Gemini], [Codex], [Copilot], [Ollama], "
        "[Auto-Healer] ou [Agente: Nome]. "
        "A conta GitHub compartilhada nao comprova qual modelo executou a PR.",
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--title", required=True)
    parser.add_argument("--author", required=True)
    args = parser.parse_args()
    passed, reason = valid_title(args.title, args.author)
    print(("PASS" if passed else "FAIL") + ": " + reason)
    return 0 if passed else 1


if __name__ == "__main__":
    sys.exit(main())
