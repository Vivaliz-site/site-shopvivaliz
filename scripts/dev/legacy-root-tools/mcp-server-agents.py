#!/usr/bin/env python3
"""
MCP Server for Claude Agents to access remote machine
Allows agents to execute commands, manage files, and monitor systems
"""

import json
import subprocess
import sys
import os
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
import traceback
import logging

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('/tmp/mcp-agents.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

class MCPAgentHandler(BaseHTTPRequestHandler):
    """HTTP handler for MCP Agent requests"""

    def do_GET(self):
        """Handle GET requests"""
        path = urlparse(self.path).path
        query = parse_qs(urlparse(self.path).query)

        try:
            if path == '/status':
                self._handle_status()
            elif path == '/health':
                self._handle_health()
            elif path == '/logs':
                self._handle_logs(query)
            elif path == '/tools':
                self._handle_tools()
            else:
                self._send_error(404, {'error': 'Not found'})
        except Exception as e:
            logger.error(f"Error handling GET {path}: {str(e)}")
            self._send_error(500, {'error': str(e)})

    def do_POST(self):
        """Handle POST requests"""
        path = urlparse(self.path).path

        try:
            content_length = int(self.headers.get('Content-Length', 0))
            body = self.rfile.read(content_length)
            data = json.loads(body.decode('utf-8')) if body else {}

            if path == '/exec':
                self._handle_exec(data)
            elif path == '/file/read':
                self._handle_file_read(data)
            elif path == '/file/write':
                self._handle_file_write(data)
            elif path == '/file/delete':
                self._handle_file_delete(data)
            elif path == '/git/status':
                self._handle_git_status(data)
            elif path == '/git/log':
                self._handle_git_log(data)
            elif path == '/git/commit':
                self._handle_git_commit(data)
            elif path == '/git/push':
                self._handle_git_push(data)
            elif path == '/service/status':
                self._handle_service_status(data)
            elif path == '/service/restart':
                self._handle_service_restart(data)
            else:
                self._send_error(404, {'error': 'Not found'})
        except Exception as e:
            logger.error(f"Error handling POST {path}: {str(e)}\n{traceback.format_exc()}")
            self._send_error(500, {'error': str(e)})

    def _handle_status(self):
        """Get system status"""
        try:
            hostname = subprocess.check_output('hostname', text=True).strip()
            whoami = subprocess.check_output('whoami', text=True).strip()
            pwd = os.getcwd()

            status = {
                'status': 'online',
                'hostname': hostname,
                'user': whoami,
                'cwd': pwd,
                'timestamp': subprocess.check_output('date -u', shell=True, text=True).strip(),
                'services': self._get_services_status()
            }
            self._send_json(200, status)
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_health(self):
        """Get system health"""
        health = {
            'healthy': True,
            'mcp': 'running',
            'services': {}
        }

        for service in ['shopvivaliz-sync', 'shopvivaliz-mcp', 'ssh']:
            try:
                result = subprocess.run(['systemctl', 'is-active', service],
                                      capture_output=True, text=True, timeout=5)
                health['services'][service] = 'running' if result.returncode == 0 else 'stopped'
            except:
                health['services'][service] = 'unknown'

        self._send_json(200, health)

    def _handle_logs(self, query):
        """Get system logs"""
        lines = int(query.get('lines', ['20'])[0])
        try:
            logs = subprocess.check_output(
                f"journalctl -n {lines} --no-pager",
                shell=True, text=True, stderr=subprocess.STDOUT
            )
            self._send_json(200, {
                'logs': logs.split('\n'),
                'count': lines
            })
        except Exception as e:
            self._send_json(200, {'logs': [], 'error': str(e)})

    def _handle_tools(self):
        """List available tools for agents"""
        tools = {
            'tools': [
                {
                    'name': 'exec',
                    'description': 'Execute shell command',
                    'params': {'cmd': 'command to execute', 'timeout': 'timeout in seconds'}
                },
                {
                    'name': 'file_read',
                    'description': 'Read file contents',
                    'params': {'path': 'file path'}
                },
                {
                    'name': 'file_write',
                    'description': 'Write to file',
                    'params': {'path': 'file path', 'content': 'file content'}
                },
                {
                    'name': 'git_status',
                    'description': 'Get git status',
                    'params': {'path': 'repo path (optional)'}
                },
                {
                    'name': 'git_log',
                    'description': 'Get git log',
                    'params': {'path': 'repo path', 'lines': 'number of commits'}
                },
                {
                    'name': 'git_commit',
                    'description': 'Create git commit',
                    'params': {'path': 'repo path', 'message': 'commit message'}
                },
                {
                    'name': 'service_status',
                    'description': 'Check service status',
                    'params': {'service': 'service name'}
                },
                {
                    'name': 'service_restart',
                    'description': 'Restart service',
                    'params': {'service': 'service name'}
                }
            ]
        }
        self._send_json(200, tools)

    def _handle_exec(self, data):
        """Execute shell command"""
        cmd = data.get('cmd', '')
        timeout = int(data.get('timeout', 30))

        if not cmd:
            return self._send_error(400, {'error': 'cmd is required'})

        try:
            result = subprocess.run(
                cmd, shell=True, capture_output=True, text=True, timeout=timeout
            )
            self._send_json(200, {
                'output': result.stdout,
                'error': result.stderr,
                'returncode': result.returncode,
                'status': 'success' if result.returncode == 0 else 'failed'
            })
        except subprocess.TimeoutExpired:
            self._send_error(408, {'error': f'Command timeout after {timeout}s'})
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_file_read(self, data):
        """Read file contents"""
        path = data.get('path', '')
        if not path:
            return self._send_error(400, {'error': 'path is required'})

        try:
            with open(path, 'r') as f:
                content = f.read()
            self._send_json(200, {
                'path': path,
                'content': content,
                'size': len(content)
            })
        except FileNotFoundError:
            self._send_error(404, {'error': f'File not found: {path}'})
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_file_write(self, data):
        """Write to file"""
        path = data.get('path', '')
        content = data.get('content', '')

        if not path:
            return self._send_error(400, {'error': 'path is required'})

        try:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, 'w') as f:
                f.write(content)
            self._send_json(200, {
                'path': path,
                'written': len(content),
                'status': 'success'
            })
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_file_delete(self, data):
        """Delete file"""
        path = data.get('path', '')
        if not path:
            return self._send_error(400, {'error': 'path is required'})

        try:
            os.remove(path)
            self._send_json(200, {'path': path, 'status': 'deleted'})
        except FileNotFoundError:
            self._send_error(404, {'error': f'File not found: {path}'})
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_git_status(self, data):
        """Get git status"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        try:
            output = subprocess.check_output(
                f'cd {path} && git status --porcelain',
                shell=True, text=True
            )
            self._send_json(200, {
                'path': path,
                'status': output.strip(),
                'clean': len(output.strip()) == 0
            })
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_git_log(self, data):
        """Get git log"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        lines = data.get('lines', 10)
        try:
            output = subprocess.check_output(
                f'cd {path} && git log --oneline -n {lines}',
                shell=True, text=True
            )
            self._send_json(200, {
                'path': path,
                'log': output.strip().split('\n'),
                'count': len(output.strip().split('\n'))
            })
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_git_commit(self, data):
        """Create git commit"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        message = data.get('message', 'Auto-commit from MCP Agent')
        try:
            subprocess.run(f'cd {path} && git add -A', shell=True, check=True)
            output = subprocess.check_output(
                f'cd {path} && git commit -m "{message}"',
                shell=True, text=True, stderr=subprocess.STDOUT
            )
            self._send_json(200, {
                'message': message,
                'output': output.strip(),
                'status': 'committed'
            })
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_git_push(self, data):
        """Push to git remote"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        remote = data.get('remote', 'origin')
        branch = data.get('branch', 'main')
        try:
            output = subprocess.check_output(
                f'cd {path} && git push {remote} {branch}',
                shell=True, text=True, stderr=subprocess.STDOUT
            )
            self._send_json(200, {
                'remote': remote,
                'branch': branch,
                'output': output.strip(),
                'status': 'pushed'
            })
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_service_status(self, data):
        """Get service status"""
        service = data.get('service', '')
        if not service:
            return self._send_error(400, {'error': 'service is required'})

        try:
            result = subprocess.run(
                ['systemctl', 'is-active', service],
                capture_output=True, text=True
            )
            status = 'running' if result.returncode == 0 else 'stopped'
            self._send_json(200, {
                'service': service,
                'status': status,
                'active': result.returncode == 0
            })
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _handle_service_restart(self, data):
        """Restart service"""
        service = data.get('service', '')
        if not service:
            return self._send_error(400, {'error': 'service is required'})

        try:
            subprocess.run(
                ['sudo', 'systemctl', 'restart', service],
                capture_output=True, text=True, timeout=10
            )
            self._send_json(200, {
                'service': service,
                'status': 'restarted'
            })
        except Exception as e:
            self._send_error(500, {'error': str(e)})

    def _get_services_status(self):
        """Get status of all services"""
        services = {}
        for service in ['shopvivaliz-mcp', 'shopvivaliz-sync', 'ssh']:
            try:
                result = subprocess.run(['systemctl', 'is-active', service],
                                      capture_output=True, text=True, timeout=5)
                services[service] = 'running' if result.returncode == 0 else 'stopped'
            except:
                services[service] = 'unknown'
        return services

    def _send_json(self, code, data):
        """Send JSON response"""
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def _send_error(self, code, error):
        """Send error response"""
        self._send_json(code, error)

    def log_message(self, format, *args):
        """Suppress logging"""
        pass


def main():
    """Start MCP Agent Server"""
    host = '0.0.0.0'
    port = 5556

    server = HTTPServer((host, port), MCPAgentHandler)
    logger.info(f"MCP Agent Server listening on {host}:{port}")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        logger.info("Server shutdown")
        server.shutdown()


if __name__ == '__main__':
    main()
