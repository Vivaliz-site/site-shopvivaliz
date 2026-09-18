#!/usr/bin/env python3
"""Compatibility entrypoint for the retired multi-provider collaboration executor."""
from __future__ import annotations

from scripts.ai.retired_executor import run_blocked


def iniciar_super_agente_trio(modo: str = "diagnostico", tarefa: str = "") -> int:
    return run_blocked(
        "ai_collaboration.py",
        "executor legado aposentado: fazia chamadas diretas a Gemini, Claude e OpenAI e nao possui consumidor operacional ativo",
        [
            "usar Gemini/OpenRouter nas rotinas automaticas aprovadas",
            "usar Claude/GPT/Codex somente por gatilho humano explicito",
        ],
    )


if __name__ == "__main__":
    raise SystemExit(iniciar_super_agente_trio())
