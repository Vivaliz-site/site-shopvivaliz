#!/usr/bin/env python3
"""Atualiza heartbeats, distribui tarefas e processa comandos dos agentes."""
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from task_queue_lib import load_queue, save_queue

ROOT = Path(__file__).resolve().parents[1]
HEARTBEAT_DIR = ROOT / ".agent-heartbeats"
COMMANDS_FILE = ROOT / "logs" / "agent-commands.jsonl"
RESPONSES_FILE = ROOT / "logs" / "monitor-responses.jsonl"
MESSAGES_FILE = ROOT / "logs" / "monitor-messages.log"

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


def ensure_dirs() -> None:
    HEARTBEAT_DIR.mkdir(parents=True, exist_ok=True)
    COMMANDS_FILE.parent.mkdir(parents=True, exist_ok=True)
    RESPONSES_FILE.parent.mkdir(parents=True, exist_ok=True)
    MESSAGES_FILE.parent.mkdir(parents=True, exist_ok=True)


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


def write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    path.write_text(
        "\n".join(json.dumps(row, ensure_ascii=False) for row in rows) + ("\n" if rows else ""),
        encoding="utf-8",
    )


def append_jsonl(path: Path, row: dict[str, Any]) -> None:
    with path.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(row, ensure_ascii=False) + "\n")


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


def assign_pending_tasks() -> list[dict[str, Any]]:
    queue = load_queue()
    assigned: list[dict[str, Any]] = []
    changed = False
    for task in queue.get("queue", []):
        if task.get("status") != "pending":
            continue
        assigned_to = task.get("assigned_to")
        if assigned_to:
            continue
        agent_id = choose_agent(task)
        task["assigned_to"] = [agent_id]
        task["assignment_updated_at"] = utc_now()
        assigned.append({"id": task.get("id"), "title": task.get("title"), "agent_id": agent_id})
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


def write_heartbeats(queue: dict[str, Any]) -> None:
    for agent_id, meta in AGENTS.items():
        tasks = tasks_for_agent(queue, agent_id)
        current_focus = tasks[0].get("title") if tasks else "Aguardando tarefa"
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
        }
        (HEARTBEAT_DIR / f"{agent_id}.heartbeat").write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")


def build_agent_reply(agent_id: str, command: dict[str, Any], queue: dict[str, Any]) -> dict[str, Any]:
    tasks = tasks_for_agent(queue, agent_id)
    focus = tasks[0].get("title") if tasks else "Aguardando tarefa"
    assigned_count = len(tasks)
    message = (
        f"[{AGENTS[agent_id]['name']}] Comando recebido: {command.get('message', '')}. "
        f"Foco atual: {focus}. Tarefas atribuídas: {assigned_count}."
    )
    return {
        "agent": AGENTS[agent_id]["name"],
        "agent_id": agent_id,
        "message": message,
        "timestamp": utc_now(),
        "command_id": command.get("id"),
        "status": "acknowledged",
    }


def process_commands(queue: dict[str, Any]) -> int:
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
        reply = build_agent_reply(agent_id, row, queue)
        append_jsonl(RESPONSES_FILE, reply)
        processed += 1
    return processed


def main() -> int:
    ensure_dirs()
    assigned = assign_pending_tasks()
    queue = load_queue()
    write_heartbeats(queue)
    processed = process_commands(queue)
    print(json.dumps({
        "ok": True,
        "assigned": assigned,
        "commands_processed": processed,
        "generated_at": utc_now(),
    }, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
