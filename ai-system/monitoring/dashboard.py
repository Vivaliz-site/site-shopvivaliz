"""
Monitoring Dashboard for Hybrid AI System
Shows costs, agents, tasks, and system status
"""

import json
import sqlite3
from datetime import datetime
from typing import Dict, Any, List

try:
    from fastapi import FastAPI
    from fastapi.responses import HTMLResponse, JSONResponse
    from fastapi.staticfiles import StaticFiles
except ImportError:
    FastAPI = None
    HTMLResponse = None

class Dashboard:
    def __init__(self, repo_path: str = "C:/site-shopvivaliz"):
        self.repo_path = repo_path
        self.db_path = f"{repo_path}/ai-system/memory/orchestrator.db"

        if FastAPI:
            self.app = FastAPI(title="ShopVivaliz AI System Dashboard")
            self._setup_routes()

    def _setup_routes(self):
        """Setup API routes"""

        @self.app.get("/")
        def home():
            return HTMLResponse(self._render_html())

        @self.app.get("/api/status")
        def get_status():
            return self._get_system_status()

        @self.app.get("/api/costs")
        def get_costs():
            return self._get_cost_report()

        @self.app.get("/api/tasks")
        def get_tasks():
            return self._get_recent_tasks()

        @self.app.get("/api/agents")
        def get_agents():
            return self._get_agent_status()

    def _get_system_status(self) -> Dict:
        """Get overall system status"""
        try:
            conn = sqlite3.connect(self.db_path)
            c = conn.cursor()

            c.execute("SELECT COUNT(*) FROM tasks WHERE status='pending'")
            pending = c.fetchone()[0]

            c.execute("SELECT COUNT(*) FROM tasks WHERE status='completed'")
            completed = c.fetchone()[0]

            c.execute("SELECT COUNT(*) FROM agent_logs WHERE DATE(timestamp) = DATE('now')")
            today_actions = c.fetchone()[0]

            conn.close()

            return {
                "timestamp": datetime.now().isoformat(),
                "pending_tasks": pending,
                "completed_tasks": completed,
                "actions_today": today_actions,
                "ollama_available": self._check_ollama(),
                "memory_status": "ready",
            }
        except Exception as e:
            return {"error": str(e)}

    def _get_cost_report(self) -> Dict:
        """Get cost tracking report"""
        try:
            conn = sqlite3.connect(self.db_path)
            c = conn.cursor()

            # Daily cost
            c.execute('''
                SELECT SUM(cost) FROM api_calls
                WHERE DATE(timestamp) = DATE('now')
            ''')
            daily_cost = c.fetchone()[0] or 0

            # Weekly cost
            c.execute('''
                SELECT SUM(cost) FROM api_calls
                WHERE DATE(timestamp) >= DATE('now', '-7 days')
            ''')
            weekly_cost = c.fetchone()[0] or 0

            # Cost by provider
            c.execute('''
                SELECT provider, SUM(cost), COUNT(*) FROM api_calls
                WHERE DATE(timestamp) = DATE('now')
                GROUP BY provider
            ''')

            by_provider = {}
            for row in c.fetchall():
                by_provider[row[0]] = {"cost": row[1], "calls": row[2]}

            conn.close()

            return {
                "daily_cost": round(daily_cost, 2),
                "weekly_cost": round(weekly_cost, 2),
                "daily_limit": 10.0,
                "remaining_today": round(max(0, 10.0 - daily_cost), 2),
                "by_provider": by_provider,
            }
        except Exception as e:
            return {"error": str(e)}

    def _get_recent_tasks(self, limit: int = 10) -> List[Dict]:
        """Get recent tasks"""
        try:
            conn = sqlite3.connect(self.db_path)
            c = conn.cursor()

            c.execute('''
                SELECT task_id, name, complexity, status, provider, cost, created_at
                FROM tasks
                ORDER BY created_at DESC
                LIMIT ?
            ''', (limit,))

            tasks = []
            for row in c.fetchall():
                tasks.append({
                    "task_id": row[0],
                    "name": row[1],
                    "complexity": row[2],
                    "status": row[3],
                    "provider": row[4],
                    "cost": row[5],
                    "created_at": row[6],
                })

            conn.close()
            return tasks
        except Exception as e:
            return [{"error": str(e)}]

    def _get_agent_status(self) -> List[Dict]:
        """Get status of all agents"""
        from .agents import AGENTS

        agents = []
        try:
            conn = sqlite3.connect(self.db_path)
            c = conn.cursor()

            for agent_name, agent in AGENTS.items():
                c.execute('''
                    SELECT COUNT(*) FROM agent_logs
                    WHERE agent_name = ? AND DATE(timestamp) = DATE('now')
                ''', (agent_name,))

                actions_today = c.fetchone()[0]

                c.execute('''
                    SELECT timestamp FROM agent_logs
                    WHERE agent_name = ?
                    ORDER BY timestamp DESC
                    LIMIT 1
                ''', (agent_name,))

                last_action = c.fetchone()

                agents.append({
                    "name": agent.name,
                    "role": agent.role.value,
                    "actions_today": actions_today,
                    "last_action": last_action[0] if last_action else None,
                    "model": agent.primary_model,
                })

            conn.close()
        except Exception as e:
            agents.append({"error": str(e)})

        return agents

    def _check_ollama(self) -> bool:
        """Check if Ollama is running"""
        try:
            import requests
            response = requests.get("http://localhost:11434/api/tags", timeout=2)
            return response.status_code == 200
        except Exception:
            return False

    def _render_html(self) -> str:
        """Render dashboard HTML"""
        return """
        <!DOCTYPE html>
        <html>
        <head>
            <title>ShopVivaliz AI System</title>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                       background: #0f172a; color: #e2e8f0; padding: 20px; }
                .container { max-width: 1400px; margin: 0 auto; }
                h1 { margin-bottom: 30px; font-size: 2em; }
                .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
                .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 20px; }
                .metric { font-size: 2.5em; font-weight: bold; color: #3b82f6; }
                .label { font-size: 0.9em; color: #94a3b8; margin-top: 10px; }
                .status { padding: 10px 15px; border-radius: 4px; background: #10b981; }
                .error { background: #ef4444; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                th, td { padding: 10px; text-align: left; border-bottom: 1px solid #334155; }
                th { background: #0f172a; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>🤖 ShopVivaliz AI System</h1>

                <div class="grid">
                    <div class="card">
                        <div class="metric" id="pending-tasks">-</div>
                        <div class="label">Pending Tasks</div>
                    </div>
                    <div class="card">
                        <div class="metric" id="daily-cost">-</div>
                        <div class="label">Daily Cost ($)</div>
                    </div>
                    <div class="card">
                        <div class="metric" id="remaining">-</div>
                        <div class="label">Budget Remaining ($)</div>
                    </div>
                    <div class="card">
                        <div class="metric" id="ollama-status">-</div>
                        <div class="label">Ollama Status</div>
                    </div>
                </div>

                <div class="card" style="margin-top: 20px;">
                    <h2>Recent Tasks</h2>
                    <table id="tasks-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Status</th>
                                <th>Provider</th>
                                <th>Cost</th>
                            </tr>
                        </thead>
                        <tbody id="tasks-body"></tbody>
                    </table>
                </div>

                <div class="card" style="margin-top: 20px;">
                    <h2>Active Agents</h2>
                    <table id="agents-table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Role</th>
                                <th>Today</th>
                                <th>Model</th>
                            </tr>
                        </thead>
                        <tbody id="agents-body"></tbody>
                    </table>
                </div>
            </div>

            <script>
                async function updateDashboard() {
                    try {
                        const status = await fetch('/api/status').then(r => r.json());
                        const costs = await fetch('/api/costs').then(r => r.json());
                        const tasks = await fetch('/api/tasks').then(r => r.json());
                        const agents = await fetch('/api/agents').then(r => r.json());

                        document.getElementById('pending-tasks').textContent = status.pending_tasks || 0;
                        document.getElementById('daily-cost').textContent = '$' + costs.daily_cost;
                        document.getElementById('remaining').textContent = '$' + costs.remaining_today;
                        document.getElementById('ollama-status').innerHTML = (status.ollama_available ? '✅ Running' : '❌ Offline');

                        const tbody = document.getElementById('tasks-body');
                        tbody.innerHTML = '';
                        tasks.forEach(t => {
                            const row = tbody.insertRow();
                            row.innerHTML = `<td>${t.name}</td>
                                           <td>${t.status}</td>
                                           <td>${t.provider || '-'}</td>
                                           <td>$${(t.cost || 0).toFixed(3)}</td>`;
                        });

                        const abody = document.getElementById('agents-body');
                        abody.innerHTML = '';
                        agents.forEach(a => {
                            const row = abody.insertRow();
                            row.innerHTML = `<td>${a.name}</td>
                                           <td>${a.role}</td>
                                           <td>${a.actions_today}</td>
                                           <td>${a.model}</td>`;
                        });
                    } catch (e) {
                        console.error('Dashboard update failed:', e);
                    }
                }

                updateDashboard();
                setInterval(updateDashboard, 10000);
            </script>
        </body>
        </html>
        """

    def run(self, host: str = "127.0.0.1", port: int = 8000):
        """Start dashboard server"""
        if not FastAPI:
            print("FastAPI not installed. Run: pip install fastapi uvicorn")
            return

        print(f"Starting dashboard on http://{host}:{port}")
        import uvicorn
        uvicorn.run(self.app, host=host, port=port)

if __name__ == "__main__":
    dashboard = Dashboard()
    dashboard.run()
