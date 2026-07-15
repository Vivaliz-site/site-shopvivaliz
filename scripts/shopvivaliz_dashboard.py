#!/usr/bin/env python3
"""
ShopVivaliz Dashboard - Web Interface

Dashboard em tempo real para monitorar todas as estações.
"""

import json
import os
from datetime import datetime
from pathlib import Path
from flask import Flask, render_template_string, jsonify
import requests

REPO_ROOT = Path(__file__).parent.parent
MCP_SERVERS_FILE = REPO_ROOT / "mcp-servers.json"


def create_app():
    """Criar aplicação Flask com dashboard."""
    app = Flask(__name__)

    @app.route("/")
    def index():
        """Dashboard HTML."""
        return render_template_string(DASHBOARD_HTML)

    @app.route("/api/status")
    def api_status():
        """API: Status de todas as estações."""
        config = load_config()
        servers = config.get("servers", {})

        status_data = {}
        for name, server_config in servers.items():
            try:
                response = requests.get(
                    f"{server_config['url']}/health",
                    timeout=3
                )
                health = response.json()
                status = "🟢 Online"
            except:
                health = {}
                status = "🔴 Offline"

            status_data[name] = {
                "status": status,
                "url": server_config["url"],
                "environment": server_config.get("environment", "?"),
                "enabled": server_config.get("enabled", False),
                "health": health,
            }

        return jsonify(status_data)

    @app.route("/api/tasks")
    def api_tasks():
        """API: Tarefas pendentes."""
        tasks_file = REPO_ROOT / "tasks-queue.json"
        if tasks_file.exists():
            with open(tasks_file) as f:
                data = json.load(f)
                return jsonify(data.get("tasks", []))
        return jsonify([])

    @app.route("/api/logs")
    def api_logs():
        """API: Últimos logs."""
        logs_dir = REPO_ROOT / "logs"
        logs = {}

        for log_file in sorted(logs_dir.glob("*.log"), reverse=True)[:5]:
            try:
                with open(log_file) as f:
                    lines = f.readlines()
                    logs[log_file.name] = "".join(lines[-20:])
            except:
                pass

        return jsonify(logs)

    return app


def load_config():
    """Carregar configuração de MCP Servers."""
    if MCP_SERVERS_FILE.exists():
        with open(MCP_SERVERS_FILE) as f:
            return json.load(f)
    return {"servers": {}}


DASHBOARD_HTML = """
<!DOCTYPE html>
<html>
<head>
    <title>ShopVivaliz Dashboard</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            color: white;
            margin-bottom: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card h2 {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .server-item {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .server-item:last-child {
            border-bottom: none;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.9em;
            font-weight: bold;
        }
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        .status-offline {
            background: #f8d7da;
            color: #721c24;
        }
        .task-item {
            padding: 10px;
            margin-bottom: 10px;
            background: #f9f9f9;
            border-left: 4px solid #667eea;
            border-radius: 4px;
        }
        .task-item .priority {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.8em;
            font-weight: bold;
            margin-left: 10px;
        }
        .priority-high { background: #ffe0e0; color: #c41e3a; }
        .priority-medium { background: #fff3cd; color: #856404; }
        .priority-low { background: #d1ecf1; color: #0c5460; }
        .refresh-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 10px;
            width: 100%;
            transition: background 0.3s ease;
        }
        .refresh-btn:hover {
            background: #5568d3;
        }
        .logs-section {
            background: #1e1e1e;
            color: #00ff00;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            max-height: 200px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .footer {
            color: white;
            text-align: center;
            margin-top: 30px;
            font-size: 0.9em;
        }
        .loading {
            text-align: center;
            color: white;
            padding: 20px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            display: inline-block;
            animation: spin 1s linear infinite;
            font-size: 1.5em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🌉 ShopVivaliz Dashboard</h1>
            <p>Monitoramento em Tempo Real do Sistema</p>
        </div>

        <div class="grid">
            <!-- Status das Estações -->
            <div class="card">
                <h2>🟢 Estações</h2>
                <div id="servers-container">
                    <div class="loading">
                        <span class="spinner">⚡</span> Carregando...
                    </div>
                </div>
                <button class="refresh-btn" onclick="refreshStatus()">Atualizar</button>
            </div>

            <!-- Tarefas Pendentes -->
            <div class="card">
                <h2>📌 Tarefas Pendentes</h2>
                <div id="tasks-container">
                    <div class="loading">
                        <span class="spinner">⚡</span> Carregando...
                    </div>
                </div>
                <button class="refresh-btn" onclick="refreshTasks()">Atualizar</button>
            </div>

            <!-- Logs Recentes -->
            <div class="card">
                <h2>📝 Logs Recentes</h2>
                <div id="logs-container">
                    <div class="loading">
                        <span class="spinner">⚡</span> Carregando...
                    </div>
                </div>
                <button class="refresh-btn" onclick="refreshLogs()">Atualizar</button>
            </div>
        </div>

        <div class="footer">
            <p>Atualizado em tempo real • ShopVivaliz v1.0</p>
        </div>
    </div>

    <script>
        async function refreshStatus() {
            try {
                const response = await fetch('/api/status');
                const data = await response.json();

                let html = '';
                for (const [name, info] of Object.entries(data)) {
                    const badge = info.status.includes('Online')
                        ? `<span class="status-badge status-online">${info.status}</span>`
                        : `<span class="status-badge status-offline">${info.status}</span>`;

                    html += `
                        <div class="server-item">
                            <strong>${name}</strong>
                            ${badge}
                        </div>
                    `;
                }
                document.getElementById('servers-container').innerHTML = html || '<p>Nenhuma estação encontrada</p>';
            } catch (e) {
                document.getElementById('servers-container').innerHTML = '<p style="color:red;">Erro ao carregar</p>';
            }
        }

        async function refreshTasks() {
            try {
                const response = await fetch('/api/tasks');
                const tasks = await response.json();

                if (!tasks.length) {
                    document.getElementById('tasks-container').innerHTML = '<p>Nenhuma tarefa pendente</p>';
                    return;
                }

                let html = '';
                for (const task of tasks.slice(0, 5)) {
                    const priorityClass = `priority-${task.priority || 'medium'}`;
                    html += `
                        <div class="task-item">
                            <strong>${task.title || task.task_id}</strong>
                            <span class="priority ${priorityClass}">
                                ${(task.priority || 'medium').toUpperCase()}
                            </span>
                            <div style="font-size: 0.85em; color: #666; margin-top: 5px;">
                                Status: ${task.status}
                            </div>
                        </div>
                    `;
                }
                document.getElementById('tasks-container').innerHTML = html;
            } catch (e) {
                document.getElementById('tasks-container').innerHTML = '<p style="color:red;">Erro ao carregar</p>';
            }
        }

        async function refreshLogs() {
            try {
                const response = await fetch('/api/logs');
                const logs = await response.json();

                let html = '';
                for (const [filename, content] of Object.entries(logs).slice(0, 3)) {
                    html += `<strong>${filename}</strong><br/>`;
                    html += content.replace(/\n/g, '<br/>');
                    html += '<br/><hr/>';
                }
                document.getElementById('logs-container').innerHTML =
                    `<div class="logs-section">${html || 'Nenhum log disponível'}</div>`;
            } catch (e) {
                document.getElementById('logs-container').innerHTML = '<p style="color:red;">Erro ao carregar</p>';
            }
        }

        // Atualizar automaticamente a cada 5 segundos
        setInterval(() => {
            refreshStatus();
            refreshTasks();
            refreshLogs();
        }, 5000);

        // Carregar ao iniciar
        refreshStatus();
        refreshTasks();
        refreshLogs();
    </script>
</body>
</html>
"""

if __name__ == "__main__":
    app = create_app()
    app.run(debug=True)
