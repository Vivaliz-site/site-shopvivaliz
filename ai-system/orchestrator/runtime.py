"""
24/7 Runtime for ShopVivaliz Hybrid AI
- Continuously monitors task queue
- Routes to local or cloud AI
- Manages agent coordination
- Tracks costs and performance
"""

import json
import time
import os
import logging
from datetime import datetime
from pathlib import Path
import sys

# Add parent directory to path
ai_system_path = str(Path(__file__).parent.parent)
sys.path.insert(0, ai_system_path)

from orchestrator.core import AIOrchestrator, TaskComplexity, ModelProvider
try:
    from api_integrations.ollama_client import OllamaClient
except ImportError:
    OllamaClient = None
try:
    from agents.agents import AGENTS, Agent
except ImportError:
    AGENTS = {}
try:
    from memory.vector_memory import VectorMemory
except ImportError:
    VectorMemory = None

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("C:/site-shopvivaliz/logs/ai-orchestrator.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger("AIRuntime")

class AIRuntime:
    def __init__(self, repo_path: str = "C:/site-shopvivaliz"):
        self.repo_path = repo_path
        self.orchestrator = AIOrchestrator(repo_path)
        self.memory = VectorMemory()
        self.task_queue_path = f"{repo_path}/tasks-queue.json"

        logger.info("✅ AI Runtime initialized")

    def read_task_queue(self) -> dict:
        """Read the task queue from JSON file"""
        if not os.path.exists(self.task_queue_path):
            logger.warning(f"Task queue not found at {self.task_queue_path}")
            return {"tasks": []}

        with open(self.task_queue_path, 'r', encoding='utf-8') as f:
            return json.load(f)

    def save_task_queue(self, queue: dict):
        """Save task queue back to file"""
        with open(self.task_queue_path, 'w', encoding='utf-8') as f:
            json.dump(queue, f, indent=2, ensure_ascii=False)

    def process_task(self, task: dict) -> bool:
        """
        Process a single task through the orchestrator
        Returns True if successful
        """
        task_id = task.get("task_id", "unknown")
        task_name = task.get("title", "Unnamed task")
        action = task.get("action", "unknown")

        logger.info(f"[{task_id}] Processing: {task_name}")

        try:
            # Classify complexity
            complexity = self.orchestrator.classify_task(task_name)
            logger.info(f"[{task_id}] Complexity: {complexity.value}")

            # Check budget
            remaining = self.orchestrator.get_remaining_budget()
            logger.info(f"[{task_id}] Budget remaining: ${remaining:.2f}")

            # Route to provider
            provider = self.orchestrator.recommend_provider(complexity, remaining)
            logger.info(f"[{task_id}] Routed to: {provider.value}")

            # Store in memory
            self.memory.store(
                content=f"Task {task_id}: {task_name} - Action: {action}",
                type_="task",
                agent="orchestrator",
                source=f"tasks-queue.json"
            )

            # Log task
            self.orchestrator.log_task(
                task_id=task_id,
                name=task_name,
                complexity=complexity,
                provider=provider,
                status="in_progress"
            )

            # Simulate processing (real implementation would call AI models)
            logger.info(f"[{task_id}] ✅ Task routed successfully")

            # Mark as processed
            return True

        except Exception as e:
            logger.error(f"[{task_id}] ❌ Error: {e}")
            return False

    def run_cycle(self) -> int:
        """
        Run one orchestration cycle
        Returns number of tasks processed
        """
        logger.info("\n" + "="*60)
        logger.info(f"ORCHESTRATION CYCLE - {datetime.now().isoformat()}")
        logger.info("="*60)

        try:
            queue = self.read_task_queue()
            tasks = queue.get("tasks", [])

            # Get pending tasks
            pending_tasks = [t for t in tasks if t.get("status") == "pending"]

            logger.info(f"📋 Found {len(pending_tasks)} pending tasks")

            if not pending_tasks:
                logger.info("✅ No pending tasks")
                return 0

            # Process each pending task
            processed = 0
            for task in pending_tasks[:5]:  # Limit to 5 per cycle
                if self.process_task(task):
                    task["status"] = "processed"
                    processed += 1

            # Save updated queue
            self.save_task_queue(queue)

            # Print status
            status = self.orchestrator.get_status_report()
            logger.info(f"\n💰 COST REPORT:")
            logger.info(f"   Daily cost: ${status['daily_cost']:.2f} / ${status['daily_limit']}")
            logger.info(f"   Budget usage: {status['budget_percent']:.1f}%")
            logger.info(f"\n✅ Cycle complete - Processed {processed} tasks")

            return processed

        except Exception as e:
            logger.error(f"❌ Cycle failed: {e}")
            return 0

    def start_continuous(self, interval_seconds: int = 300):
        """
        Start continuous monitoring (every 5 minutes by default)
        """
        logger.info("\n" + "🚀 "*20)
        logger.info("STARTING 24/7 ORCHESTRATION")
        logger.info("🚀 "*20 + "\n")

        cycle_count = 0

        try:
            while True:
                cycle_count += 1
                logger.info(f"\n📍 CYCLE #{cycle_count}")

                # Run one cycle
                self.run_cycle()

                # Wait before next cycle
                logger.info(f"⏳ Next cycle in {interval_seconds} seconds...")
                time.sleep(interval_seconds)

        except KeyboardInterrupt:
            logger.info("\n⛔ Orchestration stopped by user")
            sys.exit(0)

        except Exception as e:
            logger.error(f"\n💥 FATAL ERROR: {e}")
            sys.exit(1)

if __name__ == "__main__":
    runtime = AIRuntime()

    # Run one cycle immediately
    logger.info("Running initial cycle...")
    runtime.run_cycle()

    # Uncomment to run 24/7
    # runtime.start_continuous(interval_seconds=300)
