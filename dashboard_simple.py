import json
import sqlite3
from datetime import datetime
from typing import Dict, Any, List

class DashboardSimple:
    def __init__(self, repo_path: str = "C:/site-shopvivaliz"):
        self.db_path = f"{repo_path}/ai-system/memory/orchestrator.db"

    def get_system_status(self) -> Dict:
        try:
            conn = sqlite3.connect(self.db_path)
            c = conn.cursor()

            c.execute("SELECT COUNT(*) FROM tasks WHERE status='pending'")
            pending = c.fetchone()[0]

            c.execute("SELECT COUNT(*) FROM tasks WHERE status='completed'")
            completed = c.fetchone()[0]

            conn.close()

            return {
                "timestamp": datetime.now().isoformat(),
                "pending_tasks": pending,
                "completed_tasks": completed,
                "daily_cost": 0.0,
                "status": "OPERACIONAL"
            }
        except Exception as e:
            return {"error": str(e)}

    def print_status(self):
        status = self.get_system_status()
        print("\n" + "="*60)
        print("🤖 SHOPVIVALIZ HYBRID AI SYSTEM - DASHBOARD")
        print("="*60)
        print(f"\n✅ Tarefas Pendentes: {status.get('pending_tasks', 0)}")
        print(f"✅ Tarefas Completadas: {status.get('completed_tasks', 0)}")
        print(f"💰 Custo Diário: \ / \.00")
        print(f"📊 Status: {status.get('status', 'UNKNOWN')}")
        print(f"⏰ {status.get('timestamp', 'N/A')}")
        print("\n" + "="*60)
        print("🌐 Acesse: http://127.0.0.1:8001")
        print("="*60 + "\n")

if __name__ == "__main__":
    dash = DashboardSimple()
    dash.print_status()
    print("✅ Dashboard simples pronto!")
    print("Monitore o sistema via:")
    print("  • http://127.0.0.1:8001")
    print("  • logs/ai-orchestrator.log")
    print("  • tasks-queue.json")
