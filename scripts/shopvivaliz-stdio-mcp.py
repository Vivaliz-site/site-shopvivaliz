#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any

try:
    import requests
except ImportError:
    print("❌ Dependência ausente: requests", file=sys.stderr)
    raise SystemExit(1)


REPO_ROOT = Path(__file__).resolve().parent.parent
REMOTE_DIR = REPO_ROOT / "storage" / "remote-access"
CONNECTION_FILE = REMOTE_DIR / "connection.json"
API_KEY_FILE = REMOTE_DIR / "api-key.txt"
DEFAULT_GATEWAY_URL = "http://127.0.0.1:5560"


def load_gateway_url() -> str:
    env_url = os.getenv("SHOPVIVALIZ_REMOTE_GATEWAY_URL", "").strip()
    if env_url:
        return env_url.rstrip("/")
    if CONNECTION_FILE.exists():
        try:
            data = json.loads(CONNECTION_FILE.read_text(encoding="utf-8"))
            for key in ("status_url", "health_url"):
                url = str(data.get(key) or "").strip()
                if url.startswith("http://"):
                    return url.rsplit("/", 1)[0]
        except Exception:
            pass
    return DEFAULT_GATEWAY_URL


def load_api_key() -> str:
    env_key = os.getenv("SHOPVIVALIZ_REMOTE_API_KEY", "").strip()
    if env_key:
        return env_key
    if API_KEY_FILE.exists():
        return API_KEY_FILE.read_text(encoding="utf-8").strip()
    return ""


GATEWAY_URL = load_gateway_url()
API_KEY = load_api_key()


def gateway_headers() -> dict[str, str]:
    headers = {"Content-Type": "application/json"}
    if API_KEY:
        headers["X-API-Key"] = API_KEY
    return headers


def call_gateway(method: str, path: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
    url = f"{GATEWAY_URL}{path}"
    try:
        if method == "GET":
            response = requests.get(url, headers=gateway_headers(), timeout=30)
        else:
            response = requests.request(method, url, headers=gateway_headers(), json=payload or {}, timeout=120)
        response.raise_for_status()
        return response.json()
    except Exception as exc:
        return {"ok": False, "error": str(exc)}


TOOLS = [
    {
        "name": "gateway_status",
        "description": "Mostra status, browser e Tailscale do gateway remoto.",
        "inputSchema": {"type": "object", "properties": {}},
    },
    {
        "name": "gateway_health",
        "description": "Verifica saúde do gateway remoto.",
        "inputSchema": {"type": "object", "properties": {}},
    },
    {
        "name": "execute_command",
        "description": "Executa comando shell no PC.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "command": {"type": "string"},
                "timeout": {"type": "integer", "default": 30},
            },
            "required": ["command"],
        },
    },
    {
        "name": "execute_git_command",
        "description": "Executa comando git no repositório.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "command": {"type": "string"},
                "timeout": {"type": "integer", "default": 30},
            },
            "required": ["command"],
        },
    },
    {
        "name": "read_file",
        "description": "Lê arquivo dentro do repositório.",
        "inputSchema": {
            "type": "object",
            "properties": {"path": {"type": "string"}},
            "required": ["path"],
        },
    },
    {
        "name": "write_file",
        "description": "Escreve arquivo dentro do repositório.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "path": {"type": "string"},
                "content": {"type": "string"},
            },
            "required": ["path", "content"],
        },
    },
    {
        "name": "get_logs",
        "description": "Lê o fim dos logs do sistema.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "log_type": {"type": "string", "default": "mcp"},
                "lines": {"type": "integer", "default": 50},
            },
        },
    },
    {
        "name": "browser_open",
        "description": "Abre URL no browser controlado.",
        "inputSchema": {
            "type": "object",
            "properties": {"url": {"type": "string"}},
            "required": ["url"],
        },
    },
    {
        "name": "browser_click",
        "description": "Clica em seletor CSS.",
        "inputSchema": {
            "type": "object",
            "properties": {"selector": {"type": "string"}},
            "required": ["selector"],
        },
    },
    {
        "name": "browser_fill",
        "description": "Preenche campo no browser.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "selector": {"type": "string"},
                "text": {"type": "string"},
            },
            "required": ["selector", "text"],
        },
    },
    {
        "name": "browser_type",
        "description": "Digita texto no browser.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "selector": {"type": "string"},
                "text": {"type": "string"},
            },
            "required": ["selector", "text"],
        },
    },
    {
        "name": "browser_press",
        "description": "Pressiona tecla em seletor.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "selector": {"type": "string"},
                "key": {"type": "string"},
            },
            "required": ["selector", "key"],
        },
    },
    {
        "name": "browser_eval",
        "description": "Executa JavaScript no browser.",
        "inputSchema": {
            "type": "object",
            "properties": {"script": {"type": "string"}},
            "required": ["script"],
        },
    },
    {
        "name": "browser_text",
        "description": "Lê texto de um seletor.",
        "inputSchema": {
            "type": "object",
            "properties": {"selector": {"type": "string"}},
            "required": ["selector"],
        },
    },
    {
        "name": "browser_screenshot",
        "description": "Salva screenshot do browser.",
        "inputSchema": {
            "type": "object",
            "properties": {"name": {"type": "string"}},
        },
    },
    {
        "name": "browser_status",
        "description": "Mostra status do browser controlado.",
        "inputSchema": {"type": "object", "properties": {}},
    },
    {
        "name": "browser_back",
        "description": "Volta uma página no browser.",
        "inputSchema": {"type": "object", "properties": {}},
    },
    {
        "name": "browser_forward",
        "description": "Avança uma página no browser.",
        "inputSchema": {"type": "object", "properties": {}},
    },
    {
        "name": "browser_reload",
        "description": "Recarrega a página do browser.",
        "inputSchema": {"type": "object", "properties": {}},
    },
]


def rpc_result(request_id: Any, result: dict[str, Any]) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": request_id, "result": result}


def rpc_error(request_id: Any, code: int, message: str) -> dict[str, Any]:
    return {"jsonrpc": "2.0", "id": request_id, "error": {"code": code, "message": message}}


def bridge_status() -> dict[str, Any]:
    return call_gateway("GET", "/status")


def call_tool(name: str, arguments: dict[str, Any]) -> dict[str, Any]:
    if name == "gateway_status":
        return bridge_status()
    if name == "gateway_health":
        return call_gateway("GET", "/health")
    if name in {
        "execute_command",
        "execute_git_command",
        "read_file",
        "write_file",
        "get_logs",
        "browser_open",
        "browser_click",
        "browser_fill",
        "browser_type",
        "browser_press",
        "browser_eval",
        "browser_text",
        "browser_screenshot",
        "browser_status",
        "browser_back",
        "browser_forward",
        "browser_reload",
    }:
        return call_gateway("POST", f"/mcp/tool/{name}", {"params": arguments})
    return {"ok": False, "error": f"unknown tool: {name}"}


def handle_request(payload: dict[str, Any]) -> dict[str, Any] | None:
    method = payload.get("method")
    request_id = payload.get("id")
    params = payload.get("params") or {}

    if method == "initialize":
        return rpc_result(
            request_id,
            {
                "protocolVersion": "2024-11-05",
                "serverInfo": {
                    "name": "shopvivaliz-remote-ai-stdio",
                    "version": "1.0.0",
                },
                "capabilities": {"tools": {}},
                "instructions": (
                    "Use this server to execute commands and control the browser on the ShopVivaliz PC."
                ),
            },
        )

    if method == "notifications/initialized":
        return None

    if method == "tools/list":
        return rpc_result(request_id, {"tools": TOOLS})

    if method == "tools/call":
        name = str(params.get("name") or "").strip()
        arguments = params.get("arguments") or params.get("input") or {}
        if not isinstance(arguments, dict):
            arguments = {}
        result = call_tool(name, arguments)
        if "error" in result and not result.get("ok", True):
            return rpc_error(request_id, -32000, str(result["error"]))
        return rpc_result(
            request_id,
            {
                "content": [
                    {
                        "type": "text",
                        "text": json.dumps(result, ensure_ascii=False, indent=2),
                    }
                ],
                "structuredContent": result,
            },
        )

    if method == "ping":
        return rpc_result(request_id, {})

    return rpc_error(request_id, -32601, f"Unknown method: {method}")


def main() -> int:
    sys.stdin.reconfigure(encoding="utf-8")
    sys.stdout.reconfigure(encoding="utf-8")

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
