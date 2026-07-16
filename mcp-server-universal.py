#!/usr/bin/env python3
"""
Universal MCP Server - Compatible with GPT, Claude, Gemini, and all AI providers
Supports OpenAI, Anthropic, Google, and custom integrations
"""

import json
import subprocess
import sys
import os
import hashlib
import hmac
import time
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
import traceback
import logging
from datetime import datetime, timedelta
from collections import defaultdict
import threading

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - [%(provider)s] %(message)s',
    handlers=[
        logging.FileHandler('/tmp/mcp-universal.log'),
        logging.StreamHandler()
    ]
)

class ContextFilter(logging.Filter):
    def filter(self, record):
        record.provider = getattr(record, 'provider', 'unknown')
        return True

logger = logging.getLogger(__name__)
logger.addFilter(ContextFilter())

# API Keys (load from environment or file)
API_KEYS = {
    'openai': os.getenv('MCP_OPENAI_KEY', 'sk-openai-default-key'),
    'claude': os.getenv('MCP_CLAUDE_KEY', 'sk-claude-default-key'),
    'gemini': os.getenv('MCP_GEMINI_KEY', 'sk-gemini-default-key'),
    'default': os.getenv('MCP_DEFAULT_KEY', 'sk-default-universal-key'),
}

# Rate limiting
rate_limits = defaultdict(lambda: defaultdict(list))
MAX_REQUESTS_PER_MINUTE = 60
MAX_REQUESTS_PER_HOUR = 1000

class MCPUniversalHandler(BaseHTTPRequestHandler):
    """Universal MCP handler for all AI providers"""

    def do_GET(self):
        """Handle GET requests"""
        path = urlparse(self.path).path
        query = parse_qs(urlparse(self.path).query)

        try:
            provider = self._get_provider()

            if path == '/status':
                self._handle_status(provider)
            elif path == '/health':
                self._handle_health(provider)
            elif path == '/logs':
                self._handle_logs(query, provider)
            elif path == '/tools':
                self._handle_tools(provider)
            elif path == '/providers':
                self._handle_providers(provider)
            elif path == '/':
                self._handle_index(provider)
            else:
                self._send_error(404, {'error': 'Not found'}, provider)
        except Exception as e:
            logger.error(f"Error handling GET {path}: {str(e)}", extra={'provider': self._get_provider()})
            self._send_error(500, {'error': str(e)}, self._get_provider())

    def do_POST(self):
        """Handle POST requests"""
        path = urlparse(self.path).path
        provider = self._get_provider()

        try:
            # Check authentication
            if not self._check_auth(provider):
                return self._send_error(401, {'error': 'Unauthorized - Invalid API key'}, provider)

            # Check rate limit
            if not self._check_rate_limit(provider):
                return self._send_error(429, {'error': 'Rate limit exceeded'}, provider)

            content_length = int(self.headers.get('Content-Length', 0))
            body = self.rfile.read(content_length)
            data = json.loads(body.decode('utf-8')) if body else {}

            if path == '/exec':
                self._handle_exec(data, provider)
            elif path == '/file/read':
                self._handle_file_read(data, provider)
            elif path == '/file/write':
                self._handle_file_write(data, provider)
            elif path == '/file/delete':
                self._handle_file_delete(data, provider)
            elif path == '/git/status':
                self._handle_git_status(data, provider)
            elif path == '/git/log':
                self._handle_git_log(data, provider)
            elif path == '/git/commit':
                self._handle_git_commit(data, provider)
            elif path == '/git/push':
                self._handle_git_push(data, provider)
            elif path == '/git/pull':
                self._handle_git_pull(data, provider)
            elif path == '/service/status':
                self._handle_service_status(data, provider)
            elif path == '/service/restart':
                self._handle_service_restart(data, provider)
            elif path == '/service/logs':
                self._handle_service_logs(data, provider)
            elif path == '/npm/install':
                self._handle_npm_install(data, provider)
            elif path == '/npm/run':
                self._handle_npm_run(data, provider)
            else:
                self._send_error(404, {'error': 'Not found'}, provider)
        except Exception as e:
            logger.error(f"Error handling POST {path}: {str(e)}\n{traceback.format_exc()}", extra={'provider': provider})
            self._send_error(500, {'error': str(e)}, provider)

    def do_OPTIONS(self):
        """Handle CORS preflight"""
        self.send_response(200)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key')
        self.end_headers()

    def _get_provider(self):
        """Detect provider from headers or API key"""
        auth = self.headers.get('Authorization', '')
        api_key = self.headers.get('X-API-Key', '')

        if 'Bearer sk-openai' in auth or 'sk-openai' in api_key:
            return 'openai'
        elif 'Bearer sk-claude' in auth or 'sk-claude' in api_key:
            return 'claude'
        elif 'Bearer sk-gemini' in auth or 'sk-gemini' in api_key:
            return 'gemini'
        elif 'Bearer sk-ant-' in auth:
            return 'claude'
        elif auth.startswith('Bearer '):
            return 'openai'
        else:
            return 'unknown'

    def _check_auth(self, provider):
        """Check API authentication"""
        auth = self.headers.get('Authorization', '')
        api_key = self.headers.get('X-API-Key', '')

        if api_key:
            valid_key = API_KEYS.get(provider) or API_KEYS['default']
            return hmac.compare_digest(api_key, valid_key)

        if auth.startswith('Bearer '):
            token = auth[7:]
            valid_key = API_KEYS.get(provider) or API_KEYS['default']
            return hmac.compare_digest(token, valid_key)

        return False

    def _check_rate_limit(self, provider):
        """Check rate limiting"""
        now = time.time()
        minute_ago = now - 60
        hour_ago = now - 3600

        # Clean old entries
        rate_limits[provider]['minute'] = [t for t in rate_limits[provider]['minute'] if t > minute_ago]
        rate_limits[provider]['hour'] = [t for t in rate_limits[provider]['hour'] if t > hour_ago]

        # Check limits
        if len(rate_limits[provider]['minute']) >= MAX_REQUESTS_PER_MINUTE:
            return False
        if len(rate_limits[provider]['hour']) >= MAX_REQUESTS_PER_HOUR:
            return False

        # Add current request
        rate_limits[provider]['minute'].append(now)
        rate_limits[provider]['hour'].append(now)
        return True

    def _handle_index(self, provider):
        """Home page"""
        info = {
            'service': 'Universal MCP Server',
            'version': '1.0.0',
            'providers': ['openai', 'claude', 'gemini', 'custom'],
            'endpoints': {
                'GET': ['/status', '/health', '/logs', '/tools', '/providers'],
                'POST': ['/exec', '/file/*', '/git/*', '/service/*', '/npm/*']
            },
            'documentation': 'https://github.com/fredmourao-ai/site-shopvivaliz#mcp-server',
            'detected_provider': provider
        }
        self._send_json(200, info, provider)

    def _handle_status(self, provider):
        """System status"""
        try:
            hostname = subprocess.check_output('hostname', text=True).strip()
            whoami = subprocess.check_output('whoami', text=True).strip()

            status = {
                'status': 'online',
                'hostname': hostname,
                'user': whoami,
                'timestamp': datetime.utcnow().isoformat(),
                'provider': provider,
                'services': self._get_services_status()
            }
            self._send_json(200, status, provider)
            logger.info(f"Status requested", extra={'provider': provider})
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_health(self, provider):
        """System health"""
        health = {
            'healthy': True,
            'mcp': 'running',
            'timestamp': datetime.utcnow().isoformat(),
            'services': {}
        }

        for service in ['shopvivaliz-sync', 'shopvivaliz-mcp', 'ssh']:
            try:
                result = subprocess.run(['systemctl', 'is-active', service],
                                      capture_output=True, text=True, timeout=5)
                health['services'][service] = 'running' if result.returncode == 0 else 'stopped'
            except:
                health['services'][service] = 'unknown'

        self._send_json(200, health, provider)

    def _handle_logs(self, query, provider):
        """System logs"""
        lines = int(query.get('lines', ['20'])[0])
        try:
            logs = subprocess.check_output(
                f"journalctl -n {lines} --no-pager",
                shell=True, text=True, stderr=subprocess.STDOUT
            )
            self._send_json(200, {
                'logs': logs.split('\n'),
                'count': len(logs.split('\n')),
                'provider': provider
            }, provider)
        except Exception as e:
            self._send_json(200, {'logs': [], 'error': str(e)}, provider)

    def _handle_tools(self, provider):
        """List available tools"""
        tools = {
            'provider': provider,
            'tools': [
                {'name': 'exec', 'description': 'Execute shell command', 'method': 'POST'},
                {'name': 'file_read', 'description': 'Read file', 'method': 'POST'},
                {'name': 'file_write', 'description': 'Write file', 'method': 'POST'},
                {'name': 'file_delete', 'description': 'Delete file', 'method': 'POST'},
                {'name': 'git_status', 'description': 'Git status', 'method': 'POST'},
                {'name': 'git_log', 'description': 'Git log', 'method': 'POST'},
                {'name': 'git_commit', 'description': 'Git commit', 'method': 'POST'},
                {'name': 'git_push', 'description': 'Git push', 'method': 'POST'},
                {'name': 'git_pull', 'description': 'Git pull', 'method': 'POST'},
                {'name': 'service_status', 'description': 'Service status', 'method': 'POST'},
                {'name': 'service_restart', 'description': 'Restart service', 'method': 'POST'},
                {'name': 'service_logs', 'description': 'Service logs', 'method': 'POST'},
                {'name': 'npm_install', 'description': 'NPM install', 'method': 'POST'},
                {'name': 'npm_run', 'description': 'NPM run script', 'method': 'POST'},
            ]
        }
        self._send_json(200, tools, provider)

    def _handle_providers(self, provider):
        """List supported providers"""
        providers = {
            'supported': ['openai', 'claude', 'gemini', 'custom'],
            'current': provider,
            'documentation': {
                'openai': 'Use with OpenAI API custom headers',
                'claude': 'Use with Anthropic SDK or HTTP',
                'gemini': 'Use with Google Gemini API',
                'custom': 'Use default API key'
            }
        }
        self._send_json(200, providers, provider)

    def _handle_exec(self, data, provider):
        """Execute command"""
        cmd = data.get('cmd', '')
        timeout = int(data.get('timeout', 30))

        if not cmd:
            return self._send_error(400, {'error': 'cmd required'}, provider)

        try:
            result = subprocess.run(
                cmd, shell=True, capture_output=True, text=True, timeout=timeout
            )
            self._send_json(200, {
                'output': result.stdout,
                'error': result.stderr,
                'returncode': result.returncode,
                'status': 'success' if result.returncode == 0 else 'failed',
                'provider': provider
            }, provider)
            logger.info(f"Executed: {cmd[:50]}", extra={'provider': provider})
        except subprocess.TimeoutExpired:
            self._send_error(408, {'error': f'Timeout after {timeout}s'}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_file_read(self, data, provider):
        """Read file"""
        path = data.get('path', '')
        if not path:
            return self._send_error(400, {'error': 'path required'}, provider)

        try:
            with open(path, 'r') as f:
                content = f.read()
            self._send_json(200, {'path': path, 'content': content, 'size': len(content)}, provider)
            logger.info(f"Read: {path}", extra={'provider': provider})
        except FileNotFoundError:
            self._send_error(404, {'error': f'File not found'}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_file_write(self, data, provider):
        """Write file"""
        path = data.get('path', '')
        content = data.get('content', '')

        if not path:
            return self._send_error(400, {'error': 'path required'}, provider)

        try:
            os.makedirs(os.path.dirname(path) or '.', exist_ok=True)
            with open(path, 'w') as f:
                f.write(content)
            self._send_json(200, {'path': path, 'written': len(content), 'status': 'success'}, provider)
            logger.info(f"Wrote: {path}", extra={'provider': provider})
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_file_delete(self, data, provider):
        """Delete file"""
        path = data.get('path', '')
        if not path:
            return self._send_error(400, {'error': 'path required'}, provider)

        try:
            os.remove(path)
            self._send_json(200, {'path': path, 'status': 'deleted'}, provider)
            logger.info(f"Deleted: {path}", extra={'provider': provider})
        except FileNotFoundError:
            self._send_error(404, {'error': 'File not found'}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_git_status(self, data, provider):
        """Git status"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        try:
            output = subprocess.check_output(f'cd {path} && git status --porcelain', shell=True, text=True)
            self._send_json(200, {'path': path, 'status': output.strip(), 'clean': len(output.strip()) == 0}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_git_log(self, data, provider):
        """Git log"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        lines = int(data.get('lines', 10))
        try:
            output = subprocess.check_output(f'cd {path} && git log --oneline -n {lines}', shell=True, text=True)
            self._send_json(200, {'path': path, 'log': output.strip().split('\n')}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_git_commit(self, data, provider):
        """Git commit"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        message = data.get('message', 'Auto-commit from MCP')
        try:
            subprocess.run(f'cd {path} && git add -A', shell=True, check=True)
            output = subprocess.check_output(f'cd {path} && git commit -m "{message}"', shell=True, text=True, stderr=subprocess.STDOUT)
            self._send_json(200, {'message': message, 'output': output.strip(), 'status': 'committed'}, provider)
            logger.info(f"Committed: {message[:50]}", extra={'provider': provider})
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_git_push(self, data, provider):
        """Git push"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        remote = data.get('remote', 'origin')
        branch = data.get('branch', 'main')
        try:
            output = subprocess.check_output(f'cd {path} && git push {remote} {branch}', shell=True, text=True, stderr=subprocess.STDOUT)
            self._send_json(200, {'remote': remote, 'branch': branch, 'status': 'pushed'}, provider)
            logger.info(f"Pushed to {remote}/{branch}", extra={'provider': provider})
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_git_pull(self, data, provider):
        """Git pull"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        try:
            output = subprocess.check_output(f'cd {path} && git pull', shell=True, text=True, stderr=subprocess.STDOUT)
            self._send_json(200, {'status': 'pulled', 'output': output.strip()}, provider)
            logger.info(f"Pulled repository", extra={'provider': provider})
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_service_status(self, data, provider):
        """Service status"""
        service = data.get('service', '')
        if not service:
            return self._send_error(400, {'error': 'service required'}, provider)

        try:
            result = subprocess.run(['systemctl', 'is-active', service], capture_output=True, text=True)
            status = 'running' if result.returncode == 0 else 'stopped'
            self._send_json(200, {'service': service, 'status': status}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_service_restart(self, data, provider):
        """Restart service"""
        service = data.get('service', '')
        if not service:
            return self._send_error(400, {'error': 'service required'}, provider)

        try:
            subprocess.run(['sudo', 'systemctl', 'restart', service], capture_output=True, text=True, timeout=10)
            self._send_json(200, {'service': service, 'status': 'restarted'}, provider)
            logger.info(f"Restarted service: {service}", extra={'provider': provider})
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_service_logs(self, data, provider):
        """Service logs"""
        service = data.get('service', '')
        lines = int(data.get('lines', 20))
        if not service:
            return self._send_error(400, {'error': 'service required'}, provider)

        try:
            output = subprocess.check_output(f'journalctl -u {service} -n {lines} --no-pager', shell=True, text=True)
            self._send_json(200, {'service': service, 'logs': output.split('\n')}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_npm_install(self, data, provider):
        """NPM install"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        try:
            output = subprocess.check_output(f'cd {path} && npm install', shell=True, text=True, timeout=300)
            self._send_json(200, {'status': 'installed', 'output': output[-500:]}, provider)
            logger.info(f"NPM install completed", extra={'provider': provider})
        except subprocess.TimeoutExpired:
            self._send_error(408, {'error': 'NPM install timeout'}, provider)
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _handle_npm_run(self, data, provider):
        """NPM run script"""
        path = data.get('path', '/home/shopvivaliz/site-shopvivaliz')
        script = data.get('script', '')
        if not script:
            return self._send_error(400, {'error': 'script required'}, provider)

        try:
            output = subprocess.check_output(f'cd {path} && npm run {script}', shell=True, text=True, timeout=300)
            self._send_json(200, {'script': script, 'status': 'success', 'output': output[-500:]}, provider)
            logger.info(f"NPM run {script}", extra={'provider': provider})
        except Exception as e:
            self._send_error(500, {'error': str(e)}, provider)

    def _get_services_status(self):
        """Get services status"""
        services = {}
        for service in ['shopvivaliz-mcp', 'shopvivaliz-sync', 'ssh']:
            try:
                result = subprocess.run(['systemctl', 'is-active', service], capture_output=True, text=True, timeout=5)
                services[service] = 'running' if result.returncode == 0 else 'stopped'
            except:
                services[service] = 'unknown'
        return services

    def _send_json(self, code, data, provider):
        """Send JSON response"""
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def _send_error(self, code, error, provider):
        """Send error response"""
        self._send_json(code, error, provider)

    def log_message(self, format, *args):
        """Suppress default logging"""
        pass


def main():
    """Start Universal MCP Server"""
    host = '0.0.0.0'
    port = 5556

    server = HTTPServer((host, port), MCPUniversalHandler)
    logger.info(f"Universal MCP Server listening on {host}:{port}", extra={'provider': 'system'})
    logger.info(f"Supported providers: OpenAI, Claude, Gemini, Custom", extra={'provider': 'system'})

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        logger.info("Server shutdown", extra={'provider': 'system'})
        server.shutdown()


if __name__ == '__main__':
    main()
