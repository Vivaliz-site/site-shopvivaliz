from __future__ import annotations

import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts import agent_task_state as state
from scripts import task_resume_queue as queue


class TaskResumeQueueCertificationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name)
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = self.runtime

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def _payload(self, task_id: str) -> dict:
        return json.loads((self.runtime / f"{task_id}.json").read_text(encoding="utf-8"))

    def _request_for(self, payload: dict) -> dict:
        return {
            "id": f"resume-{payload['task_id']}",
            "kind": "auto_resume",
            "status": "queued",
            "task_id": payload["task_id"],
            "repository": payload["repository"],
            "next_action": payload["next_action"],
            "checkpoint_updated_at": payload["updated_at"],
            "fingerprint": queue.checkpoint_fingerprint(payload),
        }

    def _write_lines(self, lines: list[str]) -> None:
        path = self.runtime / queue.REQUESTS_FILE
        path.write_text("\n".join(lines) + "\n", encoding="utf-8")

    def test_certification_separates_actionable_from_historical_backlog(self) -> None:
        state.start_task("current", "current goal", "gpt")
        state.record_progress("current", next_action="current action")
        current = self._payload("current")

        state.start_task("done", "done goal", "gpt")
        state.record_progress("done", next_action="old terminal action")
        terminal_request = self._request_for(self._payload("done"))
        state.mark_ready("done", evidence=["verified"], verification="done")
        state.complete_task("done")

        state.start_task("moved", "moved goal", "gpt")
        state.record_progress("moved", next_action="old action")
        superseded_request = self._request_for(self._payload("moved"))
        state.record_progress("moved", next_action="new action")

        current_request = self._request_for(current)
        self._write_lines([
            json.dumps(current_request, sort_keys=True),
            json.dumps(terminal_request, sort_keys=True),
            json.dumps(superseded_request, sort_keys=True),
            json.dumps(current_request, sort_keys=True),
            "{malformed-json",
        ])

        report = queue.certify_queue(self.runtime)
        self.assertEqual(report["raw_rows"], 5)
        self.assertEqual(report["queued_rows"], 4)
        self.assertEqual(report["actionable_rows"], 1)
        self.assertEqual(report["duplicate_rows"], 1)
        self.assertEqual(report["terminal_history_rows"], 1)
        self.assertEqual(report["superseded_rows"], 1)
        self.assertEqual(report["malformed_rows"], 1)
        self.assertEqual(report["running_tasks"], 2)
        self.assertFalse(report["certified"])

    def test_compaction_archives_nonactionable_rows_and_keeps_only_current_unique_work(self) -> None:
        state.start_task("current", "current goal", "gpt")
        state.record_progress("current", next_action="current action")
        current_request = self._request_for(self._payload("current"))

        terminal = dict(current_request)
        terminal["task_id"] = "gone"
        terminal["id"] = "resume-gone"
        terminal["fingerprint"] = "a" * 64

        self._write_lines([
            json.dumps(current_request, sort_keys=True),
            json.dumps(current_request, sort_keys=True),
            json.dumps(terminal, sort_keys=True),
            "{bad-json",
        ])

        result = queue.compact_queue(self.runtime, force=True)
        self.assertTrue(result["compacted"])
        self.assertEqual(result["archived_rows"], 3)
        self.assertEqual(result["after"]["raw_rows"], 1)
        self.assertEqual(result["after"]["actionable_rows"], 1)
        self.assertTrue(result["after"]["certified"])

        active = queue.read_requests(self.runtime)
        self.assertEqual(len(active), 1)
        self.assertEqual(active[0]["task_id"], "current")

        archive = self.runtime / queue.ARCHIVE_FILE
        self.assertTrue(archive.is_file())
        archived = archive.read_text(encoding="utf-8")
        self.assertIn("resume-gone", archived)
        self.assertIn("{bad-json", archived)

    def test_watchdog_automatically_compacts_backlog_without_losing_current_retry(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("active", "keep running", "gpt")
        state.record_progress("active", next_action="resume current checkpoint")
        active_path = self.runtime / "active.json"
        payload = json.loads(active_path.read_text(encoding="utf-8"))
        payload["updated_at"] = (
            datetime.now(timezone.utc) - timedelta(minutes=10)
        ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        active_path.write_text(json.dumps(payload), encoding="utf-8")

        orphan_rows = [
            json.dumps({
                "id": f"resume-orphan-{index}",
                "kind": "auto_resume",
                "status": "queued",
                "task_id": f"orphan-{index}",
                "repository": state.DEFAULT_REPOSITORY,
                "next_action": "historical",
                "checkpoint_updated_at": "2020-01-01T00:00:00Z",
                "fingerprint": f"{index:064x}",
            }, sort_keys=True)
            for index in range(40)
        ]
        self._write_lines(orphan_rows)

        result = watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        self.assertEqual(result["dispatched"], 1)
        self.assertTrue(result["queue"]["compacted"])
        self.assertEqual(result["queue"]["after"]["actionable_rows"], 1)
        self.assertEqual(len(queue.read_requests(self.runtime)), 1)


if __name__ == "__main__":
    unittest.main()
