#!/usr/bin/env python3
"""Regressão da identificação de agente em PRs ShopVivaliz."""
import importlib.util
from pathlib import Path

mod = Path(__file__).resolve().parent.parent / "scripts/quality/check_pr_agent_title.py"
spec = importlib.util.spec_from_file_location("check_pr_agent_title", mod)
assert spec is not None and spec.loader is not None
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

cases = [
    ("[GPT] fix: prevenir perdas no catálogo", "fredmourao-ai", True),
    ("[Claude] docs: preparar plano", "fredmourao-ai", True),
    ("[Gemini] feat: otimizar simulação", "fredmourao-ai", True),
    ("[Codex] fix: CI", "chatgpt-codex-connector[bot]", True),
    ("[Agente: Llama 3] chore: analisar", "bot[bot]", True),
    ("[Auto-Healer] fix: resolver conflito", "shopvivaliz-pr-auto-healer[bot]", True),
    ("fix: sem agente", "fredmourao-ai", False),
    ("[Agente não identificado] fix: sem certeza", "fredmourao-ai", False),
    ("[GPT] ", "fredmourao-ai", False),
    ("fix: alteração manual", "shopvivaliz-human", True),
]
for title, login, expected in cases:
    actual, reason = module.valid_title(title, login)
    assert actual is expected, (title, login, actual, reason)
print("PASS: 10 cenarios de identificacao real de agente na criacao da PR.")
