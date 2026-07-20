#!/usr/bin/env python3
"""Orquestrador autonomo real do ShopVivaliz.

Responsabilidades:
- consumir a fila canonica;
- auto-gerar novas tarefas quando a fila estiver curta;
- ler sinais reais do projeto/site para evoluir o backlog;
- registrar trilha de passos por agente;
- consultar historico de aprendizado antes de agir;
- respeitar intervencoes humanas operacionais vindas da squad-chat API.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

from task_queue_lib import executable_pending_tasks, load_queue, save_queue, upsert_task, utc_now

ROOT = Path(__file__).resolve().parents[1]
LOGS_DIR = ROOT / "logs"
AUTONOMOUS_DIR = LOGS_DIR / "autonomous"
STORAGE_DIR = ROOT / "storage" / "private"
CANONICAL_QUEUE = ROOT / "tasks-queue.json"
LEGACY_QUEUE = ROOT / "logs" / "tasks-queue.json"
INTERVENTIONS_FILE = STORAGE_DIR / "agent-interventions.jsonl"
INTERVENTION_RESPONSES_FILE = STORAGE_DIR / "agent-intervention-responses.jsonl"
LEARNING_FILE = AUTONOMOUS_DIR / "learning-history.jsonl"
STEP_EVENTS_FILE = AUTONOMOUS_DIR / "agent-steps.jsonl"
STATUS_FILE = AUTONOMOUS_DIR / "live-status.json"
REPORT_FILE = LOGS_DIR / "autonomous-cycle-report.json"
REPORT_EVENTS_FILE = LOGS_DIR / "autonomous-cycle-events.jsonl"
EMAIL_CONFIG_FILE = LOGS_DIR / "email-config-check.json"

SITE_URL = os.getenv("SHOPVIVALIZ_SITE_URL", "https://shopvivaliz.com.br")
QUEUE_LOW_WATERMARK = int(os.getenv("AUTONOMOUS_QUEUE_LOW_WATERMARK", "3"))
MAX_GENERATED_PER_CYCLE = int(os.getenv("AUTONOMOUS_MAX_GENERATED_TASKS", "6"))

AGENTS = {
    "claude": {"label": "Claude", "role": "Implementacao e codigo", "color": "green"},
    "gemini": {"label": "Gemini", "role": "Arquitetura e descoberta", "color": "green"},
    "gpt": {"label": "ChatGPT", "role": "Validacao e QA", "color": "green"},
    "core-agent": {"label": "Core-Agent", "role": "Orquestracao autonoma", "color": "green"},
}

STOP_STATUSES = {
    "completed",
    "pr_opened",
    "blocked_price_approval_required",
    "blocked_manual_approval_required",
    "blocked_external_access_required",
    "blocked_no_safe_change",
}

ROO_HELPERS = [
    {
        "id": "qa-self-test",
        "name": "Roo Auxiliar — QA / Self-test",
        "description": "Validar logs, health checks e fluxos críticos sem alterar o ecommerce.",
        "keywords": ["qa", "self-test", "test", "validar", "log", "health", "checkout"],
        "next_steps": ["Validar endpoints críticos.", "Registrar evidências.", "Priorizar estabilidade antes do deploy."],
    },
    {
        "id": "olist-tiny",
        "name": "Roo Auxiliar — Olist / Tiny",
        "description": "Acompanhar estoque, imagens e catálogo sem alterar preços.",
        "keywords": ["olist", "tiny", "estoque", "imagem", "catalog", "sku", "produto", "sincroniz"],
        "next_steps": ["Conferir a sincronização local.", "Revisar logs sem tocar em preços.", "Encaminhar discrepâncias para revisão."],
    },
    {
        "id": "frete-checkout",
        "name": "Roo Auxiliar — Frete / Checkout",
        "description": "Auditar frete, CEP, carrinho e checkout com segurança.",
        "keywords": ["frete", "shipping", "cep", "carrinho", "entrega"],
        "next_steps": ["Validar cálculo de frete.", "Comparar dados e logs.", "Evitar alterações financeiras."],
    },
]


def classify_ai_result(returncode: int, output: str) -> tuple[str, str]:
    if returncode in {2, 3}:
        return "blocked_external_access_required", output or "Cliente de IA ou dependência externa indisponível."
    if returncode != 0:
        return "failed", output or "Falha inesperada no fluxo de IA."
    return "ok", output or "Fluxo de IA executado sem incidências."


def select_roo_helper(task: dict[str, Any]) -> dict[str, Any]:
    text = f"{task.get('title', '')} {task.get('description', '')}".lower()
    for helper in ROO_HELPERS:
        if any(keyword in text for keyword in helper["keywords"]):
            return helper
    return {
        "id": "roo-general",
        "name": "Roo Auxiliar — Geral",
        "description": "Manter continuidade segura e auditável.",
        "keywords": [],
        "next_steps": ["Registrar o estado atual.", "Evitar ações financeiras.", "Encaminhar riscos para revisão."],
    }


def render_roo_fallback_report(task: dict[str, Any], helper: dict[str, Any]) -> str:
    steps = "\n".join(f"- {step}" for step in helper.get("next_steps", []))
    return (
        "# Roo Auxiliar — Fallback seguro\n\n"
        f"## Tarefa: {task.get('title') or task.get('id') or 'sem título'}\n\n"
        f"**Descrição:** {task.get('description') or 'Sem descrição'}\n\n"
        f"**Roo selecionado:** {helper['name']}\n\n"
        "## Próximos passos seguros\n"
        f"{steps}\n"
    )


def ensure_dirs() -> None:
    AUTONOMOUS_DIR.mkdir(parents=True, exist_ok=True)
    STORAGE_DIR.mkdir(parents=True, exist_ok=True)
    LOGS_DIR.mkdir(parents=True, exist_ok=True)


def run(cmd: list[str], *, timeout: int = 180, check: bool = False) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, check=check)


def read_json(path: Path, fallback: Any) -> Any:
    if not path.exists():
        return fallback
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return fallback
    return data


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    rows: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict):
            rows.append(row)
    return rows


def append_jsonl(path: Path, row: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(row, ensure_ascii=False) + "\n")


def agent_meta(agent_id: str) -> dict[str, str]:
    return AGENTS.get(agent_id, {"label": agent_id, "role": "Agente autonomo", "color": "green"})


def emit_step(agent_id: str, action: str, *, status: str = "producing", task: dict[str, Any] | None = None, details: dict[str, Any] | None = None) -> None:
    payload = {
        "timestamp": utc_now(),
        "agent_id": agent_id,
        "agent_label": agent_meta(agent_id)["label"],
        "status": status,
        "action": action,
        "task_id": task.get("id") if isinstance(task, dict) else None,
        "task_title": task.get("title") if isinstance(task, dict) else None,
        "details": details or {},
    }
    append_jsonl(STEP_EVENTS_FILE, payload)
    append_jsonl(REPORT_EVENTS_FILE, payload)


def log_learning(agent_id: str, task: dict[str, Any] | None, lesson_type: str, summary: str, *, details: dict[str, Any] | None = None) -> None:
    append_jsonl(
        LEARNING_FILE,
        {
            "timestamp": utc_now(),
            "agent_id": agent_id,
            "task_id": task.get("id") if isinstance(task, dict) else None,
            "task_title": task.get("title") if isinstance(task, dict) else None,
            "lesson_type": lesson_type,
            "summary": summary,
            "details": details or {},
        },
    )


def recent_learning(limit: int = 80) -> list[dict[str, Any]]:
    rows = read_jsonl(LEARNING_FILE)
    return rows[-limit:]


def recent_task_titles(lessons: list[dict[str, Any]], *, window: int = 20) -> set[str]:
    titles: set[str] = set()
    for lesson in lessons[-window:]:
        title = str(lesson.get("task_title") or "").strip().lower()
        if title:
            titles.add(title)
    return titles


def pending_tasks(queue: dict[str, Any]) -> list[dict[str, Any]]:
    return [task for task in queue.get("queue", []) if str(task.get("status", "")).lower() == "pending"]


def active_tasks(queue: dict[str, Any]) -> list[dict[str, Any]]:
    return [task for task in queue.get("queue", []) if str(task.get("status", "")).lower() in {"assigned", "running", "in_progress"}]


def fetch_url(url: str, *, timeout: int = 20) -> tuple[int | None, str]:
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": "ShopVivalizAutonomousExecutor/1.0 (+https://shopvivaliz.com.br)",
            "Accept": "text/html,application/xml,text/plain,*/*",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read(250_000).decode("utf-8", errors="replace")
            return int(getattr(response, "status", 200)), body
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", errors="replace")
    except Exception as exc:  # noqa: BLE001
        return None, str(exc)


def site_signals() -> list[dict[str, Any]]:
    urls = {
        "home": SITE_URL.rstrip("/") + "/",
        "robots": SITE_URL.rstrip("/") + "/robots.txt",
        "sitemap": SITE_URL.rstrip("/") + "/sitemap.xml",
        "termos": SITE_URL.rstrip("/") + "/p/termos",
    }
    collected: list[dict[str, Any]] = []
    for key, url in urls.items():
        status_code, body = fetch_url(url)
        lowered = body.lower() if isinstance(body, str) else ""
        signal = {
            "key": key,
            "url": url,
            "status_code": status_code,
            "ok": status_code is not None and 200 <= status_code < 400,
            "contains_noindex": "noindex" in lowered,
            "contains_product_jsonld": '"@type":"product"' in lowered or '"@type": "product"' in lowered,
            "contains_org_jsonld": '"@type":"organization"' in lowered or '"@type": "organization"' in lowered,
            "body_excerpt": body[:1000] if isinstance(body, str) else "",
        }
        collected.append(signal)
    return collected


def token_signals() -> dict[str, Any]:
    candidates = [
        LOGS_DIR / "olist-token-health.json",
        LOGS_DIR / "tiny-token-health.json",
        STORAGE_DIR / "olist-token-health.json",
    ]
    token_data = {}
    for candidate in candidates:
        token_data = read_json(candidate, {})
        if token_data:
            break

    refreshed_at = token_data.get("refreshed_at")
    stale = True
    if isinstance(refreshed_at, str):
        try:
            stamp = datetime.fromisoformat(refreshed_at.replace("Z", "+00:00"))
            stale = datetime.now(timezone.utc) - stamp > timedelta(hours=2)
        except ValueError:
            stale = True

    return {
        "exists": bool(token_data),
        "refreshed_at": refreshed_at,
        "stale": stale,
        "provider": token_data.get("provider"),
    }


def email_signals() -> dict[str, Any]:
    report = read_json(EMAIL_CONFIG_FILE, {})
    checks = report.get("checks", {}) if isinstance(report, dict) else {}
    return {
        "exists": bool(report),
        "ok": bool(report.get("ok", False)),
        "checks": checks if isinstance(checks, dict) else {},
        "recipient_count": int(report.get("recipient_count", 0) or 0),
    }


def command_exists_in_learning(title: str, lessons: list[dict[str, Any]]) -> bool:
    target = re.sub(r"\s+", " ", title.strip().lower())
    for lesson in lessons:
        summary = re.sub(r"\s+", " ", str(lesson.get("summary", "")).strip().lower())
        if target and target in summary:
            return True
    return False


def generate_tasks_from_signals(queue: dict[str, Any], lessons: list[dict[str, Any]]) -> list[dict[str, Any]]:
    created: list[dict[str, Any]] = []
    signals = site_signals()
    tokens = token_signals()
    email = email_signals()
    learned_titles = recent_task_titles(lessons)

    diagnostics_task = {
        "title": "Executar auditoria autonoma ponta a ponta do site publico",
        "description": f"Validar home, robots, sitemap e pagina de termos em {SITE_URL}.",
        "priority": "high",
        "status": "pending",
        "source": "autonomous-site-analysis",
        "type": "diagnostic",
        "action": "site_audit",
        "assigned_to": ["gpt"],
        "metadata": {"signals": signals},
    }
    if diagnostics_task["title"].lower() not in learned_titles and not command_exists_in_learning(diagnostics_task["title"], lessons):
        _, inserted = upsert_task(queue, diagnostics_task)
        if inserted:
            created.append(diagnostics_task)

    if any(not item["ok"] for item in signals):
        task = {
            "title": "Corrigir indisponibilidades detectadas nas rotas publicas criticas",
            "description": "Uma ou mais rotas publicas responderam com erro ou timeout na verificacao autonoma.",
            "priority": "high",
            "status": "pending",
            "source": "autonomous-site-analysis",
            "type": "incident",
            "action": "site_repair_plan",
            "assigned_to": ["claude"],
            "metadata": {"signals": signals},
        }
        _, inserted = upsert_task(queue, task)
        if inserted:
            created.append(task)

    robots = next((item for item in signals if item["key"] == "robots"), None)
    sitemap = next((item for item in signals if item["key"] == "sitemap"), None)
    if robots and robots["ok"] and sitemap and sitemap["ok"]:
        robots_body = robots["body_excerpt"].lower()
        sitemap_body = sitemap["body_excerpt"].lower()
        if "sitemap:" not in robots_body or "shopvivaliz.com.br" not in sitemap_body:
            task = {
                "title": "Ajustar robots e sitemap para dominio principal e Merchant/SEO",
                "description": "Garantir robots.txt publico correto, apontamento de sitemap para dominio principal e base SEO consistente.",
                "priority": "high",
                "status": "pending",
                "source": "autonomous-seo-analysis",
                "type": "seo",
                "action": "seo_sitemap_validation",
                "assigned_to": ["claude"],
                "metadata": {"robots": robots, "sitemap": sitemap},
            }
            _, inserted = upsert_task(queue, task)
            if inserted:
                created.append(task)

    home = next((item for item in signals if item["key"] == "home"), None)
    if home and home["ok"] and not home["contains_org_jsonld"]:
        task = {
            "title": "Revisar dados estruturados institucionais da home",
            "description": "Home publica sem Organization JSON-LD detectado na analise automatica.",
            "priority": "medium",
            "status": "pending",
            "source": "autonomous-seo-analysis",
            "type": "seo",
            "action": "jsonld_home_review",
            "assigned_to": ["gemini"],
        }
        _, inserted = upsert_task(queue, task)
        if inserted:
            created.append(task)

    if tokens["stale"]:
        task = {
            "title": "Validar refresh automatico de token Olist/Tiny em janela de 2 horas",
            "description": "Fluxo de token esta ausente ou sem evidencia de renovacao recente. Confirmar agendamento e renovar diagnosticos.",
            "priority": "high",
            "status": "pending",
            "source": "autonomous-integration-analysis",
            "type": "integration",
            "action": "olist_token_refresh_check",
            "assigned_to": ["claude"],
            "requires_env": ["OLIST_CLIENT_ID", "OLIST_CLIENT_SECRET"],
            "metadata": tokens,
        }
        _, inserted = upsert_task(queue, task)
        if inserted:
            created.append(task)

    if email["exists"] and not email["ok"]:
        task = {
            "title": "Restaurar configuracao real de email do 24/7",
            "description": "A telemetria mostra que o canal SMTP nao esta totalmente valido; revisar aliases, destinatarios e autenticacao.",
            "priority": "high",
            "status": "pending",
            "source": "autonomous-integration-analysis",
            "type": "integration",
            "action": "email_delivery_health_check",
            "assigned_to": ["gpt"],
            "metadata": email,
        }
        _, inserted = upsert_task(queue, task)
        if inserted:
            created.append(task)

    return created[:MAX_GENERATED_PER_CYCLE]


def maybe_generate_tasks(queue: dict[str, Any], lessons: list[dict[str, Any]]) -> list[dict[str, Any]]:
    available = len(pending_tasks(queue)) + len(active_tasks(queue))
    if available >= QUEUE_LOW_WATERMARK:
        return []
    emit_step("core-agent", "Gerando novas tarefas a partir do estado real do projeto", status="producing")
    created = generate_tasks_from_signals(queue, lessons)
    if created:
        save_queue(queue)
        log_learning("core-agent", None, "backlog-growth", f"{len(created)} novas tarefas geradas a partir de sinais reais.", details={"titles": [task["title"] for task in created]})
    else:
        log_learning("core-agent", None, "backlog-growth", "Nenhuma nova tarefa segura foi gerada nesta rodada.")
    return created


def intervention_rows() -> list[dict[str, Any]]:
    return read_jsonl(INTERVENTIONS_FILE)


def intervention_responses() -> list[dict[str, Any]]:
    return read_jsonl(INTERVENTION_RESPONSES_FILE)


def intervention_pending_for(agent_id: str) -> list[dict[str, Any]]:
    answered = {str(item.get("command_id")) for item in intervention_responses() if item.get("command_id")}
    return [
        row for row in intervention_rows()
        if str(row.get("agent_id", "")).lower() == agent_id and str(row.get("id", "")) not in answered
    ]


def persist_intervention_response(payload: dict[str, Any]) -> None:
    append_jsonl(INTERVENTION_RESPONSES_FILE, payload)


def handle_interventions(agent_id: str, task: dict[str, Any], queue: dict[str, Any]) -> str | None:
    pending = intervention_pending_for(agent_id)
    if not pending:
        return None

    command = pending[-1]
    message = str(command.get("message", "")).strip()
    lowered = message.lower()
    emit_step(agent_id, "Pausando execucao para reavaliar ordem humana", status="waiting_feedback", task=task, details={"command_id": command.get("id"), "message": message})

    changes: list[str] = []
    if "prioriz" in lowered or "urgente" in lowered:
        task["priority"] = "high"
        changes.append("prioridade elevada para high")
    if "paus" in lowered:
        task["status"] = "pending"
        task["interrupted_at"] = utc_now()
        changes.append("tarefa devolvida para pending")
    if "reanalis" in lowered or "reavali" in lowered:
        task.setdefault("notes", []).append({"at": utc_now(), "type": "human-review", "message": message})
        changes.append("nota de reavaliacao adicionada")
    if not changes:
        task.setdefault("notes", []).append({"at": utc_now(), "type": "human-instruction", "message": message})
        changes.append("instrucao anexada ao contexto da tarefa")

    save_queue(queue)
    answer = {
        "timestamp": utc_now(),
        "command_id": command.get("id"),
        "agent_id": agent_id,
        "agent": agent_meta(agent_id)["label"],
        "status": "acknowledged",
        "message": f"[{agent_meta(agent_id)['label']}] Ordem recebida: {message}. Ajustes aplicados: {', '.join(changes)}.",
        "task_id": task.get("id"),
        "task_title": task.get("title"),
    }
    persist_intervention_response(answer)
    log_learning(agent_id, task, "human-intervention", answer["message"], details={"command_id": command.get("id"), "changes": changes})
    emit_step(agent_id, "Retomando execucao apos intervencao humana", status="producing", task=task, details={"changes": changes})
    return answer["message"]


def update_live_status(queue: dict[str, Any], cycle: dict[str, Any], generated_tasks: list[dict[str, Any]]) -> None:
    latest_steps = read_jsonl(STEP_EVENTS_FILE)[-120:]
    agents: dict[str, Any] = {}
    for agent_id, meta in AGENTS.items():
        agent_steps = [row for row in latest_steps if row.get("agent_id") == agent_id]
        latest = agent_steps[-1] if agent_steps else None
        agents[agent_id] = {
            "id": agent_id,
            "label": meta["label"],
            "role": meta["role"],
            "status": latest.get("status") if latest else "idle",
            "action": latest.get("action") if latest else "Aguardando trabalho",
            "last_event_at": latest.get("timestamp") if latest else None,
            "steps": agent_steps[-12:],
        }

    write_json(
        STATUS_FILE,
        {
            "generated_at": utc_now(),
            "queue": {
                "pending": len(pending_tasks(queue)),
                "active": len(active_tasks(queue)),
                "completed": len([task for task in queue.get("queue", []) if str(task.get("status", "")).lower() in STOP_STATUSES]),
            },
            "generated_tasks": [task.get("title") for task in generated_tasks],
            "cycle": cycle,
            "agents": agents,
        },
    )


def execute_task(agent_id: str, task: dict[str, Any]) -> dict[str, Any]:
    action = str(task.get("action") or "")
    emit_step(agent_id, f"Iniciando tarefa: {task.get('title')}", task=task)
    output: dict[str, Any] = {"status": "completed", "summary": "Tarefa concluida sem incidentes.", "tests": []}

    if action == "site_audit":
        emit_step(agent_id, "Auditando rotas publicas e sinais SEO", task=task)
        output["site_signals"] = site_signals()
        output["tests"].append("urlopen(home, robots, sitemap, termos)")
    elif action == "seo_sitemap_validation":
        emit_step(agent_id, "Validando robots, sitemap e dominio principal", task=task)
        output["site_signals"] = site_signals()
        output["tests"].append("urlopen(robots.txt, sitemap.xml)")
    elif action == "olist_token_refresh_check":
        emit_step(agent_id, "Verificando refresh de token Olist/Tiny", task=task)
        output["token"] = token_signals()
        output["tests"].append("token_signals()")
    elif action == "jsonld_home_review":
        emit_step(agent_id, "Inspecionando JSON-LD da home publica", task=task)
        status_code, body = fetch_url(SITE_URL.rstrip("/") + "/")
        output["tests"].append("urlopen(home)")
        output["home_status_code"] = status_code
        output["has_org_jsonld"] = '"@type":"organization"' in body.lower() or '"@type": "organization"' in body.lower()
    elif action == "email_delivery_health_check":
        emit_step(agent_id, "Validando configuracao do email autonomo e aliases SMTP", task=task)
        result = run([sys.executable, str(ROOT / "scripts" / "automation" / "validate_email_config.py")], timeout=120)
        output["tests"].append("python scripts/automation/validate_email_config.py")
        output["exit_code"] = result.returncode
        output["stdout_tail"] = result.stdout[-2000:]
        output["stderr_tail"] = result.stderr[-2000:]
        output["email"] = read_json(EMAIL_CONFIG_FILE, {})
        if result.returncode != 0:
            output["status"] = "failed"
            output["summary"] = "Configuracao de email ainda requer correcao."
        else:
            output["summary"] = "Configuracao de email validada com sucesso."
    else:
        emit_step(agent_id, "Executando diagnostico generico orientado por fila", task=task)
        result = run([sys.executable, str(ROOT / "scripts" / "system-health-check.py")], timeout=240)
        output["tests"].append("python scripts/system-health-check.py")
        output["exit_code"] = result.returncode
        output["stdout_tail"] = result.stdout[-2000:]
        output["stderr_tail"] = result.stderr[-2000:]
        if result.returncode != 0 and "STATUS FINAL: WARNING" in result.stdout:
            output["status"] = "completed"
            output["summary"] = "Health check concluiu com warning auditavel."
        elif result.returncode != 0:
            output["status"] = "failed"
            output["summary"] = "Health check retornou erro."

    return output


def choose_executor(task: dict[str, Any]) -> str:
    assigned = task.get("assigned_to")
    if isinstance(assigned, list) and assigned:
        return str(assigned[0]).lower()
    if isinstance(assigned, str) and assigned:
        return assigned.lower()
    text = " ".join([str(task.get("title", "")), str(task.get("description", "")), str(task.get("action", ""))]).lower()
    if any(token in text for token in ("seo", "jsonld", "catalogo", "merchant")):
        return "gemini"
    if any(token in text for token in ("fix", "corrigir", "token", "api", "deploy", "checkout")):
        return "claude"
    return "gpt"


def pick_next_task(queue: dict[str, Any]) -> dict[str, Any] | None:
    tasks = executable_pending_tasks(queue)
    return tasks[0] if tasks else None


def write_cycle_report(report: dict[str, Any]) -> None:
    write_json(REPORT_FILE, report)
    append_jsonl(REPORT_EVENTS_FILE, {"timestamp": utc_now(), "kind": "cycle-report", "report": report})


def detect_unproductive_loop(task: dict[str, Any], recent_cycles: list[dict[str, Any]]) -> tuple[bool, str]:
    """Req 13: Detect unproductive loops and theater."""
    task_id = task.get("id")

    same_task_cycles = [c for c in recent_cycles[-10:] if c.get("task", {}).get("id") == task_id]
    if len(same_task_cycles) >= 3:
        statuses = [c.get("task", {}).get("status") for c in same_task_cycles]
        if len(set(statuses)) == 1:
            return True, f"Same task {task_id} executed 3+ times without status change"

    test_commands = [c.get("last_command") for c in recent_cycles[-5:] if "test" in str(c.get("last_command", ""))]
    if len(test_commands) > 0 and len(set(test_commands)) == 1:
        if recent_cycles[-1].get("last_result", {}).get("diff_size", 0) == 0:
            return True, "Same test command without code change detected"

    recent_results = [c.get("last_result", {}) for c in recent_cycles[-5:]]
    if all(r.get("evidence_hash") == recent_results[-1].get("evidence_hash") for r in recent_results[-3:]):
        return True, "Identical evidence in 3+ consecutive cycles (heartbeat without new work)"

    return False, ""


def execute_cycle(queue: dict[str, Any]) -> dict[str, Any]:
    lessons = recent_learning()
    generated_tasks = maybe_generate_tasks(queue, lessons)
    task = pick_next_task(queue)

    cycle: dict[str, Any] = {
        "generated_at": utc_now(),
        "generated_tasks": [task.get("title") for task in generated_tasks],
        "selection": {"task": task, "reason": "next_executable_pending_task" if task else "queue_empty_after_generation"},
        "status": "idle",
        "email_health": email_signals(),
        "learning_tail": [row.get("summary") for row in lessons[-5:]],
    }

    if task and task.get("status") == "in_progress":
        recent_cycles = read_jsonl(REPORT_EVENTS_FILE)[-10:] if REPORT_EVENTS_FILE.exists() else []
        is_loop, loop_reason = detect_unproductive_loop(task, recent_cycles)
        if is_loop:
            log_learning("core-agent", task, "unproductive_loop", loop_reason)
            agent_id = task.get("assigned_to", ["gpt"])[0]
            emit_step(agent_id, f"PAUSED: {loop_reason}", status="paused")
            task["status"] = "blocked_loop"
            task["loop_reason"] = loop_reason
            save_queue(queue)
            append_jsonl(REPORT_EVENTS_FILE, {"timestamp": utc_now(), "kind": "loop-detected", "task_id": task.get("id"), "reason": loop_reason})
            cycle["result"] = {"status": "loop_detected", "summary": loop_reason}
            write_cycle_report(cycle)
            return cycle

    if task is None:
        emit_step("core-agent", "Fila vazia apos auto-geracao; aguardando proximo ciclo", status="waiting_feedback")
        cycle["result"] = {"status": "idle", "summary": "Nenhuma tarefa segura elegivel encontrada."}
        update_live_status(queue, cycle, generated_tasks)
        write_cycle_report(cycle)
        return cycle

    agent_id = choose_executor(task)
    task["assigned_to"] = [agent_id]
    task["status"] = "in_progress"
    task["started_at"] = utc_now()
    save_queue(queue)

    handle_interventions(agent_id, task, queue)
    result = execute_task(agent_id, task)

    task["last_result"] = result
    task["finished_at"] = utc_now()
    if result.get("status") == "failed":
        task["status"] = "pending"
        task["last_error"] = result.get("summary")
        cycle["status"] = "failed"
    else:
        task["status"] = "completed"
        cycle["status"] = "completed"

    save_queue(queue)
    summary = str(result.get("summary") or "Execucao concluida.")
    log_learning(agent_id, task, "task-execution", summary, details=result)
    emit_step(agent_id, f"Finalizou tarefa: {task.get('title')}", status="producing", task=task, details={"result_status": result.get("status", "completed")})

    cycle["task"] = {"id": task.get("id"), "title": task.get("title"), "agent_id": agent_id}
    cycle["result"] = result
    update_live_status(queue, cycle, generated_tasks)
    write_cycle_report(cycle)
    return cycle


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--max-cycles", type=int, default=int(os.getenv("AUTONOMOUS_MAX_CYCLES", "1")))
    args = parser.parse_args()

    ensure_dirs()
    queue = load_queue()
    reports: list[dict[str, Any]] = []
    emit_step("core-agent", "Executor autonomo iniciado", status="producing")

    for _ in range(max(1, args.max_cycles)):
        queue = load_queue()
        cycle = execute_cycle(queue)
        reports.append(cycle)
        if cycle.get("status") == "idle":
            break

    final = {
        "generated_at": utc_now(),
        "status": reports[-1]["status"] if reports else "idle",
        "cycles": reports,
        "queue_file": str(CANONICAL_QUEUE.relative_to(ROOT)),
        "legacy_queue_file": str(LEGACY_QUEUE.relative_to(ROOT)),
    }
    write_json(AUTONOMOUS_DIR / "last_autonomous_executor_run.json", final)
    print(json.dumps(final, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
