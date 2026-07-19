"""
ShopVivaliz Hybrid AI Orchestrator
- Route tasks between local AI (Ollama) and paid APIs (GPT, Claude, Gemini)
- Cost control and tracking
- Agent coordination
- Memory management
"""

import json
import os
import sqlite3
from datetime import datetime, timedelta
from typing import Optional, Dict, Any, List
from enum import Enum
import hashlib

class TaskComplexity(Enum):
    SIMPLE = "simple"
    MEDIUM = "medium"
    COMPLEX = "complex"
    CRITICAL = "critical"

class ModelProvider(Enum):
    OLLAMA = "ollama"
    OPENAI = "openai"
    ANTHROPIC = "anthropic"
    GOOGLE = "google"

class AIOrchestrator:
    def __init__(self, repo_path: str = "C:/site-shopvivaliz"):
        self.repo_path = repo_path
        self.db_path = f"{repo_path}/ai-system/memory/orchestrator.db"
        self.config_path = f"{repo_path}/ai-system/config/orchestrator.json"

        # Cost limits
        self.cost_limits = {
            "daily": float(os.getenv("AI_DAILY_BUDGET_USD", "1.0")),
            "weekly": float(os.getenv("AI_WEEKLY_BUDGET_USD", "7.0")),
            "monthly": float(os.getenv("AI_MONTHLY_BUDGET_USD", "30.0")),
        }

        self._init_database()
        self._load_config()

    def _init_database(self):
        os.makedirs(os.path.dirname(self.db_path), exist_ok=True)
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        c.execute('''CREATE TABLE IF NOT EXISTS tasks (
            task_id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            complexity TEXT NOT NULL,
            status TEXT,
            provider TEXT,
            model TEXT,
            cost REAL,
            tokens_used INTEGER,
            created_at TIMESTAMP,
            completed_at TIMESTAMP,
            result TEXT
        )''')

        c.execute('''CREATE TABLE IF NOT EXISTS api_calls (
            call_id TEXT PRIMARY KEY,
            provider TEXT,
            model TEXT,
            tokens_input INTEGER,
            tokens_output INTEGER,
            cost REAL,
            timestamp TIMESTAMP
        )''')

        c.execute('''CREATE TABLE IF NOT EXISTS agent_logs (
            id INTEGER PRIMARY KEY,
            agent_name TEXT,
            task_id TEXT,
            action TEXT,
            result TEXT,
            timestamp TIMESTAMP
        )''')

        conn.commit()
        conn.close()

    def _load_config(self):
        if os.path.exists(self.config_path):
            with open(self.config_path, 'r') as f:
                self.config = json.load(f)
        else:
            self.config = {
                "local_model": "mistral:7b-instruct-q4_K_M",
                "ollama_endpoint": "http://localhost:11434",
                "economy_mode": os.getenv("AI_ECONOMY_MODE", "true").lower() != "false",
                "openai_model": os.getenv("OPENAI_MODEL", "gpt-4o-mini"),
                "anthropic_model": os.getenv("ANTHROPIC_MODEL", "claude-haiku-4-5-20251001"),
                "google_model": os.getenv("GEMINI_MODEL") or os.getenv("GOOGLE_MODEL", "gemini-2.5-flash"),
                "openai_key": os.getenv("OPENAI_API_KEY"),
                "anthropic_key": os.getenv("ANTHROPIC_API_KEY"),
                "google_key": os.getenv("GOOGLE_API_KEY"),
            }
            self._save_config()

    def _save_config(self):
        os.makedirs(os.path.dirname(self.config_path), exist_ok=True)
        with open(self.config_path, 'w') as f:
            json.dump(self.config, f, indent=2)

    def classify_task(self, task_description: str, context_size: int = 0) -> TaskComplexity:
        keywords_simple = ["list", "search", "read", "find", "grep", "status", "check"]
        keywords_medium = ["refactor", "test", "document", "lint", "format", "optimize"]
        keywords_complex = ["architecture", "design", "debug", "security", "performance", "api"]
        keywords_critical = ["deploy", "delete", "modify price", "customer", "payment", "refund"]

        desc_lower = task_description.lower()

        if any(kw in desc_lower for kw in keywords_critical):
            return TaskComplexity.CRITICAL

        if any(kw in desc_lower for kw in keywords_complex) or context_size > 50000:
            return TaskComplexity.COMPLEX

        if any(kw in desc_lower for kw in keywords_medium):
            return TaskComplexity.MEDIUM

        return TaskComplexity.SIMPLE

    def recommend_provider(self, complexity: TaskComplexity, remaining_budget: float) -> ModelProvider:
        economy_mode = self.config.get("economy_mode", True)

        if economy_mode:
            if complexity in (TaskComplexity.CRITICAL, TaskComplexity.COMPLEX):
                return ModelProvider.OPENAI if remaining_budget >= 0.05 else ModelProvider.OLLAMA
            return ModelProvider.OLLAMA

        if complexity == TaskComplexity.CRITICAL:
            return ModelProvider.ANTHROPIC

        if complexity == TaskComplexity.COMPLEX:
            if remaining_budget > 2.0:
                return ModelProvider.OPENAI
            else:
                return ModelProvider.OLLAMA

        if complexity == TaskComplexity.MEDIUM:
            if remaining_budget > 0.5:
                return ModelProvider.ANTHROPIC
            else:
                return ModelProvider.OLLAMA

        return ModelProvider.OLLAMA

    def get_daily_cost(self) -> float:
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        today = datetime.now().strftime('%Y-%m-%d')
        c.execute('''
            SELECT SUM(cost) FROM api_calls
            WHERE DATE(timestamp) = ?
        ''', (today,))

        result = c.fetchone()[0] or 0.0
        conn.close()
        return result

    def get_remaining_budget(self, period: str = "daily") -> float:
        if period == "daily":
            current = self.get_daily_cost()
            return max(0, self.cost_limits["daily"] - current)

        return self.cost_limits.get(period, 0)

    def can_use_api(self, estimated_cost: float, complexity: TaskComplexity) -> bool:
        if self.config.get("economy_mode", True) and estimated_cost > 0.05:
            return False

        if complexity == TaskComplexity.CRITICAL:
            return True

        remaining = self.get_remaining_budget("daily")
        return estimated_cost <= remaining and remaining > 0

    def log_task(self, task_id: str, name: str, complexity: TaskComplexity,
                 provider: ModelProvider, status: str = "pending"):
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        c.execute('''
            INSERT INTO tasks
            (task_id, name, complexity, status, provider, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ''', (task_id, name, complexity.value, status, provider.value, datetime.now()))

        conn.commit()
        conn.close()

    def log_api_call(self, provider: str, model: str, tokens_in: int,
                     tokens_out: int, cost: float):
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()

        call_id = hashlib.md5(f"{provider}{model}{datetime.now()}".encode()).hexdigest()
        c.execute('''
            INSERT INTO api_calls
            (call_id, provider, model, tokens_input, tokens_output, cost, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ''', (call_id, provider, model, tokens_in, tokens_out, cost, datetime.now()))

        conn.commit()
        conn.close()

    def get_status_report(self) -> Dict[str, Any]:
        daily_cost = self.get_daily_cost()
        remaining = self.get_remaining_budget("daily")

        return {
            "timestamp": datetime.now().isoformat(),
            "daily_cost": round(daily_cost, 2),
            "daily_limit": self.cost_limits["daily"],
            "remaining_budget": round(remaining, 2),
            "budget_percent": round((daily_cost / self.cost_limits["daily"]) * 100, 1),
        }

if __name__ == "__main__":
    orchestrator = AIOrchestrator()

    task_complexity = orchestrator.classify_task("Refactor authentication system")
    provider = orchestrator.recommend_provider(task_complexity, orchestrator.get_remaining_budget())

    print(f"Task complexity: {task_complexity.value}")
    print(f"Recommended provider: {provider.value}")
    print(f"Status: {json.dumps(orchestrator.get_status_report(), indent=2)}")
