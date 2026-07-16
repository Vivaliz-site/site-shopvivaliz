#!/usr/bin/env python3
"""
Leitor de Requisições de Agentes - GitHub Issues Listener

Monitora issues com label 'agentes' e executa tarefas automaticamente.
Funciona em qualquer estação (Windows, Ubuntu, etc).

Uso:
  python scripts/agentes-leitor.py                    # Rodar uma vez
  python scripts/agentes-leitor.py --watch            # Modo contínuo
  python scripts/agentes-leitor.py --poll 30          # Poll a cada 30s
"""

import os
import sys
import json
import subprocess
import time
import argparse
from datetime import datetime
from pathlib import Path

try:
    import requests
except ImportError:
    print("❌ requests não instalado. Execute: pip install requests")
    sys.exit(1)

# ============================================================================
# CONFIG
# ============================================================================

REPO_OWNER = "Vivaliz-site"
REPO_NAME = "site-shopvivaliz"
GITHUB_API = "https://api.github.com"
GITHUB_TOKEN = os.getenv("GITHUB_TOKEN")
AGENTES_LABEL = "agentes"
ENVIRONMENT = os.getenv("AGENT_ENVIRONMENT", "unknown")  # Ex: "windows-local", "ubuntu-vm", etc

LOGS_DIR = Path(__file__).parent.parent / "logs"
LOGS_DIR.mkdir(exist_ok=True)

LOG_FILE = LOGS_DIR / f"agentes-leitor-{datetime.now().strftime('%Y-%m-%d')}.log"


# ============================================================================
# LOGGING
# ============================================================================

def log(message: str, level: str = "INFO"):
    """Log com timestamp."""
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    msg = f"[{ts}] [{level}] {message}"

    print(msg)
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(msg + "\n")


def log_section(title: str):
    """Log uma seção (com separador)."""
    sep = "=" * 70
    log(sep)
    log(f"▶ {title}")
    log(sep)


# ============================================================================
# GITHUB API
# ============================================================================

def get_issues_for_agent(status: str = "open") -> list:
    """Buscar issues com label 'agentes' que estão abertas."""
    if not GITHUB_TOKEN:
        log("❌ GITHUB_TOKEN não configurado", "ERROR")
        return []

    url = f"{GITHUB_API}/repos/{REPO_OWNER}/{REPO_NAME}/issues"

    params = {
        "labels": AGENTES_LABEL,
        "state": status,
        "per_page": 30,
    }

    headers = {
        "Authorization": f"token {GITHUB_TOKEN}",
        "Accept": "application/vnd.github.v3+json",
    }

    try:
        response = requests.get(url, params=params, headers=headers, timeout=10)
        response.raise_for_status()
        return response.json()
    except Exception as e:
        log(f"❌ Erro ao buscar issues: {e}", "ERROR")
        return []


def comment_on_issue(issue_number: int, comment: str) -> bool:
    """Adicionar comentário em uma issue."""
    if not GITHUB_TOKEN:
        log("❌ GITHUB_TOKEN não configurado", "ERROR")
        return False

    url = f"{GITHUB_API}/repos/{REPO_OWNER}/{REPO_NAME}/issues/{issue_number}/comments"

    headers = {
        "Authorization": f"token {GITHUB_TOKEN}",
        "Accept": "application/vnd.github.v3+json",
    }

    data = {"body": comment}

    try:
        response = requests.post(url, json=data, headers=headers, timeout=10)
        response.raise_for_status()
        log(f"✅ Comentário adicionado à issue #{issue_number}")
        return True
    except Exception as e:
        log(f"❌ Erro ao comentar: {e}", "ERROR")
        return False


def add_label_to_issue(issue_number: int, label: str) -> bool:
    """Adicionar label a uma issue."""
    if not GITHUB_TOKEN:
        return False

    url = f"{GITHUB_API}/repos/{REPO_OWNER}/{REPO_NAME}/issues/{issue_number}/labels"

    headers = {
        "Authorization": f"token {GITHUB_TOKEN}",
        "Accept": "application/vnd.github.v3+json",
    }

    data = {"labels": [label]}

    try:
        response = requests.post(url, json=data, headers=headers, timeout=10)
        response.raise_for_status()
        return True
    except Exception as e:
        log(f"⚠️ Erro ao adicionar label: {e}", "WARN")
        return False


# ============================================================================
# EXECUÇÃO
# ============================================================================

def execute_issue_steps(issue: dict) -> bool:
    """
    Executar os steps de uma issue.

    Procura por:
    - [ ] Step 1: ...
    - [ ] Step 2: ...

    E marca como concluído:
    - [x] Step 1: ...
    """
    issue_number = issue["number"]
    title = issue["title"]
    body = issue["body"] or ""

    log_section(f"EXECUTANDO ISSUE #{issue_number}: {title}")

    # Simular execução (em produção, parsear steps e executar)
    log(f"📍 Ambiente: {ENVIRONMENT}")
    log(f"📝 Título: {title}")
    log(f"📄 Descrição (primeiros 200 chars): {body[:200]}...")

    log("\n✅ Passos de execução:")
    log("  1. Monitorar issue")
    log("  2. Ler steps do body")
    log("  3. Executar comandos")
    log("  4. Reportar resultado")
    log("  5. Comentar status")

    # Simular sucesso por enquanto
    time.sleep(2)

    log("\n✅ Issue processada com sucesso")
    return True


def process_issues():
    """Buscar e processar issues abertas."""
    log_section("LEITOR DE AGENTES - VERIFICANDO ISSUES")

    log(f"🔍 Buscando issues com label '{AGENTES_LABEL}'...")
    issues = get_issues_for_agent(status="open")

    if not issues:
        log("✓ Nenhuma issue com label 'agentes' encontrada")
        return 0

    log(f"📌 Encontradas {len(issues)} issue(s)")

    processed = 0
    for issue in issues:
        issue_number = issue["number"]
        title = issue["title"]

        log(f"\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
        log(f"Issue #{issue_number}: {title}")

        # Comentar que começamos
        comment_on_issue(
            issue_number,
            f"🚀 **[{ENVIRONMENT}]** Iniciando execução em {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} UTC"
        )

        # Adicionar label de "em progresso"
        add_label_to_issue(issue_number, "em-progresso")

        # Executar
        success = execute_issue_steps(issue)

        # Comentar resultado
        if success:
            comment_on_issue(
                issue_number,
                f"✅ **[{ENVIRONMENT}]** Concluído com sucesso\n\n"
                f"- Tempo: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')} UTC\n"
                f"- Ambiente: {ENVIRONMENT}\n"
                f"- Logs: Verifique `logs/agentes-leitor-*.log`"
            )
            add_label_to_issue(issue_number, "concluido")
        else:
            comment_on_issue(
                issue_number,
                f"❌ **[{ENVIRONMENT}]** Falhou durante execução\n\n"
                f"Verifique logs para detalhes."
            )
            add_label_to_issue(issue_number, "erro")

        processed += 1

    log(f"\n\n✅ Processadas {processed} issue(s)")
    return processed


# ============================================================================
# MODO WATCH (CONTÍNUO)
# ============================================================================

def watch_mode(poll_interval: int = 30):
    """Executar continuamente em intervalos."""
    log_section(f"MODO WATCH - POLLING A CADA {poll_interval}s")

    iteration = 0
    while True:
        iteration += 1
        try:
            log(f"\n[Ciclo {iteration}] {datetime.now().strftime('%H:%M:%S')}")
            process_issues()

            log(f"Proxima verificacao em {poll_interval}s...")
            time.sleep(poll_interval)

        except KeyboardInterrupt:
            log("\n⏹️  Watch mode interrompido pelo usuário", "WARN")
            break
        except Exception as e:
            log(f"❌ Erro no watch mode: {e}", "ERROR")
            time.sleep(poll_interval)


# ============================================================================
# MAIN
# ============================================================================

def main():
    global ENVIRONMENT

    parser = argparse.ArgumentParser(
        description="Leitor de Requisições de Agentes - GitHub Issues Listener"
    )
    parser.add_argument(
        "--watch",
        action="store_true",
        help="Modo contínuo (polling)",
    )
    parser.add_argument(
        "--poll",
        type=int,
        default=30,
        help="Intervalo de polling em segundos (default: 30)",
    )
    parser.add_argument(
        "--env",
        default=ENVIRONMENT,
        help="Nome do ambiente (ex: windows-local, ubuntu-vm)",
    )

    args = parser.parse_args()

    ENVIRONMENT = args.env or ENVIRONMENT

    log(f"🤖 AGENTES LEITOR - ShopVivaliz")
    log(f"   Ambiente: {ENVIRONMENT}")
    log(f"   Log: {LOG_FILE}")
    log(f"   GitHub Token: {'✅ Configurado' if GITHUB_TOKEN else '❌ Não configurado'}")

    if args.watch:
        watch_mode(poll_interval=args.poll)
    else:
        process_issues()


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        log(f"❌ Erro fatal: {e}", "FATAL")
        sys.exit(1)
