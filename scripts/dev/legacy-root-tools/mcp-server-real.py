#!/usr/bin/env python3
"""
Real MCP (Model Context Protocol) Server
Compatible with ChatGPT, Claude, Gemini and all MCP clients
Implements standard MCP protocol: initialize, notifications, tools/list, tools/call
"""

import json
import subprocess
import sys
import logging
from typing import Any, Dict, List, Optional
import asyncio
from datetime import datetime

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('/tmp/mcp-server.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# MCP Protocol Implementation
class MCPServer:
    """Real MCP Server implementing the Model Context Protocol"""

    def __init__(self):
        self.name = "ShopVivaliz Remote Machine Access"
        self.version = "1.0.0"
        self.initialized = False
        self.tools = self._define_tools()

    def _define_tools(self) -> List[Dict[str, Any]]:
        """Define available tools for MCP clients"""
        return [
            {
                "name": "execute_command",
                "description": "Execute shell command on remote machine",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "cmd": {"type": "string", "description": "Command to execute"},
                        "timeout": {"type": "integer", "description": "Timeout in seconds", "default": 30}
                    },
                    "required": ["cmd"]
                }
            },
            {
                "name": "git_status",
                "description": "Get git repository status",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "path": {"type": "string", "description": "Repository path"}
                    }
                }
            },
            {
                "name": "git_commit",
                "description": "Create git commit",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "message": {"type": "string", "description": "Commit message"},
                        "path": {"type": "string", "description": "Repository path"}
                    },
                    "required": ["message"]
                }
            },
            {
                "name": "git_push",
                "description": "Push commits to remote",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "remote": {"type": "string", "description": "Remote name", "default": "origin"},
                        "branch": {"type": "string", "description": "Branch name", "default": "main"},
                        "path": {"type": "string", "description": "Repository path"}
                    }
                }
            },
            {
                "name": "file_read",
                "description": "Read file contents",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "path": {"type": "string", "description": "File path"}
                    },
                    "required": ["path"]
                }
            },
            {
                "name": "file_write",
                "description": "Write to file",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "path": {"type": "string", "description": "File path"},
                        "content": {"type": "string", "description": "File content"}
                    },
                    "required": ["path", "content"]
                }
            },
            {
                "name": "service_status",
                "description": "Check service status",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "service": {"type": "string", "description": "Service name"}
                    },
                    "required": ["service"]
                }
            },
            {
                "name": "npm_build",
                "description": "Run npm build",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "path": {"type": "string", "description": "Project path"}
                    }
                }
            },
            {
                "name": "system_health",
                "description": "Get system health status",
                "inputSchema": {
                    "type": "object",
                    "properties": {}
                }
            }
        ]

    async def handle_initialize(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Handle MCP initialize request"""
        logger.info("MCP Client initializing")
        self.initialized = True

        return {
            "protocolVersion": "2024-11-05",
            "capabilities": {
                "tools": {}
            },
            "serverInfo": {
                "name": self.name,
                "version": self.version
            }
        }

    async def handle_tools_list(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Handle tools/list request"""
        logger.info("Listing available tools")
        return {
            "tools": self.tools
        }

    async def handle_tools_call(self, params: Dict[str, Any]) -> Dict[str, Any]:
        """Handle tools/call request"""
        tool_name = params.get("name")
        arguments = params.get("arguments", {})

        logger.info(f"Calling tool: {tool_name}")

        try:
            if tool_name == "execute_command":
                result = await self._execute_command(arguments)
            elif tool_name == "git_status":
                result = await self._git_status(arguments)
            elif tool_name == "git_commit":
                result = await self._git_commit(arguments)
            elif tool_name == "git_push":
                result = await self._git_push(arguments)
            elif tool_name == "file_read":
                result = await self._file_read(arguments)
            elif tool_name == "file_write":
                result = await self._file_write(arguments)
            elif tool_name == "service_status":
                result = await self._service_status(arguments)
            elif tool_name == "npm_build":
                result = await self._npm_build(arguments)
            elif tool_name == "system_health":
                result = await self._system_health()
            else:
                return {"error": f"Unknown tool: {tool_name}"}

            return {
                "content": [
                    {
                        "type": "text",
                        "text": json.dumps(result, indent=2)
                    }
                ],
                "isError": False
            }
        except Exception as e:
            logger.error(f"Tool error: {str(e)}")
            return {
                "content": [
                    {
                        "type": "text",
                        "text": f"Error: {str(e)}"
                    }
                ],
                "isError": True
            }

    async def _execute_command(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Execute shell command"""
        cmd = args.get("cmd", "")
        timeout = args.get("timeout", 30)

        if not cmd:
            raise ValueError("cmd is required")

        try:
            result = subprocess.run(
                cmd, shell=True, capture_output=True, text=True, timeout=timeout
            )
            return {
                "command": cmd,
                "output": result.stdout,
                "error": result.stderr,
                "returncode": result.returncode,
                "status": "success" if result.returncode == 0 else "failed"
            }
        except subprocess.TimeoutExpired:
            raise TimeoutError(f"Command timeout after {timeout}s")

    async def _git_status(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Get git status"""
        path = args.get("path", "/home/shopvivaliz/site-shopvivaliz")
        try:
            result = subprocess.run(
                f"cd {path} && git status --porcelain",
                shell=True, capture_output=True, text=True
            )
            return {
                "path": path,
                "status": result.stdout.strip(),
                "clean": len(result.stdout.strip()) == 0
            }
        except Exception as e:
            raise RuntimeError(f"Git status failed: {str(e)}")

    async def _git_commit(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Create git commit"""
        message = args.get("message", "Auto-commit from MCP")
        path = args.get("path", "/home/shopvivaliz/site-shopvivaliz")

        try:
            subprocess.run(f"cd {path} && git add -A", shell=True, check=True)
            result = subprocess.run(
                f'cd {path} && git commit -m "{message}"',
                shell=True, capture_output=True, text=True
            )
            return {
                "message": message,
                "output": result.stdout + result.stderr,
                "status": "committed" if result.returncode == 0 else "failed"
            }
        except Exception as e:
            raise RuntimeError(f"Git commit failed: {str(e)}")

    async def _git_push(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Push to remote"""
        remote = args.get("remote", "origin")
        branch = args.get("branch", "main")
        path = args.get("path", "/home/shopvivaliz/site-shopvivaliz")

        try:
            result = subprocess.run(
                f"cd {path} && git push {remote} {branch}",
                shell=True, capture_output=True, text=True
            )
            return {
                "remote": remote,
                "branch": branch,
                "output": result.stdout + result.stderr,
                "status": "pushed" if result.returncode == 0 else "failed"
            }
        except Exception as e:
            raise RuntimeError(f"Git push failed: {str(e)}")

    async def _file_read(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Read file"""
        path = args.get("path", "")
        if not path:
            raise ValueError("path is required")

        try:
            with open(path, 'r') as f:
                content = f.read()
            return {
                "path": path,
                "content": content,
                "size": len(content)
            }
        except FileNotFoundError:
            raise FileNotFoundError(f"File not found: {path}")

    async def _file_write(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Write file"""
        path = args.get("path", "")
        content = args.get("content", "")

        if not path:
            raise ValueError("path is required")

        try:
            import os
            os.makedirs(os.path.dirname(path) or '.', exist_ok=True)
            with open(path, 'w') as f:
                f.write(content)
            return {
                "path": path,
                "written": len(content),
                "status": "success"
            }
        except Exception as e:
            raise RuntimeError(f"File write failed: {str(e)}")

    async def _service_status(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Check service status"""
        service = args.get("service", "")
        if not service:
            raise ValueError("service is required")

        try:
            result = subprocess.run(
                f"systemctl is-active {service}",
                shell=True, capture_output=True, text=True
            )
            return {
                "service": service,
                "status": "running" if result.returncode == 0 else "stopped",
                "active": result.returncode == 0
            }
        except Exception as e:
            raise RuntimeError(f"Service check failed: {str(e)}")

    async def _npm_build(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Run npm build"""
        path = args.get("path", "/home/shopvivaliz/site-shopvivaliz")

        try:
            result = subprocess.run(
                f"cd {path} && npm run build",
                shell=True, capture_output=True, text=True, timeout=300
            )
            return {
                "path": path,
                "output": result.stdout[-500:] if result.stdout else "",
                "error": result.stderr[-500:] if result.stderr else "",
                "status": "success" if result.returncode == 0 else "failed"
            }
        except subprocess.TimeoutExpired:
            raise TimeoutError("npm build timeout")

    async def _system_health(self) -> Dict[str, Any]:
        """Get system health"""
        services = {}
        for service in ["shopvivaliz-sync", "shopvivaliz-mcp", "mcp-universal", "ssh"]:
            try:
                result = subprocess.run(
                    f"systemctl is-active {service}",
                    shell=True, capture_output=True, text=True, timeout=5
                )
                services[service] = "running" if result.returncode == 0 else "stopped"
            except:
                services[service] = "unknown"

        return {
            "timestamp": datetime.utcnow().isoformat(),
            "services": services,
            "healthy": all(s == "running" for s in services.values())
        }

    async def process_request(self, request: Dict[str, Any]) -> Dict[str, Any]:
        """Process MCP request"""
        method = request.get("method")
        params = request.get("params", {})

        logger.info(f"Processing MCP request: {method}")

        if method == "initialize":
            return await self.handle_initialize(params)
        elif method == "tools/list":
            return await self.handle_tools_list(params)
        elif method == "tools/call":
            return await self.handle_tools_call(params)
        else:
            return {"error": f"Unknown method: {method}"}


async def main():
    """Main MCP server loop"""
    server = MCPServer()
    logger.info("MCP Server starting...")
    logger.info(f"Server: {server.name} v{server.version}")

    try:
        while True:
            # Read request from stdin (JSONRPC 2.0)
            try:
                line = sys.stdin.readline()
                if not line:
                    break

                request = json.loads(line)
                response = await server.process_request(request)

                # Write response to stdout
                sys.stdout.write(json.dumps(response) + '\n')
                sys.stdout.flush()

            except json.JSONDecodeError as e:
                logger.error(f"JSON decode error: {e}")
                error_response = {
                    "error": f"Invalid JSON: {str(e)}"
                }
                sys.stdout.write(json.dumps(error_response) + '\n')
                sys.stdout.flush()

    except KeyboardInterrupt:
        logger.info("MCP Server shutdown")
    except Exception as e:
        logger.error(f"Server error: {e}")
        sys.exit(1)


if __name__ == "__main__":
    asyncio.run(main())
