#!/usr/bin/env python3
"""Monitor seguro de solicitações de agentes via GitHub Issues.

Este processo NÃO executa comandos descritos em issues e NÃO declara tarefas
como concluídas. Ele apenas identifica solicitações abertas com a label
``agentes``, valida requisitos mínimos e publica um único status de triagem.
"""

from __future__ import annotations

import os
import sys
from datetime import datetime, timezone
from typing import Any

import requests

REPO_OWNER = "Vivaliz-site"
REPO_NAME = "site-shopvivaliz"
GITHUB_API = "https://api.github.com"
GITHUB_TOKEN = os.getenv("GITHUB_TOKEN", "")
AGENTES_LABEL = "agentes"
TRIAGE_MARKER = "<!-- agentes-listener:triaged -->"
ENVIRONMENT = os.getenv("AGENT_ENVIRONMENT", "github-actions")


def log(message: str) -> None:
    print(f"[{datetime.now(timezone.utc).isoformat()}] {message}")


def headers() -> dict[str, str]:
    if not GITHUB_TOKEN:
        raise RuntimeError("GITHUB_TOKEN não configurado")
    return {
        "Authorization": f"Bearer {GITHUB_TOKEN}",
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    }


def api_get(path: str, **params: Any) -> Any:
    response = requests.get(
        f"{GITHUB_API}{path}",
        headers=headers(),
        params=params,
        timeout=20,
    )
    response.raise_for_status()
    return response.json()


def api_post(path: str, payload: dict[str, Any]) -> Any:
    response = requests.post(
        f"{GITHUB_API}{path}",
        headers=headers(),
        json=payload,
        timeout=20,
    )
    response.raise_for_status()
    return response.json()


def get_open_agent_issues() -> list[dict[str, Any]]:
    items = api_get(
        f"/repos/{REPO_OWNER}/{REPO_NAME}/issues",
        labels=AGENTES_LABEL,
        state="open",
        per_page=100,
    )
    # O endpoint /issues também retorna pull requests; eles não são tarefas.
    return [item for item in items if "pull_request" not in item]


def already_triaged(issue_number: int) -> bool:
    comments = api_get(
        f"/repos/{REPO_OWNER}/{REPO_NAME}/issues/{issue_number}/comments",
        per_page=100,
    )
    return any(TRIAGE_MARKER in (comment.get("body") or "") for comment in comments)


def triage_message(issue: dict[str, Any]) -> str:
    body = (issue.get("body") or "").strip()
    has_acceptance = any(
        token in body.lower()
        for token in ("critério de aceite", "criterios de aceite", "aceite", "test plan")
    )
    has_scope = len(body) >= 80

    missing: list[str] = []
    if not has_scope:
        missing.append("descrição/escopo suficiente")
    if not has_acceptance:
        missing.append("critérios de aceite ou plano de teste")

    if missing:
        status = "Triagem pendente: " + ", ".join(missing) + "."
    else:
        status = (
            "Triagem concluída. A solicitação está pronta para revisão humana ou "
            "atribuição explícita a um executor autorizado."
        )

    return (
        f"{TRIAGE_MARKER}\n"
        f"🤖 **Monitor de agentes — {ENVIRONMENT}**\n\n"
        f"{status}\n\n"
        "Este monitor não executa comandos, não altera código, não fecha issues e "
        "não declara trabalho como concluído. Execução e merge exigem um fluxo "
        "autorizado com evidências de testes."
    )


def process() -> int:
    issues = get_open_agent_issues()
    log(f"Issues abertas com label '{AGENTES_LABEL}': {len(issues)}")

    triaged = 0
    for issue in issues:
        number = int(issue["number"])
        title = issue.get("title", "")
        if already_triaged(number):
            log(f"#{number} já triada: {title}")
            continue

        api_post(
            f"/repos/{REPO_OWNER}/{REPO_NAME}/issues/{number}/comments",
            {"body": triage_message(issue)},
        )
        triaged += 1
        log(f"#{number} triada: {title}")

    log(f"Novas triagens publicadas: {triaged}")
    return triaged


def main() -> int:
    try:
        process()
        return 0
    except requests.HTTPError as exc:
        status = exc.response.status_code if exc.response is not None else "unknown"
        log(f"Falha na API GitHub (HTTP {status}): {exc}")
        return 1
    except Exception as exc:
        log(f"Falha fatal: {exc}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
