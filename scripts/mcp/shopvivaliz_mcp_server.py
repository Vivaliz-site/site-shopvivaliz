#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import sys
from typing import Any

ROOT = pathlib.Path(os.environ.get("SHOPVIVALIZ_ROOT", "/var/www/shopvivaliz")).resolve()
ALLOWED_SERVICES = {s.strip() for s in os.environ.get("SHOPVIVALIZ_ALLOWED_SERVICES", "php8.3-fpm,nginx,apache2").split(",") if s.strip()}


def run(cmd: list[str], cwd: pathlib.Path | None = None, timeout: int = 60) -> dict[str, Any]:
    proc = subprocess.run(cmd, cwd=str(cwd or ROOT), text=True, capture_output=True, timeout=timeout, check=False)
    return {"returncode": proc.returncode, "stdout": proc.stdout[-12000:], "stderr": proc.stderr[-12000:]}


def safe_path(value: str) -> pathlib.Path:
    path = (ROOT / value).resolve()
    if path != ROOT and ROOT not in path.parents:
        raise ValueError("path outside SHOPVIVALIZ_ROOT")
    return path


def tool_list() -> list[dict[str, Any]]:
    return [
        {"name": "system_health", "description": "Resumo de uptime, disco, memoria e HTTP", "inputSchema": {"type": "object", "properties": {}}},
        {"name": "site_smoke_test", "description": "Testa homepage, CSS, catalogo e webhook health", "inputSchema": {"type": "object", "properties": {"base_url": {"type": "string", "default": "https://shopvivaliz.com.br"}}}},
        {"name": "git_status", "description": "Status e ultimo commit", "inputSchema": {"type": "object", "properties": {}}},
        {"name": "git_pull_ff_only", "description": "Atualiza branch somente por fast-forward", "inputSchema": {"type": "object", "properties": {"branch": {"type": "string", "default": "main"}}}},
        {"name": "file_read", "description": "Le arquivo textual dentro do repositorio", "inputSchema": {"type": "object", "required": ["path"], "properties": {"path": {"type": "string"}, "max_bytes": {"type": "integer", "default": 65536}}}},
        {"name": "service_status", "description": "Consulta servico permitido", "inputSchema": {"type": "object", "required": ["service"], "properties": {"service": {"type": "string"}}}},
        {"name": "service_restart", "description": "Reinicia servico explicitamente permitido", "inputSchema": {"type": "object", "required": ["service"], "properties": {"service": {"type": "string"}}}},
    ]


def call_tool(name: str, args: dict[str, Any]) -> dict[str, Any]:
    if name == "system_health":
        return {"uptime": run(["uptime"]), "disk": run(["df", "-h", str(ROOT)]), "memory": run(["free", "-h"]), "site": run(["curl", "-fsSIL", "--max-time", "15", "https://shopvivaliz.com.br/"])}
    if name == "site_smoke_test":
        base = str(args.get("base_url") or "https://shopvivaliz.com.br").rstrip("/")
        urls = [base + "/", base + "/css/shopvivaliz-core-consolidated.css", base + "/catalogo", base + "/api/olist/webhook-receiver.php?health=1"]
        return {url: run(["curl", "-fsS", "--max-time", "20", "-o", "/dev/null", "-w", "%{http_code} %{size_download}", url]) for url in urls}
    if name == "git_status":
        return {"status": run(["git", "status", "--short"]), "head": run(["git", "log", "-1", "--oneline"])}
    if name == "git_pull_ff_only":
        branch = str(args.get("branch") or "main")
        if not branch.replace("-", "").replace("_", "").isalnum():
            raise ValueError("invalid branch")
        return {"fetch": run(["git", "fetch", "origin", branch]), "pull": run(["git", "pull", "--ff-only", "origin", branch])}
    if name == "file_read":
        path = safe_path(str(args["path"]))
        max_bytes = min(max(int(args.get("max_bytes", 65536)), 1), 262144)
        data = path.read_bytes()[:max_bytes]
        return {"path": str(path.relative_to(ROOT)), "content": data.decode("utf-8", errors="replace")}
    if name in {"service_status", "service_restart"}:
        service = str(args["service"])
        if service not in ALLOWED_SERVICES:
            raise ValueError("service not allowed")
        action = "status" if name == "service_status" else "restart"
        return run(["sudo", "systemctl", action, service])
    raise ValueError("unknown tool")


def emit(payload: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def main() -> None:
    for line in sys.stdin:
        try:
            req = json.loads(line)
            req_id = req.get("id")
            method = req.get("method")
            if method == "initialize":
                result = {"protocolVersion": "2024-11-05", "capabilities": {"tools": {}}, "serverInfo": {"name": "shopvivaliz-mcp", "version": "1.0.0"}}
            elif method == "tools/list":
                result = {"tools": tool_list()}
            elif method == "tools/call":
                params = req.get("params") or {}
                value = call_tool(str(params.get("name")), params.get("arguments") or {})
                result = {"content": [{"type": "text", "text": json.dumps(value, ensure_ascii=False)}], "isError": False}
            else:
                emit({"jsonrpc": "2.0", "id": req_id, "error": {"code": -32601, "message": "method not found"}})
                continue
            emit({"jsonrpc": "2.0", "id": req_id, "result": result})
        except Exception as exc:
            emit({"jsonrpc": "2.0", "id": None, "error": {"code": -32000, "message": str(exc)}})


if __name__ == "__main__":
    main()
