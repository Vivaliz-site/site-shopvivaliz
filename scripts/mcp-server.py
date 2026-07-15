#!/usr/bin/env python3
"""
MCP Server - ShopVivaliz Inter-Estações

Fornece recursos e tools para Claude se comunicar e executar comandos
em múltiplas estações via Model Context Protocol.

Uso:
  python scripts/mcp-server.py --port 5555 --env windows-local
  python scripts/mcp-server.py --port 5556 --env ubuntu-vm
"""

import os
import sys
import json
import subprocess
import asyncio
import argparse
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List

try:
    from aiohttp import web
    import logging
except ImportError:
    print("❌ Dependências não instaladas. Execute:")
    print("   pip install aiohttp")
    sys.exit(1)

# ============================================================================
# CONFIGURAÇÃO
# ============================================================================

REPO_ROOT = Path(__file__).parent.parent
LOGS_DIR = REPO_ROOT / "logs"
LOGS_DIR.mkdir(exist_ok=True)

ENVIRONMENT = os.getenv("AGENT_ENVIRONMENT", "unknown")
MCP_PORT = int(os.getenv("MCP_PORT", "5555"))
MCP_HOST = os.getenv("MCP_HOST", "0.0.0.0")

LOG_FILE = LOGS_DIR / f"mcp-server-{ENVIRONMENT}.log"

# Configurar logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(),
    ],
)
logger = logging.getLogger(__name__)


# ============================================================================
# MCP RESOURCES (O que Claude pode acessar)
# ============================================================================

class MCPResources:
    """Recursos que Claude pode ler/escrever via MCP."""

    @staticmethod
    def get_resources() -> Dict[str, Any]:
        """Listar todos os recursos disponíveis."""
        return {
            "status://system": {
                "description": "Status atual do sistema/estação",
                "type": "read",
            },
            "logs://sync": {
                "description": "Logs de sincronização local",
                "type": "read",
            },
            "logs://agentes": {
                "description": "Logs de execução de agentes",
                "type": "read",
            },
            "config://env": {
                "description": "Variáveis de ambiente da estação",
                "type": "read",
            },
            "files://tasks": {
                "description": "Fila de tarefas (tasks-queue.json)",
                "type": "read-write",
            },
            "repo://git-status": {
                "description": "Status do repositório git",
                "type": "read",
            },
            "sync://stats": {
                "description": "Estatísticas de sincronização",
                "type": "read",
            },
        }

    @staticmethod
    async def read_resource(resource: str) -> str:
        """Ler conteúdo de um recurso."""
        logger.info(f"Lendo recurso: {resource}")

        if resource == "status://system":
            return await MCPResources._get_system_status()
        elif resource == "logs://sync":
            return await MCPResources._read_sync_logs()
        elif resource == "logs://agentes":
            return await MCPResources._read_agentes_logs()
        elif resource == "config://env":
            return await MCPResources._read_env_config()
        elif resource == "files://tasks":
            return await MCPResources._read_tasks_queue()
        elif resource == "repo://git-status":
            return await MCPResources._get_git_status()
        elif resource == "sync://stats":
            return await MCPResources._get_sync_stats()
        else:
            return json.dumps({"error": f"Recurso desconhecido: {resource}"})

    @staticmethod
    async def write_resource(resource: str, content: str) -> Dict[str, Any]:
        """Escrever conteúdo em um recurso."""
        logger.info(f"Escrevendo recurso: {resource}")

        if resource == "files://tasks":
            try:
                tasks_file = REPO_ROOT / "tasks-queue.json"
                with open(tasks_file, "w", encoding="utf-8") as f:
                    f.write(content)
                return {"success": True, "message": "Tasks queue atualizada"}
            except Exception as e:
                return {"success": False, "error": str(e)}
        else:
            return {"success": False, "error": f"Recurso somente-leitura: {resource}"}

    # --- Implementações dos recursos ---

    @staticmethod
    async def _get_system_status() -> str:
        """Status do sistema (uptime, processos, etc)."""
        try:
            result = subprocess.run(
                ["git", "status", "--short"],
                cwd=REPO_ROOT,
                capture_output=True,
                text=True,
            )
            git_status = result.stdout or "clean"

            return json.dumps(
                {
                    "environment": ENVIRONMENT,
                    "timestamp": datetime.now().isoformat(),
                    "git_status": git_status,
                    "repo_path": str(REPO_ROOT),
                    "mcp_server": f"online (port {MCP_PORT})",
                }
            )
        except Exception as e:
            return json.dumps({"error": str(e)})

    @staticmethod
    async def _read_sync_logs() -> str:
        """Últimas linhas de logs de sincronização."""
        sync_log = LOGS_DIR / f"local-sync-{datetime.now().strftime('%Y-%m-%d')}.log"
        if sync_log.exists():
            with open(sync_log, "r", encoding="utf-8") as f:
                lines = f.readlines()
                return "".join(lines[-50:])  # Últimas 50 linhas
        return "Nenhum log de sync encontrado"

    @staticmethod
    async def _read_agentes_logs() -> str:
        """Últimas linhas de logs de agentes."""
        agentes_log = LOGS_DIR / f"agentes-leitor-{datetime.now().strftime('%Y-%m-%d')}.log"
        if agentes_log.exists():
            with open(agentes_log, "r", encoding="utf-8") as f:
                lines = f.readlines()
                return "".join(lines[-50:])  # Últimas 50 linhas
        return "Nenhum log de agentes encontrado"

    @staticmethod
    async def _read_env_config() -> str:
        """Variáveis de ambiente configuradas."""
        env_file = REPO_ROOT / ".env.agentes.local"
        if env_file.exists():
            with open(env_file, "r", encoding="utf-8") as f:
                return f.read()
        return "Arquivo .env.agentes.local não encontrado"

    @staticmethod
    async def _read_tasks_queue() -> str:
        """Fila de tarefas (tasks-queue.json)."""
        tasks_file = REPO_ROOT / "tasks-queue.json"
        if tasks_file.exists():
            with open(tasks_file, "r", encoding="utf-8") as f:
                return f.read()
        return json.dumps({"tasks": []})

    @staticmethod
    async def _get_git_status() -> str:
        """Status do repositório git."""
        try:
            result = subprocess.run(
                ["git", "log", "--oneline", "-5"],
                cwd=REPO_ROOT,
                capture_output=True,
                text=True,
            )
            commits = result.stdout or "Nenhum commit encontrado"

            result = subprocess.run(
                ["git", "status", "--porcelain"],
                cwd=REPO_ROOT,
                capture_output=True,
                text=True,
            )
            changes = result.stdout or "Working tree clean"

            return json.dumps(
                {
                    "recent_commits": commits.strip().split("\n"),
                    "changes": changes.strip(),
                }
            )
        except Exception as e:
            return json.dumps({"error": str(e)})

    @staticmethod
    async def _get_sync_stats() -> str:
        """Estatísticas de sincronização."""
        sync_log = LOGS_DIR / f"local-sync-{datetime.now().strftime('%Y-%m-%d')}.log"
        if sync_log.exists():
            with open(sync_log, "r", encoding="utf-8") as f:
                content = f.read()
                push_count = content.count("Push de")
                pull_count = content.count("Puxando")
                success_count = content.count("OK - SYNC CONCLUIDO")

            return json.dumps(
                {
                    "syncs_today": success_count,
                    "pushes": push_count,
                    "pulls": pull_count,
                    "last_log": sync_log.stat().st_mtime,
                }
            )
        return json.dumps({"syncs_today": 0})


# ============================================================================
# MCP TOOLS (O que Claude pode executar)
# ============================================================================

class MCPTools:
    """Tools que Claude pode chamar para executar ações."""

    @staticmethod
    def get_tools() -> List[Dict[str, Any]]:
        """Listar todas as tools disponíveis."""
        return [
            {
                "name": "execute_git_command",
                "description": "Executar comando git (pull, push, status, etc)",
                "params": {
                    "command": "git command (ex: 'pull origin main')",
                    "timeout": "Timeout em segundos (default: 30)",
                },
            },
            {
                "name": "read_file",
                "description": "Ler arquivo do repositório",
                "params": {"path": "Caminho relativo (ex: 'tasks-queue.json')"},
            },
            {
                "name": "write_file",
                "description": "Escrever arquivo no repositório",
                "params": {
                    "path": "Caminho relativo",
                    "content": "Conteúdo do arquivo",
                },
            },
            {
                "name": "execute_command",
                "description": "Executar comando shell/PowerShell",
                "params": {
                    "command": "Comando a executar",
                    "timeout": "Timeout em segundos (default: 30)",
                },
            },
            {
                "name": "get_logs",
                "description": "Obter últimas N linhas de logs",
                "params": {
                    "log_type": "sync|agentes|mcp",
                    "lines": "Número de linhas (default: 50)",
                },
            },
        ]

    @staticmethod
    async def execute_tool(tool_name: str, params: Dict[str, Any]) -> Dict[str, Any]:
        """Executar uma tool."""
        logger.info(f"Executando tool: {tool_name} com params: {params}")

        try:
            if tool_name == "execute_git_command":
                return await MCPTools._execute_git_command(params)
            elif tool_name == "read_file":
                return await MCPTools._read_file(params)
            elif tool_name == "write_file":
                return await MCPTools._write_file(params)
            elif tool_name == "execute_command":
                return await MCPTools._execute_command(params)
            elif tool_name == "get_logs":
                return await MCPTools._get_logs(params)
            else:
                return {"error": f"Tool desconhecida: {tool_name}"}
        except Exception as e:
            return {"error": str(e)}

    @staticmethod
    async def _execute_git_command(params: Dict[str, Any]) -> Dict[str, Any]:
        """Executar comando git."""
        command = params.get("command", "status")
        timeout = params.get("timeout", 30)

        try:
            result = subprocess.run(
                ["git"] + command.split(),
                cwd=REPO_ROOT,
                capture_output=True,
                text=True,
                timeout=timeout,
            )
            return {
                "success": result.returncode == 0,
                "output": result.stdout,
                "error": result.stderr,
            }
        except subprocess.TimeoutExpired:
            return {"error": f"Comando timeout após {timeout}s"}
        except Exception as e:
            return {"error": str(e)}

    @staticmethod
    async def _read_file(params: Dict[str, Any]) -> Dict[str, Any]:
        """Ler arquivo."""
        path = params.get("path", "")
        file_path = REPO_ROOT / path

        try:
            with open(file_path, "r", encoding="utf-8") as f:
                return {"success": True, "content": f.read()}
        except Exception as e:
            return {"error": str(e)}

    @staticmethod
    async def _write_file(params: Dict[str, Any]) -> Dict[str, Any]:
        """Escrever arquivo."""
        path = params.get("path", "")
        content = params.get("content", "")
        file_path = REPO_ROOT / path

        try:
            file_path.parent.mkdir(parents=True, exist_ok=True)
            with open(file_path, "w", encoding="utf-8") as f:
                f.write(content)
            return {"success": True, "message": f"Arquivo escrito: {path}"}
        except Exception as e:
            return {"error": str(e)}

    @staticmethod
    async def _execute_command(params: Dict[str, Any]) -> Dict[str, Any]:
        """Executar comando shell."""
        command = params.get("command", "")
        timeout = params.get("timeout", 30)

        try:
            result = subprocess.run(
                command,
                shell=True,
                capture_output=True,
                text=True,
                timeout=timeout,
                cwd=REPO_ROOT,
            )
            return {
                "success": result.returncode == 0,
                "output": result.stdout,
                "error": result.stderr,
            }
        except subprocess.TimeoutExpired:
            return {"error": f"Comando timeout após {timeout}s"}
        except Exception as e:
            return {"error": str(e)}

    @staticmethod
    async def _get_logs(params: Dict[str, Any]) -> Dict[str, Any]:
        """Obter logs."""
        log_type = params.get("log_type", "sync")
        lines = params.get("lines", 50)

        today = datetime.now().strftime("%Y-%m-%d")

        if log_type == "sync":
            log_file = LOGS_DIR / f"local-sync-{today}.log"
        elif log_type == "agentes":
            log_file = LOGS_DIR / f"agentes-leitor-{today}.log"
        elif log_type == "mcp":
            log_file = LOGS_DIR / f"mcp-server-{today}.log"
        else:
            return {"error": f"Log type desconhecido: {log_type}"}

        try:
            if log_file.exists():
                with open(log_file, "r", encoding="utf-8") as f:
                    all_lines = f.readlines()
                    return {
                        "success": True,
                        "log_file": str(log_file),
                        "lines": "".join(all_lines[-lines:]),
                    }
            else:
                return {"success": False, "error": f"Log não encontrado: {log_file}"}
        except Exception as e:
            return {"error": str(e)}


# ============================================================================
# HTTP HANDLERS
# ============================================================================

async def handle_get_resources(request):
    """GET /mcp/resources - Listar recursos disponíveis."""
    resources = MCPResources.get_resources()
    return web.json_response({"resources": resources})


async def handle_read_resource(request):
    """GET /mcp/resource/{name} - Ler recurso."""
    resource = request.match_info.get("name", "")
    if not resource:
        return web.json_response({"error": "Resource name required"}, status=400)

    content = await MCPResources.read_resource(resource)
    return web.json_response({"resource": resource, "content": content})


async def handle_write_resource(request):
    """POST /mcp/resource/{name} - Escrever recurso."""
    resource = request.match_info.get("name", "")
    data = await request.json()
    content = data.get("content", "")

    result = await MCPResources.write_resource(resource, content)
    return web.json_response(result)


async def handle_get_tools(request):
    """GET /mcp/tools - Listar tools disponíveis."""
    tools = MCPTools.get_tools()
    return web.json_response({"tools": tools})


async def handle_execute_tool(request):
    """POST /mcp/tool/{name} - Executar tool."""
    tool_name = request.match_info.get("name", "")
    data = await request.json()
    params = data.get("params", {})

    result = await MCPTools.execute_tool(tool_name, params)
    return web.json_response({"tool": tool_name, "result": result})


async def handle_health(request):
    """GET /health - Health check."""
    return web.json_response(
        {
            "status": "ok",
            "environment": ENVIRONMENT,
            "mcp_version": "1.0.0",
            "timestamp": datetime.now().isoformat(),
        }
    )


# ============================================================================
# MAIN
# ============================================================================

async def main():
    """Iniciar MCP Server."""
    app = web.Application()

    # Rotas
    app.router.add_get("/health", handle_health)
    app.router.add_get("/mcp/resources", handle_get_resources)
    app.router.add_get("/mcp/resource/{name}", handle_read_resource)
    app.router.add_post("/mcp/resource/{name}", handle_write_resource)
    app.router.add_get("/mcp/tools", handle_get_tools)
    app.router.add_post("/mcp/tool/{name}", handle_execute_tool)

    logger.info(f"🚀 MCP Server iniciando")
    logger.info(f"   Ambiente: {ENVIRONMENT}")
    logger.info(f"   Host: {MCP_HOST}")
    logger.info(f"   Port: {MCP_PORT}")
    logger.info(f"   Repo: {REPO_ROOT}")

    runner = web.AppRunner(app)
    await runner.setup()

    site = web.TCPSite(runner, MCP_HOST, MCP_PORT)
    await site.start()

    logger.info(f"✅ MCP Server rodando em http://{MCP_HOST}:{MCP_PORT}")
    logger.info(f"   Health: http://{MCP_HOST}:{MCP_PORT}/health")
    logger.info(f"   Resources: http://{MCP_HOST}:{MCP_PORT}/mcp/resources")
    logger.info(f"   Tools: http://{MCP_HOST}:{MCP_PORT}/mcp/tools")

    try:
        await asyncio.Event().wait()
    except KeyboardInterrupt:
        logger.info("⏹️  MCP Server interrompido")
    finally:
        await runner.cleanup()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="MCP Server - ShopVivaliz")
    parser.add_argument("--port", type=int, default=5555, help="Port (default: 5555)")
    parser.add_argument("--host", default="0.0.0.0", help="Host (default: 0.0.0.0)")
    parser.add_argument("--env", default="unknown", help="Environment name")

    args = parser.parse_args()

    ENVIRONMENT = args.env
    MCP_PORT = args.port
    MCP_HOST = args.host

    asyncio.run(main())
