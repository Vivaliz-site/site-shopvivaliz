from __future__ import annotations

import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts import task_continuation_watchdog as watchdog
from scripts import task_resume_queue as queue


class ControllerRecentTaskWindowTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name)
        self.now = datetime(2026, 10, 5, 0, 0, 0, tzinfo=timezone.utc)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def _write_state(self, task_id: str, *, age_days: int, status: str = "RUNNING") -> dict:
        updated = (self.now - timedelta(days=age_days)).isoformat().replace("+00:00", "Z")
        payload = {
            "schema_version": 1,
            "task_id": task_id,
            "repository": "Vivaliz-site/site-shopvivaliz",
            "agent_id": "gpt",
            "goal": f"goal {task_id}",
            "status": status,
            "next_action": f"continue {task_id}",
            "updated_at": updated,
            "history": [],
        }
        (self.runtime / f"{task_id}.json").write_text(json.dumps(payload), encoding="utf-8")
        return payload

    def test_watchdog_analyzes_only_tasks_from_last_ten_days(self) -> None:
        recent = self._write_state("recent-9d", age_days=9)
        self._write_state("old-11d", age_days=11)

        result = watchdog.run_once(
            stale_seconds=120,
            runtime_dir=self.runtime,
            now=self.now,
        )

        self.assertEqual(result["analysis_window_days"], 10)
        self.assertEqual(result["scanned"], 1)
        self.assertEqual(result["ignored_outside_window"], 1)
        self.assertEqual(result["eligible"], 1)
        self.assertEqual(result["dispatched"], 1)

        requests = watchdog.read_requests(self.runtime)
        self.assertEqual([row["task_id"] for row in requests], [recent["task_id"]])

    def test_resume_queue_archives_request_for_task_older_than_window(self) -> None:
        old = self._write_state("old-queue-11d", age_days=11)
        request = {
            "id": "resume-old",
            "kind": "auto_resume",
            "status": "queued",
            "task_id": old["task_id"],
            "repository": old["repository"],
            "next_action": old["next_action"],
            "checkpoint_updated_at": old["updated_at"],
            "fingerprint": queue.checkpoint_fingerprint(old),
        }
        (self.runtime / queue.REQUESTS_FILE).write_text(json.dumps(request) + "\n", encoding="utf-8")

        result = queue.compact_queue(self.runtime, now=self.now)

        self.assertEqual(result["after"]["analysis_window_days"], 10)
        self.assertEqual(result["after"]["actionable_rows"], 0)
        self.assertEqual(result["after"]["orphan_rows"], 0)
        self.assertEqual(result["archived_rows"], 1)
        self.assertEqual(queue.read_requests(self.runtime), [])
        self.assertTrue((self.runtime / queue.ARCHIVE_FILE).is_file())


if __name__ == "__main__":
    unittest.main()
