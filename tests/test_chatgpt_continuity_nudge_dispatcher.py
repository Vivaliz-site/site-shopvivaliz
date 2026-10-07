from __future__ import annotations

import importlib.util
import json
import os
import sys
import tempfile
import unittest
from unittest import mock
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

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

    def _stale_checkpoint_and_request(self, task_id: str = "task-1", *, bind: bool = True) -> None:
        state.start_task(task_id, "goal", "gpt")
        if bind:
            state.bind_conversation(task_id, conversation_id="6ac0f8b7-f2f0-83e9-95c5-54be614b9dee")
        state.record_progress(task_id, next_action="keep going")
        # Force staleness directly on disk so the watchdog treats it as due.
        path = self.runtime / f"{task_id}.json"
        payload = json.loads(path.read_text(encoding="utf-8"))
        payload["updated_at"] = (
            datetime.now(timezone.utc) - timedelta(minutes=5)
        ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        path.write_text(json.dumps(payload), encoding="utf-8")
        watchdog.run_once(stale_seconds=1, runtime_dir=self.runtime)

    def _fake_enqueue_ok(self, **kwargs):
        self.calls.append(kwargs)
        return {"ok": True, "http_status": 200, "body": {"status": "OK", "enqueued": True}}

    def test_worker_error_remains_degraded_during_retry_cooldown(self) -> None:
        self._stale_checkpoint_and_request()
        kwargs = dict(runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
                      token="test-token", enqueue=self._fake_enqueue_ok)
        self.dispatcher.run_once(**kwargs)
        status = {"ok": True, "body": {"nudge": {"status": "ERROR"}}}
        kwargs["query_status"] = lambda **unused: status
        result = self.dispatcher.run_once(**kwargs)
        later = self.dispatcher.run_once(**kwargs)
        self.assertEqual(result["failed"], 1)
        self.assertEqual(later["failed"], 1)
        self.assertEqual(len(self.calls), 1)
        state.record_progress("task-1", next_action="repair transport", evidence="changed checkpoint")
        result = self.dispatcher.run_once(**kwargs)
        self.assertEqual(result["failed"], 0, "obsolete failed fingerprint must not degrade current work")

    def test_confirmed_progress_clears_browser_failure(self) -> None:
        self._stale_checkpoint_and_request()
        kwargs = dict(runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
                      token="test-token", enqueue=self._fake_enqueue_ok)
        self.dispatcher.run_once(**kwargs)
        result = self.dispatcher.run_once(**kwargs, query_status=lambda **unused: {
            "ok": True, "body": {"nudge": {
                "status": "PROGRESS_CONFIRMED",
                "conversation_id": "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee",
            }}})
        self.assertEqual(result["failed"], 0)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(
            state.load_task("task-1")["conversation_id"],
            "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee",
            "confirmed browser progress must persist the exact conversation binding in the checkpoint",
        )

    def test_enqueue_failure_is_reported_without_disabling_retry(self) -> None:
        self._stale_checkpoint_and_request()
        result = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=lambda **unused: {"ok": False, "http_status": 503})
        self.assertEqual(result["failed"], 1)
        self.assertEqual(result["dispatched"], 0)

    def test_confirmed_ledger_binding_is_reused_across_fingerprints(self) -> None:
        ledger = {
            "old-fingerprint": {
                "task_id": "task-1",
                "worker_status": "PROGRESS_CONFIRMED",
                "worker_status_observed_at": "2026-10-02T06:00:00Z",
                "conversation_id": "12345678-2222-3333-4444-555555555555",
            },
            "other-task": {
                "task_id": "task-2",
                "worker_status": "PROGRESS_CONFIRMED",
                "worker_status_observed_at": "2026-10-02T06:01:00Z",
                "conversation_id": "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
            },
        }
        self.assertEqual(
            self.dispatcher._ledger_bound_conversation_id(ledger, "task-1"),
            "12345678-2222-3333-4444-555555555555",
        )
        self.assertEqual(self.dispatcher._ledger_bound_conversation_id(ledger, "missing"), "")

    def test_canonical_defaults_match_backend_bridge_runtime(self) -> None:
        self.assertEqual(
            self.dispatcher.DEFAULT_BRIDGE_URL,
            "http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php",
        )
        self.assertEqual(
            self.dispatcher.DEFAULT_TOKEN_FILE,
            Path("/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token"),
        )
        self.assertNotEqual(self.dispatcher.DEFAULT_TOKEN_FILE, self.dispatcher.LEGACY_TOKEN_FILE)

    def test_unbound_checkpoint_is_deferred_without_browser_dispatch(self) -> None:
        self._stale_checkpoint_and_request(bind=False)
        result = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result.get("skipped_unbound"), 1)
        self.assertEqual(len(self.calls), 0)

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

    def test_same_conversation_dispatches_only_newest_running_checkpoint(self) -> None:
        conversation_id = "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee"
        now = datetime.now(timezone.utc)
        for task_id, updated_at in (
            ("task-old", (now - timedelta(days=2)).replace(microsecond=0).isoformat().replace("+00:00", "Z")),
            ("task-new", (now - timedelta(days=1)).replace(microsecond=0).isoformat().replace("+00:00", "Z")),
        ):
            state.start_task(task_id, "goal", "gpt")
            state.bind_conversation(task_id, conversation_id=conversation_id)
            state.record_progress(task_id, next_action="keep going")
            path = self.runtime / f"{task_id}.json"
            payload = json.loads(path.read_text(encoding="utf-8"))
            payload["updated_at"] = updated_at
            path.write_text(json.dumps(payload), encoding="utf-8")

        watchdog.run_once(stale_seconds=1, runtime_dir=self.runtime)
        result = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )

        self.assertEqual(result["dispatched"], 1)
        self.assertEqual(result.get("skipped_conversation_coalesced"), 1)
        self.assertEqual(len(self.calls), 1)
        self.assertEqual(self.calls[0]["task_id"], "task-new")
        self.assertEqual(self.calls[0]["conversation_id"], conversation_id)

    def test_inflight_same_conversation_keeps_ownership_until_terminal(self) -> None:
        conversation_id = "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee"
        state.start_task("task-old", "goal", "gpt")
        state.bind_conversation("task-old", conversation_id=conversation_id)
        state.record_progress("task-old", next_action="keep going")
        old_path = self.runtime / "task-old.json"
        old_payload = json.loads(old_path.read_text(encoding="utf-8"))
        old_payload["updated_at"] = (
            datetime.now(timezone.utc) - timedelta(days=2)
        ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        old_path.write_text(json.dumps(old_payload), encoding="utf-8")
        watchdog.run_once(stale_seconds=1, runtime_dir=self.runtime)
        self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(len(self.calls), 1)

        state.start_task("task-new", "goal", "gpt")
        state.bind_conversation("task-new", conversation_id=conversation_id)
        state.record_progress("task-new", next_action="keep going")
        new_path = self.runtime / "task-new.json"
        new_payload = json.loads(new_path.read_text(encoding="utf-8"))
        new_payload["updated_at"] = (
            datetime.now(timezone.utc) - timedelta(days=1)
        ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        new_path.write_text(json.dumps(new_payload), encoding="utf-8")
        watchdog.run_once(stale_seconds=1, runtime_dir=self.runtime)

        result = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
            query_status=lambda **unused: {"ok": True, "body": {"nudge": {"status": "CLAIMED"}}},
        )
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result["skipped_conversation_coalesced"], 1)
        self.assertEqual(len(self.calls), 1, "new checkpoint must not overlap an accepted browser attempt")

    def test_failed_older_same_conversation_does_not_poison_new_owner_health(self) -> None:
        conversation_id = "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee"
        now = datetime.now(timezone.utc)
        for task_id, updated_at in (
            ("task-old", (now - timedelta(days=2)).replace(microsecond=0).isoformat().replace("+00:00", "Z")),
            ("task-new", (now - timedelta(days=1)).replace(microsecond=0).isoformat().replace("+00:00", "Z")),
        ):
            state.start_task(task_id, "goal", "gpt")
            state.bind_conversation(task_id, conversation_id=conversation_id)
            state.record_progress(task_id, next_action="keep going")
            path = self.runtime / f"{task_id}.json"
            payload = json.loads(path.read_text(encoding="utf-8"))
            payload["updated_at"] = updated_at
            path.write_text(json.dumps(payload), encoding="utf-8")
        watchdog.run_once(stale_seconds=1, runtime_dir=self.runtime)

        requests = watchdog.read_requests(self.runtime)
        old_request = next(row for row in requests if row.get("task_id") == "task-old")
        self.dispatcher._append_ledger(self.runtime, {
            "fingerprint": old_request["fingerprint"],
            "task_id": "task-old",
            "repository": state.DEFAULT_REPOSITORY,
            "dispatched_at": "2020-01-01T00:00:00Z",
            "bridge_ok": True,
            "worker_status": "ERROR",
            "attempt_count": 1,
            "send_attempt_count": 0,
            "conversation_id": conversation_id,
        })

        result = self.dispatcher.run_once(
            runtime_dir=self.runtime, bridge_url="https://example.invalid/bridge.php",
            token="test-token", enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(result["failed"], 0, "superseded same-conversation failure must not poison readiness")
        self.assertEqual(result["dispatched"], 1)
        self.assertEqual(self.calls[0]["task_id"], "task-new")

    def test_live_dispatch_lock_blocks_parallel_nudge_effect(self) -> None:
        self._stale_checkpoint_and_request()
        with self.dispatcher._dispatcher_lock(self.runtime) as acquired:
            self.assertTrue(acquired)
            blocked = self.dispatcher.run_once(
                runtime_dir=self.runtime,
                bridge_url="https://example.invalid/bridge.php",
                token="test-token",
                enqueue=self._fake_enqueue_ok,
            )

        self.assertTrue(blocked["locked"])
        self.assertEqual(blocked["dispatched"], 0)
        self.assertEqual(len(self.calls), 0)

        recovered = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(recovered["dispatched"], 1)
        self.assertEqual(len(self.calls), 1)

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

    def test_confirmed_progress_is_followed_again_until_checkpoint_becomes_terminal(self) -> None:
        self._stale_checkpoint_and_request()
        kwargs = dict(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )
        self.dispatcher.run_once(**kwargs)
        observed = self.dispatcher.run_once(
            **kwargs,
            query_status=lambda **unused: {
                "ok": True,
                "http_status": 200,
                "body": {"status": "OK", "nudge": {
                    "status": "PROGRESS_CONFIRMED",
                    "conversation_id": "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee",
                }},
            },
        )
        self.assertEqual(observed["dispatched"], 0)
        self.assertEqual(len(self.calls), 1)

        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        rows[-1]["worker_status_observed_at"] = "2020-01-01T00:00:00Z"
        rows[-1]["dispatched_at"] = "2020-01-01T00:00:00Z"
        ledger.write_text("\n".join(json.dumps(row) for row in rows) + "\n", encoding="utf-8")

        followup = self.dispatcher.run_once(**kwargs)
        self.assertEqual(state.load_task("task-1")["status"], "RUNNING")
        self.assertEqual(followup["dispatched"], 1)
        self.assertEqual(followup.get("progress_followup_attempted"), 1)
        self.assertEqual(len(self.calls), 2)

    def test_live_foreground_lease_blocks_enqueue_when_durable_handoff_enabled(self) -> None:
        self._stale_checkpoint_and_request(task_id="foreground-blocked")
        state.bind_browser_session("foreground-blocked", browser_session="dev")
        state.acquire_foreground_lease_for_task("foreground-blocked", owner_id="turn-live", ttl_seconds=60)
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1"}):
            result = self.dispatcher.run_once(
                runtime_dir=self.runtime,
                bridge_url="https://example.invalid/bridge.php",
                token="test-token",
                enqueue=self._fake_enqueue_ok,
            )
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result["skipped_foreground_active"], 1)
        self.assertEqual(self.calls, [])

    def test_terminal_worker_result_releases_recovery_ownership_for_next_conversation(self) -> None:
        self._stale_checkpoint_and_request(task_id="first-conversation")
        state.bind_browser_session("first-conversation", browser_session="dev")

        kwargs = dict(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1"}):
            first = self.dispatcher.run_once(**kwargs)
            self.assertEqual(first["dispatched"], 1)

            observed = self.dispatcher.run_once(
                **kwargs,
                query_status=lambda **unused: {
                    "ok": True,
                    "http_status": 200,
                    "body": {"status": "OK", "nudge": {"status": "STALLED_NOT_CONFIRMED"}},
                },
            )
            self.assertEqual(observed["dispatched"], 0)

            first_state = state.load_task("first-conversation")
            self.assertTrue(
                first_state.get("recovery_released_at"),
                "terminal worker observation must release durable recovery ownership immediately",
            )

            state.start_task("second-conversation", "goal", "gpt")
            state.bind_conversation(
                "second-conversation",
                conversation_id="aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
            )
            state.bind_browser_session("second-conversation", browser_session="dev")
            state.record_progress("second-conversation", next_action="keep going")
            second_path = self.runtime / "second-conversation.json"
            second_payload = json.loads(second_path.read_text(encoding="utf-8"))
            second_payload["updated_at"] = (
                datetime.now(timezone.utc) - timedelta(minutes=5)
            ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
            second_path.write_text(json.dumps(second_payload), encoding="utf-8")
            watchdog.run_once(stale_seconds=1, runtime_dir=self.runtime)

            next_result = self.dispatcher.run_once(**kwargs)

        self.assertEqual(next_result["dispatched"], 1)
        self.assertEqual(next_result["skipped_ownership_busy"], 0)
        self.assertEqual(self.calls[-1]["task_id"], "second-conversation")

    def test_worker_status_maps_to_explicit_recovery_states(self) -> None:
        f = self.dispatcher.recovery_state_for_worker_status
        self.assertEqual(f("CLAIMED", 0, 2), "RECOVERY_CLAIMED")
        self.assertEqual(f("SENT_UNCONFIRMED", 1, 2), "WAITING_FOR_REAL_RESPONSE")
        self.assertEqual(f("PROGRESS_CONFIRMED", 1, 2), "PROGRESS_CONFIRMED")
        self.assertEqual(f("SENT_UNCONFIRMED", 2, 2), "RECOVERY_EXHAUSTED")
        self.assertNotEqual(f("STALLED_NOT_CONFIRMED", 0, 2), "PROGRESS_CONFIRMED")

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

    def test_stalled_not_confirmed_does_not_consume_real_send_budget(self) -> None:
        self._stale_checkpoint_and_request()
        fingerprint = watchdog.read_requests(self.runtime)[0]["fingerprint"]
        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        ledger.write_text(
            json.dumps(
                {
                    "fingerprint": fingerprint,
                    "task_id": "task-1",
                    "repository": state.DEFAULT_REPOSITORY,
                    "dispatched_at": "2020-01-01T00:00:00Z",
                    "bridge_ok": True,
                    "http_status": 200,
                    "worker_status": "STALLED_NOT_CONFIRMED",
                    "attempt_count": 99,
                    "send_attempt_count": 0,
                }
            )
            + "\n",
            encoding="utf-8",
        )

        retried = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(retried["retry_attempted"], 1)
        self.assertEqual(retried["dispatched"], 1)
        self.assertEqual(retried["skipped_attempt_limit"], 0)
        self.assertEqual(len(self.calls), 1)

        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        self.assertEqual(rows[-1]["send_attempt_count"], 0)

    def test_bound_session_identity_mismatch_is_not_hot_retried(self) -> None:
        self._stale_checkpoint_and_request()
        fingerprint = watchdog.read_requests(self.runtime)[0]["fingerprint"]
        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        ledger.write_text(
            json.dumps(
                {
                    "fingerprint": fingerprint,
                    "task_id": "task-1",
                    "repository": state.DEFAULT_REPOSITORY,
                    "dispatched_at": "2020-01-01T00:00:00Z",
                    "bridge_ok": True,
                    "http_status": 200,
                    "worker_status": "ERROR",
                    "detail_code": "BOUND_SESSION_IDENTITY_MISMATCH",
                    "attempt_count": 99,
                    "send_attempt_count": 0,
                }
            )
            + "\n",
            encoding="utf-8",
        )

        result = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )

        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result.get("skipped_session_unavailable"), 1)
        self.assertEqual(result["failed"], 1)
        self.assertEqual(self.calls, [])

    def test_error_does_not_consume_real_send_budget(self) -> None:
        self._stale_checkpoint_and_request()
        fingerprint = watchdog.read_requests(self.runtime)[0]["fingerprint"]
        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        ledger.write_text(
            json.dumps(
                {
                    "fingerprint": fingerprint,
                    "task_id": "task-1",
                    "repository": state.DEFAULT_REPOSITORY,
                    "dispatched_at": "2020-01-01T00:00:00Z",
                    "bridge_ok": True,
                    "http_status": 200,
                    "worker_status": "ERROR",
                    "attempt_count": 2,
                    "send_attempt_count": 1,
                }
            )
            + "\n",
            encoding="utf-8",
        )

        retried = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
        )
        self.assertEqual(retried["retry_attempted"], 1)
        self.assertEqual(retried["dispatched"], 1)
        self.assertEqual(retried["skipped_attempt_limit"], 0)
        self.assertEqual(len(self.calls), 1)

        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        self.assertEqual(rows[-1]["send_attempt_count"], 1)

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
        rows[-1]["send_attempt_count"] = 2
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

    def test_active_claim_is_repolled_until_worker_reaches_terminal_result(self) -> None:
        self._stale_checkpoint_and_request()
        fingerprint = watchdog.read_requests(self.runtime)[0]["fingerprint"]
        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        ledger.write_text(
            json.dumps(
                {
                    "fingerprint": fingerprint,
                    "task_id": "task-1",
                    "repository": state.DEFAULT_REPOSITORY,
                    "dispatched_at": self.dispatcher.utc_now(),
                    "bridge_ok": True,
                    "http_status": 200,
                    "worker_status": "CLAIMED",
                    "attempt_count": 1,
                }
            )
            + "\n",
            encoding="utf-8",
        )

        status_calls: list[dict] = []
        def confirmed_status(**kwargs):
            status_calls.append(kwargs)
            return {
                "ok": True,
                "http_status": 200,
                "body": {"status": "OK", "nudge": {"status": "PROGRESS_CONFIRMED"}},
            }

        result = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
            query_status=confirmed_status,
        )

        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(len(status_calls), 1, "CLAIMED must be re-polled instead of cached forever")
        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        self.assertEqual(rows[-1]["worker_status"], "PROGRESS_CONFIRMED")

    def test_unchanged_active_status_is_repolled_without_ledger_spam(self) -> None:
        self._stale_checkpoint_and_request()
        fingerprint = watchdog.read_requests(self.runtime)[0]["fingerprint"]
        ledger = self.runtime / self.dispatcher.LEDGER_FILE
        initial = {
            "fingerprint": fingerprint,
            "task_id": "task-1",
            "repository": state.DEFAULT_REPOSITORY,
            "dispatched_at": self.dispatcher.utc_now(),
            "bridge_ok": True,
            "http_status": 200,
            "worker_status": "PENDING",
            "attempt_count": 1,
        }
        ledger.write_text(json.dumps(initial) + "\n", encoding="utf-8")

        def pending_status(**kwargs):
            return {
                "ok": True,
                "http_status": 200,
                "body": {"status": "OK", "nudge": {"status": "PENDING"}},
            }

        result = self.dispatcher.run_once(
            runtime_dir=self.runtime,
            bridge_url="https://example.invalid/bridge.php",
            token="test-token",
            enqueue=self._fake_enqueue_ok,
            query_status=pending_status,
        )

        self.assertEqual(result["dispatched"], 0)
        rows = [json.loads(line) for line in ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
        self.assertEqual(len(rows), 1, "unchanged PENDING status must not append duplicate ledger rows")

    def test_missing_token_skips_without_crashing_and_never_marks_the_ledger(self) -> None:
        self._stale_checkpoint_and_request()
        missing_token_file = self.runtime / "deliberately-missing-bridge.token"
        with (
            patch.dict(
                os.environ,
                {
                    "CHATGPT_CONTINUITY_BRIDGE_TOKEN": "",
                    "CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE": str(missing_token_file),
                },
                clear=False,
            ),
            patch.object(self.dispatcher, "DEFAULT_TOKEN_FILE", missing_token_file),
            patch.object(self.dispatcher, "LEGACY_TOKEN_FILE", missing_token_file),
        ):
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
