#!/usr/bin/env python3
"""
ShopVivaliz CLI - Controle Centralizado do Sistema

Ferramenta única para gerenciar múltiplas estações, sincronizações e tarefas.

Uso:
  python scripts/shopvivaliz-cli.py status
  python scripts/shopvivaliz-cli.py logs ubuntu-vm
  python scripts/shopvivaliz-cli.py sync --parallel
  python scripts/shopvivaliz-cli.py task --list
  python scripts/shopvivaliz-cli.py dashboard
"""

import os
import sys
import json
import time
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Any

try:
    import click
    import requests
    from tabulate import tabulate
except ImportError:
    print("❌ Dependências não instaladas. Execute:")
    print("   pip install click requests tabulate")
    sys.exit(1)

# ============================================================================
# CONFIGURAÇÃO
# ============================================================================

REPO_ROOT = Path(__file__).parent.parent
MCP_SERVERS_FILE = REPO_ROOT / "mcp-servers.json"
TASKS_FILE = REPO_ROOT / "tasks-queue.json"
LOGS_DIR = REPO_ROOT / "logs"


# ============================================================================
# HELPERS
# ============================================================================

def load_mcp_config() -> Dict[str, Any]:
    """Carregar configuração de servidores MCP."""
    if MCP_SERVERS_FILE.exists():
        with open(MCP_SERVERS_FILE) as f:
            return json.load(f)
    return {"servers": {}}


def get_mcp_client(server_name: str):
    """Criar cliente MCP para um servidor."""
    config = load_mcp_config()
    server = config["servers"].get(server_name)
    if not server or not server.get("enabled"):
        return None

    from scripts.mcp_client import MCPClient
    return MCPClient(server["url"])


def format_table(data: List[Dict], headers: List[str]) -> str:
    """Formatar dados como tabela."""
    return tabulate(data, headers=headers, tablefmt="grid")


def print_success(msg: str):
    """Print com cor verde."""
    click.secho(f"✅ {msg}", fg="green")


def print_error(msg: str):
    """Print com cor vermelha."""
    click.secho(f"❌ {msg}", fg="red")


def print_info(msg: str):
    """Print com cor azul."""
    click.secho(f"ℹ️  {msg}", fg="cyan")


# ============================================================================
# CLI GROUP
# ============================================================================

@click.group()
@click.version_option(version="1.0.0")
def cli():
    """🎮 ShopVivaliz Control Center - Controle Centralizado do Sistema"""
    pass


# ============================================================================
# COMANDO: STATUS
# ============================================================================

@cli.command()
@click.option("--detailed", is_flag=True, help="Mostrar informações detalhadas")
def status(detailed):
    """📊 Mostrar status de todas as estações"""
    click.echo("\n🔍 Verificando status de todas as estações...\n")

    config = load_mcp_config()
    servers = config.get("servers", {})

    status_data = []

    for server_name, server_config in servers.items():
        enabled = server_config.get("enabled", False)
        status_icon = "🟢" if enabled else "⚪"

        if not enabled:
            status_data.append({
                "Estação": server_name,
                "Status": f"{status_icon} Desabilitada",
                "Uptime": "N/A",
                "Syncs": "N/A",
            })
            continue

        # Tentar conectar
        try:
            response = requests.get(
                f"{server_config['url']}/health",
                timeout=5
            )
            health = response.json()
            status_icon = "🟢"
            uptime = health.get("status", "desconhecido")

            # Ler stats de sync
            mcp = get_mcp_client(server_name)
            sync_stats = {}
            if mcp:
                result = mcp.read_resource("sync://stats")
                try:
                    sync_stats = json.loads(result.get("content", "{}"))
                except:
                    pass

            syncs_today = sync_stats.get("syncs_today", 0)

        except Exception as e:
            status_icon = "🔴"
            uptime = "offline"
            syncs_today = "N/A"

        status_data.append({
            "Estação": server_name,
            "Status": f"{status_icon} Online" if status_icon == "🟢" else f"{status_icon} Offline",
            "Uptime": uptime,
            "Syncs Hoje": syncs_today,
            "URL": server_config["url"] if detailed else "...",
        })

    headers = ["Estação", "Status", "Uptime", "Syncs Hoje"] + (["URL"] if detailed else [])
    table = format_table(status_data, headers)
    click.echo(table)

    # Resumo
    online = sum(1 for s in status_data if "Online" in str(s.get("Status", "")))
    total = len(status_data)
    click.echo(f"\n{online}/{total} estações online\n")


# ============================================================================
# COMANDO: LOGS
# ============================================================================

@cli.command()
@click.argument("environment", default="all")
@click.option("--type", "log_type", default="sync", type=click.Choice(["sync", "agentes", "mcp"]))
@click.option("--lines", default=50, help="Número de linhas")
@click.option("--follow", is_flag=True, help="Seguir logs em tempo real")
def logs(environment, log_type, lines, follow):
    """📝 Ver logs de uma estação"""
    if environment == "all":
        # Mostrar logs de todas
        click.echo(f"\n📋 Últimos logs ({log_type}) de todas as estações:\n")

        config = load_mcp_config()
        for server_name in config.get("servers", {}).keys():
            mcp = get_mcp_client(server_name)
            if not mcp:
                continue

            try:
                result = mcp.execute_tool("get_logs", {
                    "log_type": log_type,
                    "lines": lines
                })
                content = result.get("result", {}).get("lines", "")

                click.secho(f"\n{'='*60}", fg="blue")
                click.secho(f"📍 {server_name.upper()}", fg="cyan", bold=True)
                click.secho(f"{'='*60}\n", fg="blue")
                click.echo(content[:500] + "..." if len(content) > 500 else content)

            except Exception as e:
                print_error(f"Erro ao ler logs de {server_name}: {e}")
    else:
        # Logs de uma estação específica
        mcp = get_mcp_client(environment)
        if not mcp:
            print_error(f"Servidor {environment} não encontrado ou desabilitado")
            return

        try:
            while True:
                result = mcp.execute_tool("get_logs", {
                    "log_type": log_type,
                    "lines": lines
                })
                content = result.get("result", {}).get("lines", "")

                click.clear()
                click.secho(f"📝 Logs de {environment} ({log_type})", fg="cyan", bold=True)
                click.secho(f"{'='*60}\n", fg="blue")
                click.echo(content)

                if not follow:
                    break

                time.sleep(2)

        except Exception as e:
            print_error(f"Erro: {e}")


# ============================================================================
# COMANDO: SYNC
# ============================================================================

@cli.command()
@click.option("--server", default="all", help="Estação específica ou 'all'")
@click.option("--parallel", is_flag=True, help="Executar em paralelo")
def sync(server, parallel):
    """🔄 Forçar sincronização"""
    config = load_mcp_config()
    servers = config.get("servers", {})

    if server == "all":
        servers_to_sync = list(servers.keys())
    else:
        servers_to_sync = [server]

    click.echo(f"\n🔄 Sincronizando {len(servers_to_sync)} estação(s)...\n")

    results = {}

    if parallel:
        # Não esperar por nada
        for srv in servers_to_sync:
            mcp = get_mcp_client(srv)
            if mcp:
                try:
                    result = mcp.execute_tool("execute_git_command", {
                        "command": "pull origin main"
                    })
                    results[srv] = "✅ Iniciado"
                except Exception as e:
                    results[srv] = f"❌ {str(e)[:30]}"
    else:
        # Esperar cada um completar
        for srv in servers_to_sync:
            mcp = get_mcp_client(srv)
            if not mcp:
                results[srv] = "⚪ Desabilitado"
                continue

            try:
                click.echo(f"Sincronizando {srv}...", nl=False)
                result = mcp.execute_tool("execute_git_command", {
                    "command": "pull origin main"
                })

                if result.get("result", {}).get("success"):
                    results[srv] = "✅ Sucesso"
                    click.echo(" ✅")
                else:
                    results[srv] = "⚠️ Warning"
                    click.echo(" ⚠️")

            except Exception as e:
                results[srv] = f"❌ {str(e)[:30]}"
                click.echo(" ❌")

    # Mostrar resumo
    click.echo("\n📊 Resumo:")
    for srv, status in results.items():
        click.echo(f"  {srv}: {status}")

    click.echo()


# ============================================================================
# COMANDO: TASK
# ============================================================================

@cli.command()
@click.option("--list", "list_tasks", is_flag=True, help="Listar tarefas")
@click.option("--status", default="pending", type=click.Choice(["pending", "running", "done", "failed"]))
@click.option("--create", is_flag=True, help="Criar tarefa interativa")
def task(list_tasks, status, create):
    """📌 Gerenciar tarefas"""
    if not TASKS_FILE.exists():
        print_error("tasks-queue.json não encontrado")
        return

    with open(TASKS_FILE) as f:
        tasks_data = json.load(f)

    tasks = tasks_data.get("tasks", [])

    if list_tasks:
        # Filtrar por status
        filtered = [t for t in tasks if t.get("status") == status]

        if not filtered:
            print_info(f"Nenhuma tarefa com status '{status}'")
            return

        task_data = []
        for t in filtered:
            task_data.append({
                "ID": t.get("task_id", "?"),
                "Título": t.get("title", "?")[:40],
                "Status": t.get("status", "?"),
                "Prioridade": t.get("priority", "?"),
            })

        table = format_table(task_data, ["ID", "Título", "Status", "Prioridade"])
        click.echo(f"\n📋 Tarefas ({status}):\n")
        click.echo(table)
        click.echo()

    elif create:
        # Criar tarefa interativa
        task_id = click.prompt("ID da tarefa")
        title = click.prompt("Título")
        priority = click.prompt("Prioridade (high/medium/low)")

        new_task = {
            "task_id": task_id,
            "title": title,
            "priority": priority,
            "status": "pending",
            "created_at": datetime.now().isoformat(),
        }

        tasks_data["tasks"].append(new_task)

        with open(TASKS_FILE, "w") as f:
            json.dump(tasks_data, f, indent=2)

        print_success(f"Tarefa {task_id} criada")


# ============================================================================
# COMANDO: MCP
# ============================================================================

@cli.command()
@click.argument("command")
@click.option("--server", default="windows-local")
@click.option("--params", default="{}")
def mcp(command, server, params):
    """🌉 Executar command via MCP"""
    mcp_client = get_mcp_client(server)
    if not mcp_client:
        print_error(f"Servidor {server} não disponível")
        return

    try:
        params_dict = json.loads(params)
    except json.JSONDecodeError:
        print_error("Params JSON inválido")
        return

    try:
        result = mcp_client.execute_tool(command, params_dict)
        click.echo(json.dumps(result, indent=2))
    except Exception as e:
        print_error(f"Erro: {e}")


# ============================================================================
# COMANDO: DASHBOARD
# ============================================================================

@cli.command()
@click.option("--port", default=8888, help="Porta do dashboard")
def dashboard(port):
    """📊 Abrir dashboard web"""
    from scripts.shopvivaliz_dashboard import create_app

    app = create_app()

    click.secho(f"\n🚀 Dashboard rodando em http://localhost:{port}/\n", fg="green", bold=True)
    click.echo("Pressione Ctrl+C para parar\n")

    try:
        app.run(host="0.0.0.0", port=port, debug=False)
    except KeyboardInterrupt:
        click.echo("\n⏹️  Dashboard parado")


# ============================================================================
# COMANDO: HEALTH
# ============================================================================

@cli.command()
def health():
    """🏥 Verificação rápida de saúde"""
    click.echo("\n🏥 Verificando saúde do sistema...\n")

    config = load_mcp_config()
    checks = {
        "Git instalado": False,
        "Python 3.8+": False,
        "Repositório OK": False,
        "MCP Servers": 0,
    }

    # Verificar git
    try:
        subprocess.run(["git", "--version"], capture_output=True, check=True)
        checks["Git instalado"] = True
    except:
        pass

    # Python
    import sys
    if sys.version_info >= (3, 8):
        checks["Python 3.8+"] = True

    # Repositório
    if (REPO_ROOT / ".git").exists():
        checks["Repositório OK"] = True

    # MCP Servers online
    for name, server in config.get("servers", {}).items():
        if server.get("enabled"):
            try:
                requests.get(f"{server['url']}/health", timeout=2)
                checks["MCP Servers"] += 1
            except:
                pass

    # Mostrar
    for check, status in checks.items():
        status_icon = "✅" if status else "❌" if isinstance(status, bool) else f"📊 {status}"
        click.echo(f"  {status_icon} {check}")

    click.echo()


# ============================================================================
# MAIN
# ============================================================================

if __name__ == "__main__":
    try:
        cli()
    except KeyboardInterrupt:
        click.echo("\n⏹️  Interrompido pelo usuário")
        sys.exit(0)
    except Exception as e:
        print_error(f"Erro: {e}")
        sys.exit(1)
