#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def repo_root() -> Path:
    current = Path(__file__).resolve().parent
    for candidate in [current, *current.parents]:
        if (candidate / ".git").exists() or (candidate / "AGENTS.md").exists():
            return candidate
    return current


ROOT = repo_root()
DATA_DIR = Path(os.environ.get("CODEX_BRIDGE_DATA_DIR") or ROOT / "storage" / "codex-bridge")
MESSAGES_FILE = DATA_DIR / "messages.jsonl"
STATE_FILE = DATA_DIR / "state.json"


def ensure_storage() -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    if not MESSAGES_FILE.exists():
        MESSAGES_FILE.write_text("", encoding="utf-8")
    if not STATE_FILE.exists():
        STATE_FILE.write_text(json.dumps({"last_start": None, "last_message_id": None}, indent=2) + "\n", encoding="utf-8")


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    rows: list[dict[str, Any]] = []
    for raw in path.read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if not raw:
            continue
        try:
            item = json.loads(raw)
            if isinstance(item, dict):
                rows.append(item)
        except json.JSONDecodeError:
            continue
    return rows


def append_jsonl(path: Path, row: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def state_update(**updates: Any) -> None:
    ensure_storage()
    state: dict[str, Any] = {}
    if STATE_FILE.exists():
        try:
            loaded = json.loads(STATE_FILE.read_text(encoding="utf-8"))
            if isinstance(loaded, dict):
                state = loaded
        except json.JSONDecodeError:
            state = {}
    state.update(updates)
    STATE_FILE.write_text(json.dumps(state, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def tool_post_message(arguments: dict[str, Any]) -> dict[str, Any]:
    ensure_storage()
    sender = str(arguments.get("from") or arguments.get("sender") or "unknown").strip() or "unknown"
    recipient = str(arguments.get("to") or arguments.get("recipient") or "*").strip() or "*"
    title = str(arguments.get("title") or arguments.get("subject") or "").strip()
    body = str(arguments.get("body") or "").strip()
    thread = str(arguments.get("thread") or arguments.get("thread_id") or uuid.uuid4()).strip()
    if not body:
        raise ValueError("body is required")

    row = {
        "id": str(uuid.uuid4()),
        "thread": thread,
        "ts": now_iso(),
        "from": sender,
        "to": recipient,
        "title": title,
        "body": body,
        "host": os.environ.get("COMPUTERNAME") or os.environ.get("HOSTNAME") or "",
        "branch": os.environ.get("GIT_BRANCH") or "",
        "read": False,
    }
    append_jsonl(MESSAGES_FILE, row)
    state_update(last_start=state_get("last_start"), last_message_id=row["id"])
    return {
        "resultType": "complete",
        "message_id": row["id"],
        "thread": thread,
        "stored_at": row["ts"],
    }


def state_get(key: str, default: Any = None) -> Any:
    if not STATE_FILE.exists():
        return default
    try:
        loaded = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        if isinstance(loaded, dict):
            return loaded.get(key, default)
    except json.JSONDecodeError:
        pass
    return default


def tool_read_messages(arguments: dict[str, Any]) -> dict[str, Any]:
    ensure_storage()
    recipient = str(arguments.get("recipient") or "*").strip() or "*"
    thread = str(arguments.get("thread") or "").strip()
    limit = int(arguments.get("limit") or 20)
    since_id = str(arguments.get("since_id") or "").strip()

    rows = read_jsonl(MESSAGES_FILE)
    if since_id:
        seen = False
        filtered: list[dict[str, Any]] = []
        for row in rows:
            if seen:
                filtered.append(row)
            elif row.get("id") == since_id:
                seen = True
        rows = filtered

    if thread:
        rows = [row for row in rows if str(row.get("thread") or "") == thread]

    if recipient != "*":
        rows = [
            row for row in rows
            if str(row.get("to") or "*") in ("*", recipient) or str(row.get("from") or "") == recipient
        ]

    rows = rows[-max(1, limit):]
    return {
        "resultType": "complete",
        "messages": rows,
        "count": len(rows),
        "last_message_id": rows[-1]["id"] if rows else None,
    }


def tool_status(arguments: dict[str, Any]) -> dict[str, Any]:
    ensure_storage()
    try:
        branch = os.popen("git branch --show-current").read().strip()
        head = os.popen("git rev-parse HEAD").read().strip()
    except Exception:
        branch = ""
        head = ""
    rows = read_jsonl(MESSAGES_FILE)
    return {
        "resultType": "complete",
        "project": "ShopVivaliz",
        "repo_root": str(ROOT),
        "branch": branch,
        "head": head,
        "message_count": len(rows),
        "last_message_id": rows[-1]["id"] if rows else None,
    }


TOOLS = [
    {
        "name": "post_message",
        "description": "Posta uma mensagem para outro Codex ou para um grupo compartilhado.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "from": {"type": "string"},
                "to": {"type": "string", "default": "*"},
                "title": {"type": "string"},
                "body": {"type": "string"},
                "thread": {"type": "string"},
            },
            "required": ["body"],
        },
    },
    {
        "name": "read_messages",
        "description": "Lê mensagens do mailbox compartilhado.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "recipient": {"type": "string", "default": "*"},
                "thread": {"type": "string"},
                "limit": {"type": "integer", "default": 20},
                "since_id": {"type": "string"},
            },
        },
    },
    {
        "name": "bridge_status",
        "description": "Mostra o estado do bridge, branch atual e contagem de mensagens.",
        "inputSchema": {"type": "object", "properties": {}},
    },
]


def jsonrpc_result(request_id: Any, result: dict[str, Any]) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": request_id, "result": result}


def jsonrpc_error(request_id: Any, code: int, message: str, data: Any = None) -> dict[str, Any]:
    payload = {"jsonrpc": "2.0", "id": request_id, "error": {"code": code, "message": message}}
    if data is not None:
        payload["error"]["data"] = data
    return payload


def handle_request(payload: dict[str, Any]) -> dict[str, Any] | None:
    method = payload.get("method")
    request_id = payload.get("id")
    params = payload.get("params") or {}

    if method == "initialize":
        return jsonrpc_result(request_id, {
            "protocolVersion": "2025-11-25",
            "serverInfo": {
                "name": "shopvivaliz-codex-mesh-bridge",
                "version": "1.0.0",
            },
            "capabilities": {
                "tools": {},
            },
            "instructions": (
                "Use this bridge to exchange messages between Codex instances. "
                "Prefer short, actionable updates with a thread id when the task spans machines."
            ),
        })

    if method == "notifications/initialized":
        return None

    if method == "tools/list":
        return jsonrpc_result(request_id, {"tools": TOOLS})

    if method == "tools/call":
        name = str(params.get("name") or "").strip()
        arguments = params.get("arguments") or params.get("input") or {}
        if not isinstance(arguments, dict):
            arguments = {}
        try:
            if name == "post_message":
                result = tool_post_message(arguments)
            elif name == "read_messages":
                result = tool_read_messages(arguments)
            elif name == "bridge_status":
                result = tool_status(arguments)
            else:
                return jsonrpc_error(request_id, -32601, f"Unknown tool: {name}")
        except Exception as exc:
            return jsonrpc_error(request_id, -32000, str(exc))

        return jsonrpc_result(request_id, {
            "content": [
                {
                    "type": "text",
                    "text": json.dumps(result, ensure_ascii=False, indent=2),
                }
            ],
            "structuredContent": result,
        })

    if method == "ping":
        return jsonrpc_result(request_id, {})

    return jsonrpc_error(request_id, -32601, f"Unknown method: {method}")


def main() -> int:
    # MCP stdio is always UTF-8. On Windows, Python otherwise inherits the
    # legacy cp1252 code page; accented tool descriptions then produce invalid
    # UTF-8 and Codex waits for tools/list until startup times out.
    sys.stdin.reconfigure(encoding="utf-8")
    sys.stdout.reconfigure(encoding="utf-8")
    ensure_storage()
    state_update(last_start=now_iso())

    for raw in sys.stdin:
        raw = raw.strip()
        if not raw:
            continue
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            continue

        if not isinstance(payload, dict):
            continue

        response = handle_request(payload)
        if response is not None and payload.get("id") is not None:
            print(json.dumps(response, ensure_ascii=False), flush=True)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
