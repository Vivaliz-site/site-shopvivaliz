#!/usr/bin/env python3
from __future__ import annotations

import asyncio
import argparse
import ipaddress
import json
import logging
import os
import secrets
import shlex
import socket
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    from aiohttp import web
except ImportError:
    print("❌ Dependência ausente: aiohttp")
    raise SystemExit(1)

try:
    from playwright.async_api import async_playwright
except ImportError:
    async_playwright = None


REPO_ROOT = Path(__file__).resolve().parent.parent
DATA_DIR = REPO_ROOT / "storage" / "remote-access"
REPORTS_DIR = REPO_ROOT / "reports" / "remote-access"
LOGS_DIR = REPO_ROOT / "logs"
DATA_DIR.mkdir(parents=True, exist_ok=True)
REPORTS_DIR.mkdir(parents=True, exist_ok=True)
LOGS_DIR.mkdir(parents=True, exist_ok=True)

DEFAULT_HOST = os.getenv("SHOPVIVALIZ_REMOTE_HOST", "0.0.0.0")
DEFAULT_PORT = int(os.getenv("SHOPVIVALIZ_REMOTE_PORT", "5555"))
API_KEY_ENV = "SHOPVIVALIZ_REMOTE_API_KEY"
API_KEY_FILE = DATA_DIR / "api-key.txt"
CONNECTION_FILE = DATA_DIR / "connection.json"
BROWSER_PROFILE_DIR = DATA_DIR / "browser-profile"
BROWSER_SCREENSHOT_DIR = REPORTS_DIR / "browser"
BROWSER_SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.FileHandler(LOGS_DIR / "ai-remote-gateway.log", encoding="utf-8"),
        logging.StreamHandler(),
    ],
)
logger = logging.getLogger("ai-remote-gateway")

_browser_lock = asyncio.Lock()


def _ensure_api_key() -> str:
    key = os.getenv(API_KEY_ENV, "").strip()
    if key:
        API_KEY_FILE.write_text(key + "\n", encoding="utf-8")
        return key
    if API_KEY_FILE.exists():
        existing = API_KEY_FILE.read_text(encoding="utf-8").strip()
        if existing:
            return existing
    key = secrets.token_urlsafe(32)
    API_KEY_FILE.write_text(key + "\n", encoding="utf-8")
    return key


API_KEY = _ensure_api_key()


def _write_connection_card(host: str, port: int) -> None:
    payload = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "host": host,
        "port": port,
        "health_url": f"http://{host}:{port}/health" if host not in {"0.0.0.0", "::"} else f"http://127.0.0.1:{port}/health",
        "status_url": f"http://{host}:{port}/status" if host not in {"0.0.0.0", "::"} else f"http://127.0.0.1:{port}/status",
        "api_key_file": str(API_KEY_FILE),
        "browser_profile": str(BROWSER_PROFILE_DIR),
        "auth_header": "X-API-Key",
    }
    CONNECTION_FILE.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


WRITE_PROTECTED_PREFIXES = (
    ".git",
    ".github/workflows",
    ".claude/settings.json",
    "storage/remote-access",
)


def _safe_repo_path(path: str) -> Path:
    candidate = (REPO_ROOT / path).resolve()
    try:
        candidate.relative_to(REPO_ROOT.resolve())
    except ValueError as exc:
        raise ValueError("path outside repository root") from exc
    return candidate


def _is_write_protected(path: str) -> bool:
    normalized = path.strip().replace("\\", "/").lstrip("/")
    return any(
        normalized == prefix or normalized.startswith(prefix + "/")
        for prefix in WRITE_PROTECTED_PREFIXES
    )


def _is_private_or_loopback(remote: str | None) -> bool:
    if not remote:
        return False
    try:
        addr = ipaddress.ip_address(remote.split("%", 1)[0])
    except ValueError:
        return False
    return (
        addr.is_loopback
        or addr.is_private
        or addr in ipaddress.ip_network("100.64.0.0/10")
    )


def _authorized(request: web.Request) -> bool:
    header_key = request.headers.get("X-API-Key", "").strip()
    auth_header = request.headers.get("Authorization", "").strip()
    bearer = ""
    if auth_header.lower().startswith("bearer "):
        bearer = auth_header[7:].strip()
    token = header_key or bearer
    return bool(token and secrets.compare_digest(token, API_KEY))


def _auth_error(message: str, status: int = 401) -> web.Response:
    return web.json_response({"ok": False, "error": message}, status=status)


def _json_response(payload: dict[str, Any], status: int = 200) -> web.Response:
    return web.json_response(payload, status=status)


COMMAND_AUDIT_LOG = LOGS_DIR / "ai-remote-gateway-commands.log"


def _audit_log_command(command: str, remote: str | None, result: dict[str, Any]) -> None:
    entry = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "remote": remote,
        "command": command,
        "returncode": result.get("returncode"),
        "ok": result.get("ok"),
    }
    with COMMAND_AUDIT_LOG.open("a", encoding="utf-8") as f:
        f.write(json.dumps(entry, ensure_ascii=False) + "\n")


def _run_command_sync(command: str, timeout: int) -> dict[str, Any]:
    result = subprocess.run(
        command,
        shell=True,
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    return {
        "ok": result.returncode == 0,
        "returncode": result.returncode,
        "stdout": result.stdout,
        "stderr": result.stderr,
    }


async def run_command(command: str, timeout: int = 30, remote: str | None = None) -> dict[str, Any]:
    result = await asyncio.to_thread(_run_command_sync, command, timeout)
    logger.info("exec command=%r remote=%s returncode=%s", command, remote, result.get("returncode"))
    _audit_log_command(command, remote, result)
    return result


def _read_log_tail(kind: str, lines: int) -> dict[str, Any]:
    today = datetime.now().strftime("%Y-%m-%d")
    if kind == "mcp":
        log_file = LOGS_DIR / "ai-remote-gateway.log"
    elif kind == "sync":
        log_file = LOGS_DIR / f"local-sync-{today}.log"
    elif kind == "agentes":
        log_file = LOGS_DIR / f"agentes-leitor-{today}.log"
    else:
        raise ValueError(f"log type desconhecido: {kind}")
    if not log_file.exists():
        return {"ok": False, "error": f"log not found: {log_file}"}
    content = log_file.read_text(encoding="utf-8", errors="replace").splitlines()
    return {
        "ok": True,
        "log_file": str(log_file),
        "lines": "\n".join(content[-max(1, lines) :]),
    }


def _read_file(path: str) -> dict[str, Any]:
    file_path = _safe_repo_path(path)
    if not file_path.exists():
        return {"ok": False, "error": "file not found"}
    return {"ok": True, "content": file_path.read_text(encoding="utf-8", errors="replace")}


def _write_file(path: str, content: str) -> dict[str, Any]:
    if _is_write_protected(path):
        return {"ok": False, "error": f"write blocked for protected path: {path}"}
    file_path = _safe_repo_path(path)
    file_path.parent.mkdir(parents=True, exist_ok=True)
    file_path.write_text(content, encoding="utf-8")
    return {"ok": True, "path": str(file_path)}


def _tailscale_status() -> dict[str, Any]:
    exe = Path(r"C:\Program Files\Tailscale\tailscale.exe")
    if not exe.exists():
        return {"installed": False}
    try:
        result = subprocess.run(
            [str(exe), "status", "--json"],
            capture_output=True,
            text=True,
            timeout=10,
        )
        if result.returncode != 0:
            return {
                "installed": True,
                "error": result.stderr.strip() or result.stdout.strip(),
            }
        data = json.loads(result.stdout)
        return {
            "installed": True,
            "backend_state": data.get("BackendState"),
            "online": bool(data.get("Self", {}).get("Online")),
            "tailscale_ips": data.get("Self", {}).get("TailscaleIPs") or data.get("TailscaleIPs"),
            "auth_url": data.get("AuthURL") or "",
        }
    except Exception as exc:
        return {"installed": True, "error": str(exc)}


class BrowserController:
    def __init__(self) -> None:
        self._playwright = None
        self._context = None
        self._page = None
        self._channel = os.getenv("SHOPVIVALIZ_BROWSER_CHANNEL", "chrome").strip() or None
        self._headless = os.getenv("SHOPVIVALIZ_BROWSER_HEADLESS", "0").strip() == "1"

    async def _start(self) -> None:
        if async_playwright is None:
            raise RuntimeError("playwright não instalado")
        if self._page is not None and self._context is not None:
            return
        if self._playwright is None:
            self._playwright = await async_playwright().start()
        launch_errors: list[str] = []
        launch_kwargs = {
            "user_data_dir": str(BROWSER_PROFILE_DIR),
            "headless": self._headless,
            "viewport": {"width": 1440, "height": 900},
        }
        if self._channel:
            try:
                self._context = await self._playwright.chromium.launch_persistent_context(
                    channel=self._channel,
                    **launch_kwargs,
                )
            except Exception as exc:
                launch_errors.append(f"channel={self._channel}: {exc}")
        if self._context is None:
            try:
                self._context = await self._playwright.chromium.launch_persistent_context(
                    **launch_kwargs,
                )
            except Exception as exc:
                launch_errors.append(f"chromium: {exc}")
                raise RuntimeError("; ".join(launch_errors)) from exc
        pages = self._context.pages
        self._page = pages[0] if pages else await self._context.new_page()

    async def ensure_ready(self) -> None:
        async with _browser_lock:
            await self._start()

    async def status(self) -> dict[str, Any]:
        try:
            await self.ensure_ready()
            return {
                "ok": True,
                "ready": True,
                "url": self._page.url if self._page else "about:blank",
                "title": await self._page.title() if self._page else "",
                "headless": self._headless,
                "channel": self._channel or "default",
            }
        except Exception as exc:
            return {"ok": False, "ready": False, "error": str(exc)}

    async def open(self, url: str) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.goto(url, wait_until="domcontentloaded")
            return {
                "ok": True,
                "url": self._page.url,
                "title": await self._page.title(),
            }

    async def click(self, selector: str) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.click(selector)
            return {"ok": True, "url": self._page.url}

    async def fill(self, selector: str, text: str) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.fill(selector, text)
            return {"ok": True, "url": self._page.url}

    async def type(self, selector: str, text: str) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.type(selector, text)
            return {"ok": True, "url": self._page.url}

    async def press(self, selector: str, key: str) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.press(selector, key)
            return {"ok": True, "url": self._page.url}

    async def evaluate(self, script: str) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            result = await self._page.evaluate(script)
            return {"ok": True, "result": result}

    async def text(self, selector: str) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            locator = self._page.locator(selector)
            return {"ok": True, "text": await locator.inner_text()}

    async def screenshot(self, name: str | None = None) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
            safe_name = (name or "browser").strip().replace(" ", "_")
            path = BROWSER_SCREENSHOT_DIR / f"{safe_name}-{stamp}.png"
            await self._page.screenshot(path=str(path), full_page=True)
            return {"ok": True, "path": str(path), "url": self._page.url}

    async def back(self) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.go_back()
            return {"ok": True, "url": self._page.url}

    async def forward(self) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.go_forward()
            return {"ok": True, "url": self._page.url}

    async def reload(self) -> dict[str, Any]:
        async with _browser_lock:
            await self._start()
            assert self._page is not None
            await self._page.reload()
            return {"ok": True, "url": self._page.url}

    async def close(self) -> dict[str, Any]:
        async with _browser_lock:
            if self._context is not None:
                await self._context.close()
            if self._playwright is not None:
                await self._playwright.stop()
            self._playwright = None
            self._context = None
            self._page = None
            return {"ok": True}


BROWSER = BrowserController()


async def get_system_status() -> dict[str, Any]:
    git_status = await run_command("git status --porcelain", timeout=15)
    return {
        "ok": True,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        "repo_root": str(REPO_ROOT),
        "host": socket.gethostname(),
        "api_key_file": str(API_KEY_FILE),
        "browser_profile": str(BROWSER_PROFILE_DIR),
        "git_clean": git_status.get("stdout", "").strip() == "",
        "git_status": git_status.get("stdout", "").strip(),
        "tailscale": _tailscale_status(),
        "browser": await BROWSER.status(),
    }


TOOLS = [
    {"name": "execute_command", "description": "Executa comando shell no PC"},
    {"name": "execute_git_command", "description": "Executa comando git no repositório"},
    {"name": "read_file", "description": "Lê arquivo dentro do repositório"},
    {"name": "write_file", "description": "Escreve arquivo dentro do repositório"},
    {"name": "get_logs", "description": "Lê o fim dos logs do sistema"},
    {"name": "browser_open", "description": "Abre URL no browser controlado"},
    {"name": "browser_click", "description": "Clica em seletor CSS"},
    {"name": "browser_fill", "description": "Preenche campo no browser"},
    {"name": "browser_type", "description": "Digita texto em campo no browser"},
    {"name": "browser_press", "description": "Pressiona tecla em seletor"},
    {"name": "browser_eval", "description": "Executa JavaScript no browser"},
    {"name": "browser_text", "description": "Lê texto de seletor"},
    {"name": "browser_screenshot", "description": "Salva screenshot do browser"},
    {"name": "browser_back", "description": "Volta uma página"},
    {"name": "browser_forward", "description": "Avança uma página"},
    {"name": "browser_reload", "description": "Recarrega a página"},
    {"name": "browser_close", "description": "Fecha o browser controlado"},
    {"name": "browser_status", "description": "Mostra status do browser"},
]


async def execute_tool(tool_name: str, params: dict[str, Any], remote: str | None = None) -> dict[str, Any]:
    command = str(params.get("command") or "").strip()
    timeout = int(params.get("timeout") or 30)
    if tool_name == "execute_command":
        if not command:
            return {"ok": False, "error": "command is required"}
        return await run_command(command, timeout, remote=remote)
    if tool_name == "execute_git_command":
        if not command:
            return {"ok": False, "error": "command is required"}
        return await run_command(f"git {command}", timeout, remote=remote)
    if tool_name == "read_file":
        return _read_file(str(params.get("path") or ""))
    if tool_name == "write_file":
        return _write_file(str(params.get("path") or ""), str(params.get("content") or ""))
    if tool_name == "get_logs":
        return _read_log_tail(str(params.get("log_type") or "mcp"), int(params.get("lines") or 50))
    if tool_name == "browser_open":
        return await BROWSER.open(str(params.get("url") or "about:blank"))
    if tool_name == "browser_click":
        return await BROWSER.click(str(params.get("selector") or ""))
    if tool_name == "browser_fill":
        return await BROWSER.fill(str(params.get("selector") or ""), str(params.get("text") or ""))
    if tool_name == "browser_type":
        return await BROWSER.type(str(params.get("selector") or ""), str(params.get("text") or ""))
    if tool_name == "browser_press":
        return await BROWSER.press(str(params.get("selector") or ""), str(params.get("key") or ""))
    if tool_name == "browser_eval":
        return await BROWSER.evaluate(str(params.get("script") or ""))
    if tool_name == "browser_text":
        return await BROWSER.text(str(params.get("selector") or ""))
    if tool_name == "browser_screenshot":
        return await BROWSER.screenshot(str(params.get("name") or "") or None)
    if tool_name == "browser_back":
        return await BROWSER.back()
    if tool_name == "browser_forward":
        return await BROWSER.forward()
    if tool_name == "browser_reload":
        return await BROWSER.reload()
    if tool_name == "browser_close":
        return await BROWSER.close()
    if tool_name == "browser_status":
        return await BROWSER.status()
    return {"ok": False, "error": f"unknown tool: {tool_name}"}


@web.middleware
async def auth_middleware(request: web.Request, handler):
    if request.path in {"/health"}:
        return await handler(request)
    if not _is_private_or_loopback(request.remote):
        return _auth_error(f"remote host not allowed: {request.remote}", status=403)
    if not _authorized(request):
        return _auth_error("unauthorized", status=401)
    return await handler(request)


def _tool_doc() -> dict[str, Any]:
    return {
        "ok": True,
        "tools": TOOLS,
        "auth_header": "X-API-Key",
        "api_key_file": str(API_KEY_FILE),
        "browser_profile": str(BROWSER_PROFILE_DIR),
        "paths": {
            "health": "/health",
            "status": "/status",
            "tools": "/mcp/tools",
            "tool_call": "/mcp/tool/{name}",
            "exec": "/exec",
            "browser": "/browser/*",
        },
    }


async def handle_health(request: web.Request) -> web.Response:
    return _json_response(
        {
            "ok": True,
            "status": "healthy",
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "version": "1.0.0",
            "host": socket.gethostname(),
        }
    )


async def handle_status(request: web.Request) -> web.Response:
    return _json_response(await get_system_status())


async def handle_tools(request: web.Request) -> web.Response:
    return _json_response(_tool_doc())


async def handle_exec(request: web.Request) -> web.Response:
    data = await request.json()
    result = await run_command(str(data.get("cmd") or ""), int(data.get("timeout") or 30))
    return _json_response(result)


async def handle_file_read(request: web.Request) -> web.Response:
    data = await request.json()
    return _json_response(_read_file(str(data.get("path") or "")))


async def handle_file_write(request: web.Request) -> web.Response:
    data = await request.json()
    return _json_response(_write_file(str(data.get("path") or ""), str(data.get("content") or "")))


async def handle_browser(request: web.Request) -> web.Response:
    action = request.match_info["action"]
    data = {}
    if request.can_read_body:
        try:
            data = await request.json()
        except Exception:
            data = {}
    result = await execute_tool(
        f"browser_{action}",
        data,
    )
    return _json_response(result)


async def handle_browser_status(request: web.Request) -> web.Response:
    return _json_response(await BROWSER.status())


async def handle_mcp_tools(request: web.Request) -> web.Response:
    return _json_response({"tools": TOOLS})


async def handle_mcp_tool(request: web.Request) -> web.Response:
    tool_name = request.match_info["name"]
    payload = await request.json()
    params = payload.get("params") or payload.get("input") or {}
    if not isinstance(params, dict):
        params = {}
    result = await execute_tool(tool_name, params)
    return _json_response({"tool": tool_name, "result": result})


async def handle_root(request: web.Request) -> web.Response:
    return _json_response(
        {
            "ok": True,
            "name": "ShopVivaliz Remote AI Gateway",
            "message": "Use /status, /mcp/tools e /browser/*",
            "auth_header": "X-API-Key",
        }
    )


def _save_boot_report(host: str, port: int) -> None:
    report = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "host": host,
        "port": port,
        "api_key_file": str(API_KEY_FILE),
        "browser_profile": str(BROWSER_PROFILE_DIR),
    }
    (REPORTS_DIR / "remote-gateway-startup.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


async def main() -> int:
    parser = argparse.ArgumentParser(description="ShopVivaliz Remote AI Gateway")
    parser.add_argument("--host", default=DEFAULT_HOST)
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    args = parser.parse_args()

    _write_connection_card(args.host, args.port)
    _save_boot_report(args.host, args.port)

    app = web.Application(middlewares=[auth_middleware])
    app.router.add_get("/", handle_root)
    app.router.add_get("/health", handle_health)
    app.router.add_get("/status", handle_status)
    app.router.add_get("/tools", handle_tools)
    app.router.add_get("/mcp/tools", handle_mcp_tools)
    app.router.add_post("/mcp/tool/{name}", handle_mcp_tool)
    app.router.add_post("/exec", handle_exec)
    app.router.add_post("/file/read", handle_file_read)
    app.router.add_post("/file/write", handle_file_write)
    app.router.add_post("/browser/{action}", handle_browser)
    app.router.add_get("/browser/status", handle_browser_status)
    app.router.add_post("/browser/status", handle_browser_status)

    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, args.host, args.port)
    await site.start()

    logger.info("ShopVivaliz Remote AI Gateway started")
    logger.info("Health: http://%s:%s/health", args.host, args.port)
    logger.info("Status: http://%s:%s/status", args.host, args.port)
    logger.info("API key saved at %s", API_KEY_FILE)
    logger.info("Auth header: X-API-Key")
    logger.info("Tailscale: %s", json.dumps(_tailscale_status(), ensure_ascii=False))

    try:
        await asyncio.Event().wait()
    except KeyboardInterrupt:
        logger.info("Gateway interrupted")
    finally:
        await BROWSER.close()
        await runner.cleanup()
    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
