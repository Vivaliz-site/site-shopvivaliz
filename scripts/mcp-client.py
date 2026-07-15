#!/usr/bin/env python3
"""
MCP Client - Interface para Claude acessar MCP Servers

Permite Claude se comunicar com múltiplos MCP Servers em diferentes estações.

Uso:
  python scripts/mcp-client.py --list-resources --server localhost:5555
  python scripts/mcp-client.py --read-resource status://system --server ubuntu-vm:5556
  python scripts/mcp-client.py --execute-tool git_pull --server windows-local:5555
"""

import os
import sys
import json
import argparse
from typing import Dict, Any, Optional
from urllib.parse import urlparse

try:
    import requests
except ImportError:
    print("❌ requests não instalado. Execute: pip install requests")
    sys.exit(1)


class MCPClient:
    """Cliente para comunicar com MCP Servers."""

    def __init__(self, server_url: str, timeout: int = 30):
        """Inicializar cliente MCP."""
        self.server_url = server_url.rstrip("/")
        self.timeout = timeout

    def health_check(self) -> Dict[str, Any]:
        """Verificar saúde do servidor MCP."""
        try:
            response = requests.get(f"{self.server_url}/health", timeout=self.timeout)
            response.raise_for_status()
            return response.json()
        except Exception as e:
            return {"error": str(e)}

    def list_resources(self) -> Dict[str, Any]:
        """Listar recursos disponíveis no servidor."""
        try:
            response = requests.get(
                f"{self.server_url}/mcp/resources", timeout=self.timeout
            )
            response.raise_for_status()
            return response.json()
        except Exception as e:
            return {"error": str(e)}

    def read_resource(self, resource: str) -> Dict[str, Any]:
        """Ler um recurso do servidor."""
        try:
            response = requests.get(
                f"{self.server_url}/mcp/resource/{resource}", timeout=self.timeout
            )
            response.raise_for_status()
            return response.json()
        except Exception as e:
            return {"error": str(e)}

    def write_resource(self, resource: str, content: str) -> Dict[str, Any]:
        """Escrever um recurso no servidor."""
        try:
            response = requests.post(
                f"{self.server_url}/mcp/resource/{resource}",
                json={"content": content},
                timeout=self.timeout,
            )
            response.raise_for_status()
            return response.json()
        except Exception as e:
            return {"error": str(e)}

    def list_tools(self) -> Dict[str, Any]:
        """Listar tools disponíveis no servidor."""
        try:
            response = requests.get(
                f"{self.server_url}/mcp/tools", timeout=self.timeout
            )
            response.raise_for_status()
            return response.json()
        except Exception as e:
            return {"error": str(e)}

    def execute_tool(self, tool_name: str, params: Dict[str, Any]) -> Dict[str, Any]:
        """Executar uma tool no servidor."""
        try:
            response = requests.post(
                f"{self.server_url}/mcp/tool/{tool_name}",
                json={"params": params},
                timeout=self.timeout,
            )
            response.raise_for_status()
            return response.json()
        except Exception as e:
            return {"error": str(e)}


class MCPCloudManager:
    """Gerenciar múltiplos MCP Servers (nuvem de agentes)."""

    def __init__(self):
        """Inicializar gerenciador."""
        self.servers: Dict[str, MCPClient] = {}
        self.server_meta: Dict[str, Dict[str, Any]] = {}
        self.load_server_config()

    def load_server_config(self):
        """Carregar configuração de servidores."""
        config_file = os.path.join(
            os.path.dirname(__file__), "..", "mcp-servers.json"
        )

        default_config = {
            "servers": {
                "windows-local": "http://localhost:5555",
                "ubuntu-vm": "http://137.131.156.17:5556",
                "fred-win": "http://192.168.1.100:5557",
            }
        }

        if os.path.exists(config_file):
            with open(config_file, "r") as f:
                config = json.load(f)
        else:
            config = default_config
            with open(config_file, "w") as f:
                json.dump(config, f, indent=2)

        for name, entry in config.get("servers", {}).items():
            if isinstance(entry, dict):
                url = entry.get("url", "")
                meta = entry
            else:
                url = entry
                meta = {"url": entry}

            if not url:
                continue

            self.servers[name] = MCPClient(url)
            self.server_meta[name] = meta

    def list_available_servers(self) -> Dict[str, Dict[str, Any]]:
        """Listar servidores disponíveis e seu status."""
        result = {}
        for name, client in self.servers.items():
            meta = self.server_meta.get(name, {})
            enabled = meta.get("enabled", True)
            if not enabled:
                result[name] = {
                    "url": client.server_url,
                    "status": "disabled",
                    "enabled": False,
                    "environment": meta.get("environment"),
                    "location": meta.get("location"),
                    "health": {"status": "disabled"},
                }
                continue

            health = client.health_check()
            result[name] = {
                "url": client.server_url,
                "status": "online" if "status" in health else "offline",
                "enabled": True,
                "environment": meta.get("environment"),
                "location": meta.get("location"),
                "health": health,
            }
        return result

    def broadcast_command(self, tool_name: str, params: Dict[str, Any]):
        """Executar comando em todos os servidores."""
        results = {}
        for name, client in self.servers.items():
            print(f"Executando {tool_name} em {name}...")
            result = client.execute_tool(tool_name, params)
            results[name] = result
        return results


def validate_server_config(config_path: str) -> Dict[str, Any]:
    """Validar a configuração sem depender de servidores privados estarem online."""
    errors = []
    try:
        with open(config_path, "r", encoding="utf-8") as config_file:
            config = json.load(config_file)
    except (OSError, json.JSONDecodeError) as exc:
        return {"valid": False, "errors": [str(exc)]}

    servers = config.get("servers")
    if not isinstance(servers, dict) or not servers:
        errors.append("'servers' deve ser um objeto não vazio")
        servers = {}

    for name, server in servers.items():
        if not isinstance(server, dict):
            errors.append(f"{name}: configuração deve ser um objeto")
            continue
        parsed_url = urlparse(server.get("url", ""))
        if parsed_url.scheme not in {"http", "https"} or not parsed_url.netloc:
            errors.append(f"{name}: URL HTTP(S) inválida")
        for field in ("environment", "location"):
            if not isinstance(server.get(field), str) or not server[field].strip():
                errors.append(f"{name}: campo '{field}' ausente ou inválido")
        if not isinstance(server.get("enabled"), bool):
            errors.append(f"{name}: campo 'enabled' deve ser booleano")

    return {
        "valid": not errors,
        "servers": len(servers),
        "enabled": sum(
            1
            for server in servers.values()
            if isinstance(server, dict) and server.get("enabled") is True
        ),
        "errors": errors,
    }


def main():
    """CLI para MCP Client."""
    parser = argparse.ArgumentParser(description="MCP Client - ShopVivaliz")

    # Servidor
    parser.add_argument(
        "--server",
        default="localhost:5555",
        help="Server (ex: localhost:5555 ou ubuntu-vm:5556)",
    )

    # Operações
    parser.add_argument(
        "--health", action="store_true", help="Verificar saúde do servidor"
    )
    parser.add_argument(
        "--list-resources", action="store_true", help="Listar recursos disponíveis"
    )
    parser.add_argument(
        "--read-resource", metavar="NAME", help="Ler um recurso (ex: status://system)"
    )
    parser.add_argument(
        "--write-resource",
        nargs=2,
        metavar=("NAME", "FILE"),
        help="Escrever recurso de arquivo",
    )
    parser.add_argument("--list-tools", action="store_true", help="Listar tools")
    parser.add_argument(
        "--execute-tool",
        nargs=2,
        metavar=("NAME", "PARAMS_JSON"),
        help="Executar tool (ex: execute_git_command '{\"command\": \"status\"}')",
    )
    parser.add_argument(
        "--list-servers", action="store_true", help="Listar servidores conhecidos"
    )
    parser.add_argument(
        "--validate-config",
        action="store_true",
        help="Validar mcp-servers.json sem acessar a rede",
    )
    parser.add_argument(
        "--broadcast", action="store_true", help="Executar em todos os servidores"
    )

    args = parser.parse_args()

    if args.validate_config:
        config_path = os.path.join(
            os.path.dirname(__file__), "..", "mcp-servers.json"
        )
        result = validate_server_config(config_path)
        print(json.dumps(result, indent=2))
        sys.exit(0 if result["valid"] else 1)

    # Normalizar URL do servidor
    if "://" not in args.server:
        args.server = f"http://{args.server}"

    client = MCPClient(args.server)

    # Executar operações
    if args.health:
        result = client.health_check()
        print(json.dumps(result, indent=2))
        sys.exit(1 if "error" in result else 0)

    elif args.list_resources:
        result = client.list_resources()
        print(json.dumps(result, indent=2))
        sys.exit(1 if "error" in result else 0)

    elif args.read_resource:
        result = client.read_resource(args.read_resource)
        print(json.dumps(result, indent=2))
        sys.exit(1 if "error" in result else 0)

    elif args.write_resource:
        name, file_path = args.write_resource
        with open(file_path, "r") as f:
            content = f.read()
        result = client.write_resource(name, content)
        print(json.dumps(result, indent=2))
        sys.exit(1 if "error" in result else 0)

    elif args.list_tools:
        result = client.list_tools()
        print(json.dumps(result, indent=2))
        sys.exit(1 if "error" in result else 0)

    elif args.execute_tool:
        tool_name, params_json = args.execute_tool
        try:
            params = json.loads(params_json)
        except json.JSONDecodeError:
            print("❌ Erro ao fazer parse de params JSON")
            sys.exit(1)
        result = client.execute_tool(tool_name, params)
        print(json.dumps(result, indent=2))
        sys.exit(1 if "error" in result else 0)

    elif args.list_servers:
        manager = MCPCloudManager()
        servers = manager.list_available_servers()
        print(json.dumps(servers, indent=2))

    elif args.broadcast:
        print("❌ --broadcast requer também --execute-tool")
        sys.exit(1)

    else:
        parser.print_help()


if __name__ == "__main__":
    main()
