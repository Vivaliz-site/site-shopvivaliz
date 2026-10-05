from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
sys.path.insert(0, str(SCRIPTS))

import task_resume_dispatcher as dispatcher  # noqa: E402


def load_worker():
    path = SCRIPTS / "task_resume_worker.py"
    spec = importlib.util.spec_from_file_location("task_resume_worker_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load task resume worker")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ResumeWorkerTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.runtime = self.root / "state"
        self.runtime.mkdir()
        self.project = self.root / "project"
        self.project.mkdir()
        (self.project / "scripts").mkdir()

    def tearDown(self) -> None:
        self.temp.cleanup()

    def _seed(self) -> tuple[dict, dict]:
        now = datetime.now(timezone.utc).replace(microsecond=0)
        state = {
            "schema_version": 1,
            "task_id": "worker-e2e",
            "agent_id": "worker-test",
            "goal": "advance durable checkpoint",
            "status": "RUNNING",
            "next_action": "continue worker task",
            "evidence": [],
            "verification": None,
            "blocker": None,
            "created_at": (now - timedelta(hours=1)).isoformat().replace("+00:00", "Z"),
            "updated_at": (now - timedelta(minutes=5)).isoformat().replace("+00:00", "Z"),
            "history": [],
        }
        (self.runtime / "worker-e2e.json").write_text(json.dumps(state), encoding="utf-8")
        request = {
            "id": "worker-request-1",
            "kind": "auto_resume",
            "status": "queued",
            "task_id": "worker-e2e",
            "goal": state["goal"],
            "next_action": state["next_action"],
            "checkpoint_updated_at": state["updated_at"],
            "fingerprint": "worker-fingerprint-1",
        }
        (self.runtime / "_resume-requests.jsonl").write_text(json.dumps(request) + "\n", encoding="utf-8")
        dispatcher.enqueue_execution(self.runtime, self.project, request, state, 30)
        return state, request

    def test_worker_claims_one_execution_and_records_real_progress(self) -> None:
        worker = load_worker()
        state, _request = self._seed()
        executor = self.root / "advance.py"
        executor.write_text(
            """
import json, os
from pathlib import Path
runtime = Path(os.environ['SHOPVIVALIZ_AGENT_TASK_STATE_DIR'])
task_id = os.environ['SHOPVIVALIZ_TASK_ID']
path = runtime / f'{task_id}.json'
state = json.loads(path.read_text())
state['next_action'] = 'advanced by worker'
state['updated_at'] = '2026-10-05T08:10:00Z'
path.write_text(json.dumps(state))
""",
            encoding="utf-8",
        )

        result = worker.worker_run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=[sys.executable, str(executor)],
        )

        self.assertEqual(result["claimed"], 1)
        self.assertEqual(result["progressed"], 1)
        records = list(self.runtime.glob("_resume-execution-*.json"))
        self.assertEqual(len(records), 1)
        record = json.loads(records[0].read_text())
        self.assertEqual(record["status"], "completed")
        self.assertEqual(record["result"], "progress")
        self.assertEqual(record["checkpoint_before"], state["updated_at"])
        self.assertEqual(record["checkpoint_after"], "2026-10-05T08:10:00Z")
        self.assertEqual(record["evidence"]["checkpoint_before"], state["updated_at"])
        self.assertEqual(record["evidence"]["checkpoint_after"], "2026-10-05T08:10:00Z")

    def test_second_worker_cannot_claim_live_execution(self) -> None:
        worker = load_worker()
        self._seed()
        record_path = next(self.runtime.glob("_resume-execution-*.json"))
        record = json.loads(record_path.read_text())
        record["status"] = "running"
        record["worker_pid"] = 1
        record["worker_start_time"] = worker._process_start_time(1)
        record_path.write_text(json.dumps(record), encoding="utf-8")

        result = worker.worker_run_once(runtime_dir=self.runtime, project_dir=self.project, executor=[sys.executable])

        self.assertEqual(result["claimed"], 0)
        current = json.loads(record_path.read_text())
        self.assertEqual(current["status"], "running")

    def test_worker_restart_recovers_dead_claim_without_marking_success(self) -> None:
        worker = load_worker()
        self._seed()
        record_path = next(self.runtime.glob("_resume-execution-*.json"))
        record = json.loads(record_path.read_text())
        record["status"] = "running"
        record["worker_pid"] = 999999
        record["worker_start_time"] = "dead-process"
        record_path.write_text(json.dumps(record), encoding="utf-8")
        noop = self.root / "noop.py"
        noop.write_text("pass\n", encoding="utf-8")

        result = worker.worker_run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=[sys.executable, str(noop)],
        )

        self.assertEqual(result["recovered"], 1)
        self.assertEqual(result["claimed"], 1)
        self.assertEqual(result["progressed"], 0)
        self.assertEqual(result["no_progress"], 1)
        current = json.loads(record_path.read_text())
        self.assertEqual(current["result"], "no_progress")

    def test_provider_timeout_is_terminal_worker_failure_not_controller_failure(self) -> None:
        worker = load_worker()
        self._seed()
        record_path = next(self.runtime.glob("_resume-execution-*.json"))
        record = json.loads(record_path.read_text())
        record["timeout_seconds"] = 1
        record_path.write_text(json.dumps(record), encoding="utf-8")
        sleeper = self.root / "sleep.py"
        sleeper.write_text("import time\ntime.sleep(2)\n", encoding="utf-8")

        result = worker.worker_run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=[sys.executable, str(sleeper)],
        )

        self.assertEqual(result["failed"], 1)
        current = json.loads(record_path.read_text())
        self.assertEqual(current["status"], "completed")
        self.assertEqual(current["result"], "timeout")

    def test_newer_checkpoint_is_not_overwritten_by_old_worker_no_progress_restore(self) -> None:
        worker = load_worker()
        self._seed()
        state_path = self.runtime / "worker-e2e.json"
        newer = json.loads(state_path.read_text())
        newer["next_action"] = "newer external progress"
        newer["updated_at"] = "2026-10-05T08:20:00Z"
        state_path.write_text(json.dumps(newer), encoding="utf-8")
        marker = self.root / "should-not-run.py"
        marker.write_text("raise SystemExit(99)\n", encoding="utf-8")

        result = worker.worker_run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=[sys.executable, str(marker)],
        )

        self.assertEqual(result["claimed"], 0)
        current_state = json.loads(state_path.read_text())
        self.assertEqual(current_state["next_action"], "newer external progress")
        self.assertEqual(current_state["updated_at"], "2026-10-05T08:20:00Z")
        record = json.loads(next(self.runtime.glob("_resume-execution-*.json")).read_text())
        self.assertEqual(record["result"], "superseded")


if __name__ == "__main__":
    unittest.main()
