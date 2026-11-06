"""Regression: non-ChatGPT task continuity is explicit, fenced and isolated."""
from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import agent_task_state as state
from scripts import task_continuation_watchdog as watchdog
from scripts import task_resume_dispatcher as dispatcher


class DetachedTaskHandoffTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name)
        self.original_state_runtime = state.RUNTIME_DIR
        self.original_watchdog_runtime = watchdog.RUNTIME_DIR
        state.RUNTIME_DIR = self.runtime
        watchdog.RUNTIME_DIR = self.runtime
        self.env = patch.dict(os.environ, {
            "SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1",
            "SHOPVIVALIZ_RESUME_OWNERSHIP_TTL_SECONDS": "90",
        })
        self.env.start()

    def tearDown(self) -> None:
        self.env.stop()
        state.RUNTIME_DIR = self.original_state_runtime
        watchdog.RUNTIME_DIR = self.original_watchdog_runtime
        self.temp.cleanup()

    def task(self, agent: str = "claude-sonnet-5-5") -> dict:
        state.start_task("real-source-task", "finish validated recovery task", agent)
        return state.record_progress(
            "real-source-task", next_action="Execute the original pending E2E journey"
        )

    def authorize(self, payload: dict) -> dict:
        return state.authorize_detached_route(
            "real-source-task",
            expected_checkpoint_version=payload["checkpoint_version"],
            authorized_by="owner-approved-scope-20261106",
            reason="Continue exactly this stalled Claude task without any ChatGPT conversation mutation",
        )

    def test_unbound_native_task_does_not_enqueue_without_owner_opt_in(self):
        self.task()
        result = watchdog.run_once(runtime_dir=self.runtime, proactive=True)
        self.assertEqual(result["dispatched"], 0)
        self.assertEqual(result["skipped_unbound_origin"], 1)
        self.assertEqual(watchdog.read_requests(self.runtime), [])

    def test_opted_in_native_task_queues_only_cli_with_source_provenance(self):
        original = self.task()
        self.authorize(original)
        result = watchdog.run_once(runtime_dir=self.runtime, proactive=True)
        self.assertEqual(result["dispatched"], 1)
        request = watchdog.read_requests(self.runtime)[0]
        self.assertEqual(request["execution_route"], "detached")
        self.assertEqual(request["previous_agent_id"], "claude-sonnet-5-5")
        self.assertEqual(request["preferred_executor"], "cli")
        self.assertEqual(request["executor_order"], ["cli"])
        self.assertNotIn("conversation_id", request)
        self.assertNotIn("browser_session", request)
        self.assertEqual(watchdog.run_once(runtime_dir=self.runtime, proactive=True)["dispatched"], 0)

    def test_authorization_cas_rejects_wrong_version_and_chatgpt_origin(self):
        source = self.task()
        with self.assertRaises(state.TaskStateError):
            state.authorize_detached_route(
                "real-source-task",
                expected_checkpoint_version=source["checkpoint_version"] - 1,
                authorized_by="owner",
                reason="Try stale unsafe authorization",
            )
        state.start_task("gpt-task", "do not retarget to provider", "gpt")
        with self.assertRaises(state.TaskStateError):
            state.authorize_detached_route(
                "gpt-task", expected_checkpoint_version=1,
                authorized_by="owner",
                reason="ChatGPT requires its original conversation binding",
            )

    def test_task_scoped_runtime_lease_prevents_double_execution(self):
        source = self.task()
        self.authorize(source)
        claimed = state.claim_detached_recovery_ownership(
            "real-source-task", owner_id="one", ttl_seconds=60
        )
        self.assertTrue(state.verify_detached_recovery_ownership(
            "real-source-task", owner_id="one",
            lease_id=claimed["runtime_lease_id"],
            fencing_token=claimed["runtime_fencing_token"],
            checkpoint_version=claimed["recovery_checkpoint_version"],
        ))
        with self.assertRaises(state.TaskStateError):
            state.claim_detached_recovery_ownership(
                "real-source-task", owner_id="two", ttl_seconds=60
            )
        with self.assertRaises(state.TaskStateError):
            state.verify_detached_recovery_ownership(
                "real-source-task", owner_id="one",
                lease_id=claimed["runtime_lease_id"],
                fencing_token=claimed["runtime_fencing_token"] + 1,
                checkpoint_version=claimed["recovery_checkpoint_version"],
            )
        state.release_detached_recovery_ownership(
            "real-source-task", owner_id="one", reason="test"
        )
        with self.assertRaises(state.TaskStateError):
            state.verify_detached_recovery_ownership(
                "real-source-task", owner_id="one",
                lease_id=claimed["runtime_lease_id"],
                fencing_token=claimed["runtime_fencing_token"],
                checkpoint_version=claimed["recovery_checkpoint_version"],
            )

    def test_shell_rejects_forged_detached_metadata_before_any_provider(self):
        original = self.task()
        self.authorize(original)
        prompt = self.runtime / "instructions.txt"
        prompt.write_text("test only", encoding="utf-8")
        env = dict(os.environ)
        env.update({
            "SHOPVIVALIZ_AGENT_TASK_STATE_DIR": str(self.runtime),
            "SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1",
            "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
            "SHOPVIVALIZ_RESUME_EXECUTION_ROUTE": "detached",
            "SHOPVIVALIZ_TASK_ID": "real-source-task",
            "SHOPVIVALIZ_RESUME_OWNER_ID": "forged",
            "SHOPVIVALIZ_RESUME_RUNTIME_LEASE_ID": "invalid-lease",
            "SHOPVIVALIZ_RESUME_RUNTIME_FENCING_TOKEN": "77",
            "SHOPVIVALIZ_RESUME_CHECKPOINT_VERSION": "77",
        })
        script = Path(__file__).resolve().parents[1] / "scripts" / "autonomous-provider-failover.sh"
        result = subprocess.run(
            ["bash", str(script), str(prompt)], cwd=self.runtime, env=env,
            capture_output=True, text=True, timeout=6, check=False,
        )
        self.assertEqual(result.returncode, 76)
        self.assertIn("detached task lease verification failed", result.stderr)

    def test_shell_does_not_weaken_bound_chatgpt_gate(self):
        prompt = self.runtime / "instructions.txt"
        prompt.write_text("test only", encoding="utf-8")
        env = dict(os.environ)
        env.update({
            "SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1",
            "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
            "SHOPVIVALIZ_RESUME_EXECUTION_ROUTE": "chatgpt",
            "SHOPVIVALIZ_RESUME_CHECKPOINT_VERSION": "2",
            "SHOPVIVALIZ_RESUME_OWNER_ID": "test",
            "SHOPVIVALIZ_RESUME_RUNTIME_LEASE_ID": "fake",
            "SHOPVIVALIZ_RESUME_RUNTIME_FENCING_TOKEN": "1",
        })
        script = Path(__file__).resolve().parents[1] / "scripts" / "autonomous-provider-failover.sh"
        result = subprocess.run(
            ["bash", str(script), str(prompt)], cwd=self.runtime, env=env,
            capture_output=True, text=True, timeout=6, check=False,
        )
        self.assertEqual(result.returncode, 76)
        self.assertIn("durable resume ownership metadata missing", result.stderr)

    def test_real_detached_worker_advances_existing_checkpoint_under_lease(self):
        original = self.task()
        self.authorize(original)
        self.assertEqual(watchdog.run_once(runtime_dir=self.runtime, proactive=True)["dispatched"], 1)
        cli = Path(__file__).resolve().parents[1] / "scripts" / "agent_task_state.py"
        worker = self.runtime / "worker.sh"
        worker.write_text(
            "#!/bin/bash\n"
            f"python3 '{cli}' progress --task real-source-task "
            "--next-action 'Validate corrected integration with UI evidence' "
            "--evidence 'bounded deterministic recovery regression' > /dev/null\n",
            encoding="utf-8",
        )
        result = dispatcher.run_once(
            runtime_dir=self.runtime, project_dir=self.runtime,
            executor=["bash", str(worker)], timeout_seconds=8,
        )
        self.assertEqual(result["executed"], 1)
        self.assertEqual(result["progressed"], 1)
        after = state.load_task("real-source-task")
        self.assertEqual(after["agent_id"], "claude-sonnet-5-5")
        self.assertEqual(after["execution_route"], "detached")
        self.assertEqual(after["next_action"], "Validate corrected integration with UI evidence")
        self.assertEqual(after.get("status"), "RUNNING")

    def test_detached_dispatcher_does_not_require_fictitious_browser_binding(self):
        source = self.task()
        self.authorize(source)
        self.assertEqual(watchdog.run_once(runtime_dir=self.runtime, proactive=True)["dispatched"], 1)
        report = dispatcher.run_once(
            runtime_dir=self.runtime,
            project_dir=self.runtime,
            executor=["/bin/true"],
            timeout_seconds=5,
        )
        self.assertEqual(report["executed"], 1)
        self.assertEqual(report["deferred_unbound"], 0)
        self.assertEqual(report["deferred_unbound_session"], 0)
        self.assertEqual(report["no_progress"], 1)
        self.assertEqual(state.load_task("real-source-task")["status"], "RUNNING")


if __name__ == "__main__":
    unittest.main()
