from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
sys.path.insert(0, str(SCRIPTS))

import agent_task_state as state  # noqa: E402
import task_continuation_watchdog as watchdog  # noqa: E402


def load_dispatcher():
    path = SCRIPTS / "chatgpt_continuity_nudge_dispatcher.py"
    spec = importlib.util.spec_from_file_location("chatgpt_continuity_nudge_dispatcher_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load chatgpt continuity nudge dispatcher")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ChatgptContinuityNudgeDispatcherTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name)
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = self.runtime
        watchdog.RUNTIME_DIR = self.runtime
        self.dispatcher = load_dispatcher()
        # Existing behavioral tests exercise the implementation behind the
        # production fail-closed guard. Production keeps this True.
        self.dispatcher.CHATGPT_WEB_TURN_AUTOMATION_BLOCKED = False
        self.calls: list[dict] = []

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def _stale_checkpoint_and_request(self, task_id: str = "task-1") -> None:
        state.start_task(task_id, "goal", "gpt")
        state.record_progress(task_id, next_action="keep going")
        # Force staleness directly on disk so the watchdog treats it as due.
        path = self.runtime / f"{task_id}.json"
        payload = json.loads(path.read_text(encoding="utf-8"))
        payload["updated_at"] = "2020-01-01T00:00:00Z"
        path.write_text(json.dumps(payload), encoding="utf-8")
        watchdog.run_once(stale_seconds=1, runtime_dir=self.runtime)

    def _fake_enqueue_ok(self, **kwargs):
        self.calls.append(kwargs)
        return {"ok": True, "http_status": 200, "body": {"status": "OK", "enqueued": True}}

    def test_dispatches_new_chatgpt_common_request_to_the_bridge(self) -> None:
        self._stale_checkpoint_and_request()
        result = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(result["dispatched"], 1)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(self.calls[0]["task_id"], "task-1")
        self.assertEqual(self.calls[0]["repository"], state.DEFAULT_REPOSITORY)

    def test_same_fingerprint_is_never_dispatched_twice(self) -> None:
        self._stale_checkpoint_and_request()
        self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        second = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(second["dispatched"], 0)
        self.assertEqual(len(self.calls), 1, "the bridge must be called exactly once for the same fingerprint")

    def test_confirmed_progress_is_the_only_terminal_success_for_same_fingerprint(self) -> None:
        self._stale_checkpoint_and_request()
        self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )

        status_calls: list[dict] = []
        def confirmed_status(**kwargs):
            status_calls.append(kwargs)
            return {
                "ok": True,
                "http_status": 200,
                "body": {"status": "OK", "nudge": {"status": "PROGRESS_CONFIRMED"}},
            }

        second = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
            query_status=confirmed_status,
        )
        self.assertEqual(second["dispatched"], 0)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(len(status_calls), 1)

    def test_sent_unconfirmed_becomes_retryable_after_cooldown(self) -> None:
        self._stale_checkpoint_and_request()
        self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )

        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        rows[-1]["dispatched_at"] = "2020-01-01T00:00:00Z"
        ledger.write_text("\n".join(json.dumps(row) for row in rows) + "\n", encoding="utf-8")

        def unconfirmed_status(**kwargs):
            return {
                "ok": True,
                "http_status": 200,
                "body": {"status": "OK", "nudge": {"status": "SENT_UNCONFIRMED"}},
            }

        retried = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
            query_status=unconfirmed_status,
        )
        self.assertEqual(retried["retry_attempted"], 1)
        self.assertEqual(retried["dispatched"], 1)
        self.assertEqual(len(self.calls), 2)

    def test_unconfirmed_resume_stops_after_bounded_attempt_limit(self) -> None:
        self._stale_checkpoint_and_request()
        self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )

        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        rows[-1]["dispatched_at"] = "2020-01-01T00:00:00Z"
        rows[-1]["attempt_count"] = 2
        ledger.write_text("\n".join(json.dumps(row) for row in rows) + "\n", encoding="utf-8")

        def unconfirmed_status(**kwargs):
            return {
                "ok": True,
                "http_status": 200,
                "body": {"status": "OK", "nudge": {"status": "SENT_UNCONFIRMED"}},
            }

        stopped = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
            query_status=unconfirmed_status,
        )
        self.assertEqual(stopped["dispatched"], 0)
        self.assertEqual(stopped["skipped_attempt_limit"], 1)
        self.assertEqual(len(self.calls), 1)

    def test_missing_token_skips_without_crashing_and_never_marks_the_ledger(self) -> None:
        self._stale_checkpoint_and_request()
        result = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result["skipped_no_token"], 1)
        self.assertEqual(len(self.calls), 0)

        # Providing the token on a later run must still be able to dispatch it
        # (a missing token must never permanently mark the fingerprint as done).
        recovered = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(recovered["dispatched"], 1)

    def test_bridge_transport_failure_still_marks_the_ledger_to_avoid_a_hot_retry_loop(self) -> None:
        self._stale_checkpoint_and_request()

        def failing_enqueue(**kwargs):
            self.calls.append(kwargs)
            return {"ok": False, "http_status": 0, "error": "transport_error"}

        first = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=failing_enqueue,
        )
        self.assertEqual(first["dispatched"], 0)
        self.assertEqual(len(self.calls), 1)

        second = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=failing_enqueue,
        )
        self.assertEqual(len(self.calls), 1, "a failed attempt must not be retried on every single tick")

    def test_terminal_checkpoint_never_dispatches_historical_queued_request(self) -> None:
        self._stale_checkpoint_and_request()
        state.mark_ready("task-1", evidence=["done"], verification="verified")
        state.complete_task("task-1")
        result = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result["skipped_stale_checkpoint"], 1)
        self.assertEqual(len(self.calls), 0)

    def test_request_must_match_current_checkpoint_fingerprint(self) -> None:
        self._stale_checkpoint_and_request()
        state.record_progress("task-1", next_action="newer action after the queued request")
        result = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result["skipped_stale_checkpoint"], 1)
        self.assertEqual(len(self.calls), 0)

    def test_failed_bridge_attempt_becomes_retryable_after_cooldown(self) -> None:
        self._stale_checkpoint_and_request()
        calls: list[dict] = []

        def failing_enqueue(**kwargs):
            calls.append(kwargs)
            return {"ok": False, "http_status": 0, "error": "transport_error"}

        first = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=failing_enqueue,
        )
        self.assertEqual(first["dispatched"], 0)
        self.assertEqual(len(calls), 1)

        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        rows[-1]["dispatched_at"] = "2020-01-01T00:00:00Z"
        ledger.write_text("\n".join(json.dumps(row) for row in rows) + "\n", encoding="utf-8")

        retried = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=failing_enqueue,
        )
        self.assertEqual(retried["retry_attempted"], 1)
        self.assertEqual(len(calls), 2)

    def test_ignores_requests_that_are_not_preferred_executor_chatgpt_common(self) -> None:
        self._stale_checkpoint_and_request()
        # Corrupt the only request row to a different preferred_executor and
        # confirm the dispatcher correctly ignores it.
        requests_path = self.runtime / "_resume-requests.jsonl"
        rows = [json.loads(line) for line in requests_path.read_text(encoding="utf-8").splitlines() if line.strip()]
        for row in rows:
            row["preferred_executor"] = "chatgpt_work"
        requests_path.write_text("\n".join(json.dumps(r) for r in rows) + "\n", encoding="utf-8")

        result = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(len(self.calls), 0)


class ChatgptContinuityPolicyGuardTests(unittest.TestCase):
    def test_authorized_checkpoint_resume_is_not_disabled_by_support_investigation(self) -> None:
        dispatcher = load_dispatcher()
        self.assertFalse(
            getattr(dispatcher, "CHATGPT_WEB_TURN_AUTOMATION_BLOCKED", False),
            "support investigation must not disable the explicitly authorized checkpoint-driven resume path",
        )
        self.assertNotEqual(
            getattr(dispatcher, "CHATGPT_WEB_TURN_AUTOMATION_POLICY", ""),
            "CHATGPT_WEB_AUTOMATION_RISK_GUARD_V2",
        )


class ChatgptContinuityDispatcherWiringTests(unittest.TestCase):
    def test_autonomous_loop_runs_the_dispatcher_after_the_watchdog_and_never_aborts_the_cycle_on_its_failure(self) -> None:
        loop = (ROOT / "scripts" / "autonomous-agent-loop.sh").read_text(encoding="utf-8")
        watchdog_pos = loop.index("task_continuation_watchdog.py")
        dispatcher_pos = loop.index("chatgpt_continuity_nudge_dispatcher.py")
        self.assertLess(watchdog_pos, dispatcher_pos, "the nudge dispatcher must run after the watchdog produces requests")
        # A failure here must be a warning, never `return 1` (which would
        # abort the whole autonomous cycle) -- the chatgpt_common tier was
        # previously always inert, so a bridge outage must regress nothing.
        segment = loop[dispatcher_pos : dispatcher_pos + 700]
        self.assertIn("WARN", segment)
        self.assertNotIn("return 1", segment)


if __name__ == "__main__":
    unittest.main()
