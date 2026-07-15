#!/usr/bin/env python3
"""Hardened local administrative server for ShopVivaliz.

This is a restricted legacy HTTP service, not a standards-compliant MCP transport.
It binds to localhost by default and refuses non-local binding without a bearer token.
"""
from __future__ import annotations

import argparse
import asyncio
import hmac
import json
import logging
import os
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Any

try:
    from aiohttp import web
except ImportError:
    raise SystemExit("Install dependency: pip install aiohttp")

REPO_ROOT = Path(__file__).resolve().parent.parent
LOGS_DIR = REPO_ROOT / "logs"
LOGS_DIR.mkdir(exist_ok=True)
ENVIRONMENT = os.getenv("AGENT_ENVIRONMENT", "unknown")
MCP_PORT = int(os.getenv("MCP_PORT", "5555"))
MCP_HOST = os.getenv("MCP_HOST", "127.0.0.1")
MCP_AUTH_TOKEN = os.getenv("MCP_AUTH_TOKEN", "")
MAX_BODY_BYTES = 256 * 1024
MAX_FILE_BYTES = 1024 * 1024
ALLOWED_WRITE_PATHS = {"tasks-queue.json"}
ALLOWED_GIT_COMMANDS = {
    "status": ["status", "--short"],
    "log": ["log", "--oneline", "-10"],
    "diff": ["diff", "--stat"],
    "branch": ["branch", "--show-current"],
    "head": ["rev-parse", "HEAD"],
}

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[logging.FileHandler(LOGS_DIR / f"mcp-server-{ENVIRONMENT}.log"), logging.StreamHandler()],
)
logger = logging.getLogger(__name__)


def is_loopback(host: str) -> bool:
    return host in {"127.0.0.1", "localhost", "::1"}


def safe_repo_path(relative: str, *, must_exist: bool = False) -> Path:
    if not relative or Path(relative).is_absolute():
        raise ValueError("A relative repository path is required")
    candidate = (REPO_ROOT / relative).resolve()
    try:
        candidate.relative_to(REPO_ROOT)
    except ValueError as exc:
        raise ValueError("Path escapes repository root") from exc
    if must_exist and not candidate.exists():
        raise FileNotFoundError(relative)
    return candidate


@web.middleware
async def security_middleware(request: web.Request, handler):
    if request.content_length and request.content_length > MAX_BODY_BYTES:
        raise web.HTTPRequestEntityTooLarge(max_size=MAX_BODY_BYTES, actual_size=request.content_length)
    if MCP_AUTH_TOKEN:
        expected = f"Bearer {MCP_AUTH_TOKEN}"
        supplied = request.headers.get("Authorization", "")
        if not hmac.compare_digest(supplied, expected):
            raise web.HTTPUnauthorized(text="Unauthorized")
    elif not is_loopback(MCP_HOST):
        raise web.HTTPServiceUnavailable(text="MCP_AUTH_TOKEN is required for non-local binding")
    return await handler(request)


def run_git(operation: str, timeout: int = 15) -> dict[str, Any]:
    args = ALLOWED_GIT_COMMANDS.get(operation)
    if args is None:
        return {"success": False, "error": "Git operation not allowed"}
    timeout = max(1, min(int(timeout), 30))
    try:
        result = subprocess.run(
            ["git", *args], cwd=REPO_ROOT, capture_output=True, text=True,
            timeout=timeout, check=False,
        )
        return {"success": result.returncode == 0, "output": result.stdout, "error": result.stderr}
    except subprocess.TimeoutExpired:
        return {"success": False, "error": "Git operation timed out"}


async def health(_: web.Request) -> web.Response:
    return web.json_response({
        "status": "ok", "environment": ENVIRONMENT, "version": "2.1.0-hardened",
        "timestamp": datetime.now().isoformat(), "network_scope": "local" if is_loopback(MCP_HOST) else "authenticated",
    })


async def list_resources(_: web.Request) -> web.Response:
    return web.json_response({"resources": {
        "status://system": {"type": "read"},
        "logs://sync": {"type": "read"},
        "logs://agentes": {"type": "read"},
        "config://env-status": {"type": "read"},
        "files://tasks": {"type": "read-write"},
        "repo://git-status": {"type": "read"},
    }})


async def read_resource(request: web.Request) -> web.Response:
    name = request.match_info["name"]
    today = datetime.now().strftime("%Y-%m-%d")
    if name == "status://system":
        content: Any = {"environment": ENVIRONMENT, "git": run_git("status"), "port": MCP_PORT}
    elif name == "logs://sync":
        path = LOGS_DIR / f"local-sync-{today}.log"
        content = "".join(path.read_text(encoding="utf-8", errors="replace").splitlines(True)[-50:]) if path.exists() else ""
    elif name == "logs://agentes":
        path = LOGS_DIR / f"agentes-leitor-{today}.log"
        content = "".join(path.read_text(encoding="utf-8", errors="replace").splitlines(True)[-50:]) if path.exists() else ""
    elif name == "config://env-status":
        env_file = REPO_ROOT / ".env.agentes.local"
        keys: list[str] = []
        if env_file.exists():
            for raw in env_file.read_text(encoding="utf-8", errors="replace").splitlines():
                raw = raw.strip()
                if raw and not raw.startswith("#") and "=" in raw:
                    keys.append(raw.split("=", 1)[0].strip())
        content = {"configured_keys": sorted(set(keys)), "values_exposed": False}
    elif name == "files://tasks":
        path = REPO_ROOT / "tasks-queue.json"
        content = path.read_text(encoding="utf-8") if path.exists() else json.dumps({"tasks": []})
    elif name == "repo://git-status":
        content = {"status": run_git("status"), "log": run_git("log")}
    else:
        raise web.HTTPNotFound(text="Unknown resource")
    return web.json_response({"resource": name, "content": content})


async def write_resource(request: web.Request) -> web.Response:
    if request.match_info["name"] != "files://tasks":
        raise web.HTTPForbidden(text="Resource is read-only")
    data = await request.json()
    content = data.get("content", "")
    if not isinstance(content, str) or len(content.encode("utf-8")) > MAX_FILE_BYTES:
        raise web.HTTPBadRequest(text="Invalid or oversized content")
    try:
        json.loads(content)
    except json.JSONDecodeError as exc:
        raise web.HTTPBadRequest(text="tasks-queue.json must contain valid JSON") from exc
    path = safe_repo_path("tasks-queue.json")
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(content, encoding="utf-8")
    os.replace(tmp, path)
    return web.json_response({"success": True})


async def list_tools(_: web.Request) -> web.Response:
    return web.json_response({"tools": [
        {"name": "git_read", "params": {"operation": sorted(ALLOWED_GIT_COMMANDS)}},
        {"name": "read_file", "params": {"path": "relative repository path"}},
        {"name": "write_tasks_queue", "params": {"content": "valid JSON"}},
        {"name": "get_logs", "params": {"log_type": "sync|agentes|mcp", "lines": "1..200"}},
    ]})


async def execute_tool(request: web.Request) -> web.Response:
    name = request.match_info["name"]
    data = await request.json()
    params = data.get("params", {})
    if not isinstance(params, dict):
        raise web.HTTPBadRequest(text="params must be an object")
    if name == "git_read":
        result = run_git(str(params.get("operation", "status")), params.get("timeout", 15))
    elif name == "read_file":
        try:
            path = safe_repo_path(str(params.get("path", "")), must_exist=True)
            if path.is_dir() or path.stat().st_size > MAX_FILE_BYTES:
                raise ValueError("File unavailable or too large")
            result = {"success": True, "content": path.read_text(encoding="utf-8", errors="replace")}
        except Exception as exc:
            result = {"success": False, "error": str(exc)}
    elif name == "write_tasks_queue":
        content = params.get("content", "")
        if not isinstance(content, str):
            result = {"success": False, "error": "content must be a string"}
        else:
            try:
                json.loads(content)
                path = safe_repo_path("tasks-queue.json")
                tmp = path.with_suffix(".json.tmp")
                tmp.write_text(content, encoding="utf-8")
                os.replace(tmp, path)
                result = {"success": True}
            except Exception as exc:
                result = {"success": False, "error": str(exc)}
    elif name == "get_logs":
        kind = str(params.get("log_type", "sync"))
        lines = max(1, min(int(params.get("lines", 50)), 200))
        today = datetime.now().strftime("%Y-%m-%d")
        names = {"sync": f"local-sync-{today}.log", "agentes": f"agentes-leitor-{today}.log", "mcp": f"mcp-server-{ENVIRONMENT}.log"}
        if kind not in names:
            result = {"success": False, "error": "Unknown log type"}
        else:
            path = LOGS_DIR / names[kind]
            result = {"success": path.exists(), "lines": "".join(path.read_text(encoding="utf-8", errors="replace").splitlines(True)[-lines:]) if path.exists() else ""}
    else:
        raise web.HTTPNotFound(text="Unknown tool")
    return web.json_response({"tool": name, "result": result})


async def main() -> None:
    if not is_loopback(MCP_HOST) and not MCP_AUTH_TOKEN:
        raise SystemExit("Refusing non-local binding without MCP_AUTH_TOKEN")
    app = web.Application(middlewares=[security_middleware], client_max_size=MAX_BODY_BYTES)
    app.router.add_get("/health", health)
    app.router.add_get("/mcp/resources", list_resources)
    app.router.add_get("/mcp/resource/{name}", read_resource)
    app.router.add_post("/mcp/resource/{name}", write_resource)
    app.router.add_get("/mcp/tools", list_tools)
    app.router.add_post("/mcp/tool/{name}", execute_tool)
    runner = web.AppRunner(app)
    await runner.setup()
    await web.TCPSite(runner, MCP_HOST, MCP_PORT).start()
    logger.info("Hardened legacy server listening on %s:%s", MCP_HOST, MCP_PORT)
    try:
        await asyncio.Event().wait()
    finally:
        await runner.cleanup()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=MCP_PORT)
    parser.add_argument("--host", default=MCP_HOST)
    parser.add_argument("--env", default=ENVIRONMENT)
    args = parser.parse_args()
    MCP_PORT, MCP_HOST, ENVIRONMENT = args.port, args.host, args.env
    asyncio.run(main())
