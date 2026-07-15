#!/usr/bin/env python3
"""
ShopVivaliz Database - Histórico e Rastreamento

SQLite database para rastrear:
- Execuções de syncs
- Tarefas completadas
- Eventos de sistema
- Métricas de performance
"""

import sqlite3
import json
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Any, Optional

DB_PATH = Path(__file__).parent.parent / "shopvivaliz.db"


class ShopVivalizDB:
    """Database manager para ShopVivaliz."""

    def __init__(self, db_path: Path = DB_PATH):
        """Inicializar database."""
        self.db_path = db_path
        self.init_db()

    def init_db(self):
        """Criar tabelas se não existirem."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()

            # Tabela: Syncs
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS syncs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    environment TEXT NOT NULL,
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status TEXT,  -- success, failed, partial
                    commits_pulled INTEGER DEFAULT 0,
                    commits_pushed INTEGER DEFAULT 0,
                    files_changed INTEGER DEFAULT 0,
                    error_message TEXT,
                    duration_seconds FLOAT,
                    retry_count INTEGER DEFAULT 0
                )
            """)

            # Tabela: Tasks
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS tasks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id TEXT UNIQUE NOT NULL,
                    title TEXT,
                    status TEXT,  -- pending, running, done, failed
                    priority TEXT,  -- high, medium, low
                    assigned_to TEXT,
                    created_at DATETIME,
                    started_at DATETIME,
                    completed_at DATETIME,
                    error_message TEXT
                )
            """)

            # Tabela: Events
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                    environment TEXT,
                    event_type TEXT,  -- sync, task, error, alert
                    severity TEXT,  -- info, warning, error
                    message TEXT,
                    metadata TEXT  -- JSON
                )
            """)

            # Tabela: Metrics
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS metrics (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                    environment TEXT,
                    metric_name TEXT,
                    value REAL,
                    unit TEXT
                )
            """)

            conn.commit()

    def record_sync(
        self,
        environment: str,
        status: str,
        commits_pulled: int = 0,
        commits_pushed: int = 0,
        files_changed: int = 0,
        error_message: Optional[str] = None,
        duration_seconds: Optional[float] = None,
        retry_count: int = 0,
    ) -> int:
        """Registrar execução de sync."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()
            cursor.execute("""
                INSERT INTO syncs (
                    environment, status, commits_pulled, commits_pushed,
                    files_changed, error_message, duration_seconds, retry_count
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                environment, status, commits_pulled, commits_pushed,
                files_changed, error_message, duration_seconds, retry_count
            ))
            conn.commit()
            return cursor.lastrowid

    def record_task(
        self,
        task_id: str,
        title: str,
        status: str,
        priority: str,
        assigned_to: str,
        created_at: Optional[str] = None,
    ) -> int:
        """Registrar tarefa."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()
            cursor.execute("""
                INSERT OR REPLACE INTO tasks (
                    task_id, title, status, priority, assigned_to, created_at
                ) VALUES (?, ?, ?, ?, ?, ?)
            """, (
                task_id, title, status, priority, assigned_to,
                created_at or datetime.now().isoformat()
            ))
            conn.commit()
            return cursor.lastrowid

    def update_task_status(
        self,
        task_id: str,
        new_status: str,
        error_message: Optional[str] = None,
    ) -> bool:
        """Atualizar status de tarefa."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()

            if new_status == "running":
                cursor.execute(
                    "UPDATE tasks SET status = ?, started_at = ? WHERE task_id = ?",
                    (new_status, datetime.now().isoformat(), task_id)
                )
            elif new_status in ["done", "failed"]:
                cursor.execute(
                    "UPDATE tasks SET status = ?, completed_at = ?, error_message = ? WHERE task_id = ?",
                    (new_status, datetime.now().isoformat(), error_message, task_id)
                )
            else:
                cursor.execute(
                    "UPDATE tasks SET status = ? WHERE task_id = ?",
                    (new_status, task_id)
                )

            conn.commit()
            return cursor.rowcount > 0

    def record_event(
        self,
        event_type: str,
        message: str,
        environment: Optional[str] = None,
        severity: str = "info",
        metadata: Optional[Dict] = None,
    ) -> int:
        """Registrar evento do sistema."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()
            cursor.execute("""
                INSERT INTO events (
                    environment, event_type, severity, message, metadata
                ) VALUES (?, ?, ?, ?, ?)
            """, (
                environment,
                event_type,
                severity,
                message,
                json.dumps(metadata or {})
            ))
            conn.commit()
            return cursor.lastrowid

    def get_syncs(self, environment: Optional[str] = None, limit: int = 50) -> List[Dict]:
        """Obter histórico de syncs."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()

            if environment:
                cursor.execute("""
                    SELECT * FROM syncs
                    WHERE environment = ?
                    ORDER BY timestamp DESC
                    LIMIT ?
                """, (environment, limit))
            else:
                cursor.execute("""
                    SELECT * FROM syncs
                    ORDER BY timestamp DESC
                    LIMIT ?
                """, (limit,))

            columns = [d[0] for d in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def get_tasks(self, status: Optional[str] = None) -> List[Dict]:
        """Obter tarefas."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()

            if status:
                cursor.execute(
                    "SELECT * FROM tasks WHERE status = ? ORDER BY created_at DESC",
                    (status,)
                )
            else:
                cursor.execute("SELECT * FROM tasks ORDER BY created_at DESC")

            columns = [d[0] for d in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def get_events(self, environment: Optional[str] = None, limit: int = 100) -> List[Dict]:
        """Obter eventos do sistema."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()

            if environment:
                cursor.execute("""
                    SELECT * FROM events
                    WHERE environment = ?
                    ORDER BY timestamp DESC
                    LIMIT ?
                """, (environment, limit))
            else:
                cursor.execute("""
                    SELECT * FROM events
                    ORDER BY timestamp DESC
                    LIMIT ?
                """, (limit,))

            columns = [d[0] for d in cursor.description]
            return [dict(zip(columns, row)) for row in cursor.fetchall()]

    def get_stats(self, days: int = 7) -> Dict[str, Any]:
        """Obter estatísticas do sistema."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()

            # Total de syncs
            cursor.execute("""
                SELECT COUNT(*) FROM syncs
                WHERE datetime(timestamp) >= datetime('now', ? || ' days')
            """, (f"-{days}",))
            total_syncs = cursor.fetchone()[0]

            # Syncs bem-sucedidas
            cursor.execute("""
                SELECT COUNT(*) FROM syncs
                WHERE status = 'success'
                AND datetime(timestamp) >= datetime('now', ? || ' days')
            """, (f"-{days}",))
            successful_syncs = cursor.fetchone()[0]

            # Tarefas completadas
            cursor.execute("""
                SELECT COUNT(*) FROM tasks
                WHERE status = 'done'
                AND datetime(completed_at) >= datetime('now', ? || ' days')
            """, (f"-{days}",))
            completed_tasks = cursor.fetchone()[0]

            # Eventos de erro
            cursor.execute("""
                SELECT COUNT(*) FROM events
                WHERE severity = 'error'
                AND datetime(timestamp) >= datetime('now', ? || ' days')
            """, (f"-{days}",))
            error_events = cursor.fetchone()[0]

            sync_rate = (successful_syncs / total_syncs * 100) if total_syncs > 0 else 0

            return {
                "period_days": days,
                "total_syncs": total_syncs,
                "successful_syncs": successful_syncs,
                "sync_success_rate": f"{sync_rate:.1f}%",
                "completed_tasks": completed_tasks,
                "error_events": error_events,
            }

    def cleanup_old_data(self, days: int = 30):
        """Limpar dados antigos."""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()

            cursor.execute("""
                DELETE FROM syncs
                WHERE datetime(timestamp) < datetime('now', ? || ' days')
            """, (f"-{days}",))

            cursor.execute("""
                DELETE FROM events
                WHERE datetime(timestamp) < datetime('now', ? || ' days')
            """, (f"-{days}",))

            conn.commit()


# CLI para debugging
if __name__ == "__main__":
    db = ShopVivalizDB()

    print("📊 ShopVivaliz Database Stats")
    print("=" * 50)

    stats = db.get_stats(days=7)
    for key, value in stats.items():
        print(f"{key}: {value}")

    print("\n📋 Últimos Syncs:")
    for sync in db.get_syncs(limit=5):
        print(f"  - {sync['environment']}: {sync['status']} ({sync['timestamp']})")

    print("\n📌 Tarefas Pendentes:")
    for task in db.get_tasks(status="pending"):
        print(f"  - {task['title']} ({task['priority']})")
