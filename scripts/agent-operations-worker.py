#!/usr/bin/env python3
"""Atualiza heartbeats, distribui tarefas e registra timeline viva por agente."""
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from task_queue_lib import load_queue, save_queue

ROOT = Path(__file__).resolve().parents[1]
HEARTBEAT_DIR = ROOT / ".agent-heartbeats"
LOGS_DIR = ROOT / "logs"
STORAGE_DIR = ROOT / "storage" / "private"
COMMANDS_FILE = LOGS_DIR / "agent-commands.jsonl"
RESPONSES_FILE = LOGS_DIR / "monitor-responses.jsonl"
MESSAGES_FILE = LOGS_DIR / "monitor-messages.log"
INTERVENTIONS_FILE = STORAGE_DIR / "agent-interventions.jsonl"
INTERVENTION_RESPONSES_FILE = STORAGE_DIR / "agent-intervention-responses.jsonl"
RUNTIME_STATE_FILE = LOGS_DIR / "agent-runtime-state.json"
STEP_LOG_FILE = LOGS_DIR / "agent-execution-steps.jsonl"
MAX_STEPS_PER_AGENT = 10

AGENTS = {
    "claude": {
        "name": "Claude",
        "role": "Implementacao e codigo",
        "keywords": ("checkout", "api", "php", "fix", "corrigir", "monitor", "deploy"),
    },
    "gemini": {
        "name": "Gemini",
        "role": "Arquitetura e descoberta",
        "keywords": ("catalogo", "seo", "backlog", "gerar", "discovery", "mercado", "shopee"),
    },
    "gpt": {
        "name": "ChatGPT",
        "role": "Validacao e QA",
        "keywords": ("teste", "qa", "validar", "monitorar", "smoke", "browser"),
    },
}


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def local_time_label() -> str:
    return datetime.now().astimezone().strftime("%H:%M:%S")


def ensure_dirs() -> None:
    HEARTBEAT_DIR.mkdir(parents=True, exist_ok=True)
    LOGS_DIR.mkdir(parents=True, exist_ok=True)
    STORAGE_DIR.mkdir(parents=True, exist_ok=True)


def read_json(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return payload if isinstance(payload, dict) else {}


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    items: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict):
            items.append(row)
    return items


def append_jsonl(path: Path, row: dict[str, Any]) -> None:
    with path.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(row, ensure_ascii=False) + "\n")


def bootstrap_runtime_state() -> dict[str, Any]:
    runtime_state = read_json(RUNTIME_STATE_FILE)
    agents_state = runtime_state.setdefault("agents", {})
    for agent_id, meta in AGENTS.items():
        state = agents_state.setdefault(agent_id, {})
        state.setdefault("agent_id", agent_id)
        state.setdefault("name", meta["name"])
        state.setdefault("role", meta["role"])
        state.setdefault("current_focus", "Aguardando tarefa")
        state.setdefault("passos_execucao", [])
        state.setdefault("last_updated_at", utc_now())
    runtime_state["generated_at"] = utc_now()
    return runtime_state


def push_step(runtime_state: dict[str, Any], agent_id: str, message: str, *, kind: str = "activity", extra: dict[str, Any] | None = None) -> None:
    meta = AGENTS[agent_id]
    agent_state = runtime_state.setdefault("agents", {}).setdefault(agent_id, {
        "agent_id": agent_id,
        "name": meta["name"],
        "role": meta["role"],
        "current_focus": "Aguardando tarefa",
        "passos_execucao": [],
        "last_updated_at": utc_now(),
    })
    step = {
        "timestamp": utc_now(),
        "label": f"[{local_time_label()}] {message}",
        "message": message,
        "kind": kind,
    }
    if extra:
        step["extra"] = extra

    steps = list(agent_state.get("passos_execucao", []))
    steps.append(step)
    agent_state["passos_execucao"] = steps[-MAX_STEPS_PER_AGENT:]
    agent_state["last_updated_at"] = step["timestamp"]
    runtime_state["generated_at"] = step["timestamp"]
    append_jsonl(STEP_LOG_FILE, {"agent_id": agent_id, **step})


def set_focus(runtime_state: dict[str, Any], agent_id: str, focus: str) -> None:
    agent_state = runtime_state.setdefault("agents", {}).setdefault(agent_id, {})
    agent_state["current_focus"] = focus
    agent_state["last_updated_at"] = utc_now()


def choose_agent(task: dict[str, Any]) -> str:
    text = " ".join(
        [
            str(task.get("title", "")),
            str(task.get("description", "")),
            " ".join(str(item) for item in task.get("tags", [])),
        ]
    ).lower()
    best_agent = "gpt"
    best_score = -1
    for agent_id, meta in AGENTS.items():
        score = sum(1 for keyword in meta["keywords"] if keyword in text)
        if score > best_score:
            best_agent = agent_id
            best_score = score
    return best_agent


def assign_pending_tasks(runtime_state: dict[str, Any]) -> list[dict[str, Any]]:
    queue = load_queue()
    assigned: list[dict[str, Any]] = []
    changed = False
    for task in queue.get("queue", []):
        if task.get("status") != "pending":
            continue
        if task.get("assigned_to"):
            continue
        agent_id = choose_agent(task)
        task["assigned_to"] = [agent_id]
        task["assignment_updated_at"] = utc_now()
        assigned.append({"id": task.get("id"), "title": task.get("title"), "agent_id": agent_id})
        push_step(
            runtime_state,
            agent_id,
            f"Assumindo a tarefa '{task.get('title')}' na fila compartilhada",
            kind="assignment",
            extra={"task_id": task.get("id")},
        )
        changed = True
    if changed:
        save_queue(queue)
    return assigned


def tasks_for_agent(queue: dict[str, Any], agent_id: str) -> list[dict[str, Any]]:
    found = []
    for task in queue.get("queue", []):
        assigned = task.get("assigned_to")
        if isinstance(assigned, str) and assigned.lower() == agent_id:
            found.append(task)
        elif isinstance(assigned, list) and agent_id in [str(item).lower() for item in assigned]:
            found.append(task)
    return found


def validation_command_for(agent_id: str, focus: str) -> str:
    if agent_id == "claude":
        return "php -l api/monitor/api.php"
    if agent_id == "gemini":
        return "python scripts/auto-task-generator.py"
    return "python scripts/agent-operations-worker.py"


def record_agent_activity(queue: dict[str, Any], runtime_state: dict[str, Any]) -> None:
    for agent_id in AGENTS:
        tasks = tasks_for_agent(queue, agent_id)
        focus = tasks[0].get("title") if tasks else "Aguardando tarefa"
        set_focus(runtime_state, agent_id, focus)
        push_step(runtime_state, agent_id, f"Lendo a fila canônica para atualizar foco atual: {focus}", kind="read-queue")
        if tasks:
            current_task = tasks[0]
            push_step(
                runtime_state,
                agent_id,
                f"Lendo o contexto da tarefa '{current_task.get('title')}' para identificar o próximo bloco de execução",
                kind="task-context",
                extra={"task_id": current_task.get("id")},
            )
            push_step(
                runtime_state,
                agent_id,
                f"Executando comando de teste: {validation_command_for(agent_id, focus)}",
                kind="validation",
                extra={"task_id": current_task.get("id")},
            )
        else:
            push_step(runtime_state, agent_id, "Sem tarefa atribuída agora; aguardando novas missões da auto-geração", kind="idle")


def write_heartbeats(queue: dict[str, Any], runtime_state: dict[str, Any]) -> None:
    for agent_id, meta in AGENTS.items():
        tasks = tasks_for_agent(queue, agent_id)
        current_focus = tasks[0].get("title") if tasks else "Aguardando tarefa"
        passos_execucao = runtime_state.get("agents", {}).get(agent_id, {}).get("passos_execucao", [])
        payload = {
            "agent_id": agent_id,
            "name": meta["name"],
            "role": meta["role"],
            "timestamp": utc_now(),
            "unix_timestamp": int(datetime.now(timezone.utc).timestamp()),
            "status": "alive",
            "tasks_processed": len([task for task in tasks if task.get("status") in ("completed", "done")]),
            "current_focus": current_focus,
            "assigned_count": len(tasks),
            "passos_execucao": passos_execucao[-MAX_STEPS_PER_AGENT:],
        }
        (HEARTBEAT_DIR / f"{agent_id}.heartbeat").write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")


def build_agent_reply(agent_id: str, command: dict[str, Any], queue: dict[str, Any], *, supervisor_mode: bool = False) -> dict[str, Any]:
    tasks = tasks_for_agent(queue, agent_id)
    focus = tasks[0].get("title") if tasks else "Aguardando tarefa"
    assigned_count = len(tasks)
    prefix = "Ordem do supervisor recebida" if supervisor_mode else "Comando recebido"
    return {
        "agent": AGENTS[agent_id]["name"],
        "agent_id": agent_id,
        "message": f"[{AGENTS[agent_id]['name']}] {prefix}: {command.get('message', '')}. Foco atual: {focus}. Tarefas atribuídas: {assigned_count}.",
        "timestamp": utc_now(),
        "command_id": command.get("id"),
        "status": "acknowledged",
    }


def process_commands(queue: dict[str, Any], runtime_state: dict[str, Any]) -> int:
    rows = read_jsonl(COMMANDS_FILE)
    existing_responses = {
        str(row.get("command_id", ""))
        for row in read_jsonl(RESPONSES_FILE)
        if row.get("command_id")
    }
    processed = 0
    for row in rows:
        if row.get("status") != "queued":
            continue
        if str(row.get("id", "")) in existing_responses:
            continue
        agent_id = str(row.get("agent_id", "")).lower()
        if agent_id not in AGENTS:
            continue
        push_step(runtime_state, agent_id, f"Lendo mensagem operacional da fila: {row.get('message', '')}", kind="command", extra={"command_id": row.get("id")})
        reply = build_agent_reply(agent_id, row, queue)
        append_jsonl(RESPONSES_FILE, reply)
        push_step(runtime_state, agent_id, "Enviando resposta de sucesso para a fila e aguardando validação do deploy", kind="response", extra={"command_id": row.get("id")})
        processed += 1
    return processed


def process_supervisor_interventions(queue: dict[str, Any], runtime_state: dict[str, Any]) -> int:
    rows = read_jsonl(INTERVENTIONS_FILE)
    existing_responses = {
        str(row.get("command_id", ""))
        for row in read_jsonl(INTERVENTION_RESPONSES_FILE)
        if row.get("command_id")
    }
    processed = 0
    for row in rows:
        if str(row.get("status", "queued")).lower() != "queued":
            continue
        if str(row.get("id", "")) in existing_responses:
            continue
        agent_id = str(row.get("agent_id", "")).lower()
        if agent_id not in AGENTS:
            continue
        push_step(runtime_state, agent_id, "Pausado por Intervenção do Supervisor", kind="pause", extra={"command_id": row.get("id")})
        push_step(runtime_state, agent_id, f"Reavaliando prioridades com a instrução: {row.get('message', '')}", kind="supervisor", extra={"command_id": row.get("id")})
        reply = build_agent_reply(agent_id, row, queue, supervisor_mode=True)
        append_jsonl(INTERVENTION_RESPONSES_FILE, reply)
        push_step(runtime_state, agent_id, "Retomando o trabalho real após intervenção humana", kind="resume", extra={"command_id": row.get("id")})
        processed += 1
    return processed


def main() -> int:
    ensure_dirs()
    runtime_state = bootstrap_runtime_state()
    assigned = assign_pending_tasks(runtime_state)
    queue = load_queue()
    record_agent_activity(queue, runtime_state)
    processed = process_commands(queue, runtime_state)
    supervisor_processed = process_supervisor_interventions(queue, runtime_state)
    write_heartbeats(queue, runtime_state)
    write_json(RUNTIME_STATE_FILE, runtime_state)
    print(json.dumps({
        "ok": True,
        "assigned": assigned,
        "commands_processed": processed,
        "supervisor_commands_processed": supervisor_processed,
        "generated_at": utc_now(),
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
