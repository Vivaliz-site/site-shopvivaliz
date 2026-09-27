from __future__ import annotations

import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / "scripts"
DISPATCHER_PATH = SCRIPTS / "task_resume_dispatcher.py"
sys.path.insert(0, str(SCRIPTS))


def load_dispatcher():
    spec = importlib.util.spec_from_file_location("task_resume_dispatcher_test", DISPATCHER_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load task resume dispatcher")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class DetachedTaskResumeTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.runtime = self.root / "state"
        self.runtime.mkdir()
        self.project = self.root / "project"
        self.project.mkdir()
        (self.project / "scripts").mkdir()
        self.capture = self.root / "capture.json"

    def tearDown(self) -> None:
        self.temp.cleanup()

    def _state(self, *, updated_at: str = "2026-09-26T20:00:00Z", next_action: str = "continue real work") -> dict:
        payload = {
            "schema_version": 1,
            "task_id": "resume-e2e",
            "agent_id": "chatgpt-common",
            "goal": "finish without abandonment",
            "status": "RUNNING",
            "next_action": next_action,
            "evidence": ["checkpoint before interrupted stream"],
            "verification": None,
            "blocker": None,
            "created_at": "2026-09-26T19:59:00Z",
            "updated_at": updated_at,
            "history": [],
        }
        (self.runtime / "resume-e2e.json").write_text(json.dumps(payload), encoding="utf-8")
        return payload

    def _request(self, state: dict) -> None:
        row = {
            "id": "resume-test-fingerprint",
            "kind": "auto_resume",
            "status": "queued",
            "task_id": "resume-e2e",
            "agent_id": "gpt",
            "goal": state["goal"],
            "next_action": state["next_action"],
            "checkpoint_updated_at": state["updated_at"],
            "fingerprint": "fingerprint-v1",
            "preferred_executor": "chatgpt_common",
            "secondary_executor": "chatgpt_work",
            "final_fallback": "cli",
            "executor_order": ["chatgpt_common", "chatgpt_work", "cli"],
            "fallback_policy": "chatgpt_common_then_work_then_cli",
        }
        (self.runtime / "_resume-requests.jsonl").write_text(json.dumps(row) + "\n", encoding="utf-8")

    def _executor(self, *, advance: bool) -> list[str]:
        script = self.root / ("advance.py" if advance else "noop.py")
        if advance:
            body = """
import json, os, sys
from pathlib import Path
runtime = Path(os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"])
task_id = os.environ["SHOPVIVALIZ_TASK_ID"]
state_path = runtime / f"{task_id}.json"
state = json.loads(state_path.read_text())
capture = {
    "resume_stage": os.environ.get("SHOPVIVALIZ_RESUME_STAGE"),
    "result_mode": os.environ.get("SHOPVIVALIZ_RESUME_RESULT_MODE"),
    "background_mode": os.environ.get("SHOPVIVALIZ_RESUME_BACKGROUND"),
    "task_id": task_id,
    "prompt": Path(sys.argv[1]).read_text(),
}
Path(os.environ["CAPTURE_PATH"]).write_text(json.dumps(capture))
state["next_action"] = "continue from detached executor checkpoint"
state["updated_at"] = "2026-09-26T20:05:00Z"
state_path.write_text(json.dumps(state))
"""
        else:
            body = """
import os, sys
from pathlib import Path
Path(os.environ["CAPTURE_PATH"]).write_text(Path(sys.argv[1]).read_text())
"""
        script.write_text(body, encoding="utf-8")
        return [sys.executable, str(script)]

    def test_dispatcher_executes_matching_checkpoint_and_requires_real_state_advance(self) -> None:
        dispatcher = load_dispatcher()
        state = self._state()
        self._request(state)
        old_capture = os.environ.get("CAPTURE_PATH")
        os.environ["CAPTURE_PATH"] = str(self.capture)
        try:
            result = dispatcher.run_once(
                runtime_dir=self.runtime,
                project_dir=self.project,
                executor=self._executor(advance=True),
                timeout_seconds=30,
                max_requests=1,
            )
        finally:
            if old_capture is None:
                os.environ.pop("CAPTURE_PATH", None)
            else:
                os.environ["CAPTURE_PATH"] = old_capture

        self.assertEqual(result["executed"], 1)
        self.assertEqual(result["progressed"], 1)
        self.assertEqual(result["no_progress"], 0)
        capture = json.loads(self.capture.read_text())
        self.assertEqual(capture["resume_stage"], "cli_last")
        self.assertEqual(capture["result_mode"], "task_state")
        self.assertEqual(capture["background_mode"], "1")
        self.assertEqual(capture["task_id"], "resume-e2e")
        self.assertIn("finish without abandonment", capture["prompt"])
        self.assertIn("continue real work", capture["prompt"])

        ledger = [
            json.loads(line)
            for line in (self.runtime / "_resume-executions.jsonl").read_text().splitlines()
            if line.strip()
        ]
        self.assertEqual(ledger[-1]["result"], "progress")
        self.assertEqual(ledger[-1]["fingerprint"], "fingerprint-v1")

        second = dispatcher.run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=self._executor(advance=True),
            timeout_seconds=30,
            max_requests=1,
        )
        self.assertEqual(second["executed"], 0)

    def test_success_exit_without_checkpoint_advance_is_not_counted_as_resume(self) -> None:
        dispatcher = load_dispatcher()
        state = self._state()
        self._request(state)
        old_capture = os.environ.get("CAPTURE_PATH")
        os.environ["CAPTURE_PATH"] = str(self.capture)
        try:
            result = dispatcher.run_once(
                runtime_dir=self.runtime,
                project_dir=self.project,
                executor=self._executor(advance=False),
                timeout_seconds=30,
                max_requests=1,
            )
        finally:
            if old_capture is None:
                os.environ.pop("CAPTURE_PATH", None)
            else:
                os.environ["CAPTURE_PATH"] = old_capture

        self.assertEqual(result["executed"], 1)
        self.assertEqual(result["progressed"], 0)
        self.assertEqual(result["no_progress"], 1)
        current = json.loads((self.runtime / "resume-e2e.json").read_text())
        self.assertEqual(current["next_action"], "continue real work")
        ledger = [
            json.loads(line)
            for line in (self.runtime / "_resume-executions.jsonl").read_text().splitlines()
            if line.strip()
        ]
        self.assertEqual(ledger[-1]["result"], "no_progress")

        second = dispatcher.run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=self._executor(advance=False),
            timeout_seconds=30,
            max_requests=1,
        )
        self.assertEqual(second["executed"], 0)

        retried = dispatcher.run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=self._executor(advance=False),
            timeout_seconds=30,
            max_requests=1,
            retry_after_seconds=0,
        )
        self.assertEqual(retried["executed"], 1)
        self.assertEqual(retried["no_progress"], 1)

    def test_recent_successful_chatgpt_nudge_defers_detached_executor(self) -> None:
        dispatcher = load_dispatcher()
        state = self._state()
        self._request(state)
        now = dispatcher.utc_now()
        (self.runtime / "_chatgpt-continuity-nudges.jsonl").write_text(
            json.dumps(
                {
                    "fingerprint": "fingerprint-v1",
                    "task_id": "resume-e2e",
                    "repository": "Vivaliz-site/site-shopvivaliz",
                    "dispatched_at": now,
                    "bridge_ok": True,
                    "http_status": 200,
                }
            )
            + "\n",
            encoding="utf-8",
        )

        result = dispatcher.run_once(
            runtime_dir=self.runtime,
            project_dir=self.project,
            executor=self._executor(advance=True),
            timeout_seconds=30,
            max_requests=1,
        )

        self.assertEqual(result["executed"], 0)
        self.assertEqual(result.get("deferred_chatgpt"), 1)
        self.assertFalse(self.capture.exists(), "detached executor must not run while ChatGPT gets first recovery window")

    def test_old_or_failed_chatgpt_nudge_does_not_block_detached_fallback_forever(self) -> None:
        dispatcher = load_dispatcher()
        state = self._state()
        self._request(state)
        (self.runtime / "_chatgpt-continuity-nudges.jsonl").write_text(
            "\n".join(
                [
                    json.dumps(
                        {
                            "fingerprint": "fingerprint-v1",
                            "task_id": "resume-e2e",
                            "repository": "Vivaliz-site/site-shopvivaliz",
                            "dispatched_at": "2020-01-01T00:00:00Z",
                            "bridge_ok": True,
                            "http_status": 200,
                        }
                    ),
                    json.dumps(
                        {
                            "fingerprint": "fingerprint-v1",
                            "task_id": "resume-e2e",
                            "repository": "Vivaliz-site/site-shopvivaliz",
                            "dispatched_at": dispatcher.utc_now(),
                            "bridge_ok": False,
                            "http_status": 0,
                        }
                    ),
                ]
            )
            + "\n",
            encoding="utf-8",
        )
        old_capture = os.environ.get("CAPTURE_PATH")
        os.environ["CAPTURE_PATH"] = str(self.capture)
        try:
            result = dispatcher.run_once(
                runtime_dir=self.runtime,
                project_dir=self.project,
                executor=self._executor(advance=True),
                timeout_seconds=30,
                max_requests=1,
            )
        finally:
            if old_capture is None:
                os.environ.pop("CAPTURE_PATH", None)
            else:
                os.environ["CAPTURE_PATH"] = old_capture

        self.assertEqual(result["executed"], 1)
        self.assertEqual(result["progressed"], 1)

    def test_detached_prompt_names_the_headless_approved_task_state_command(self) -> None:
        dispatcher = (SCRIPTS / "task_resume_dispatcher.py").read_text(encoding="utf-8")
        self.assertIn("python3 scripts/agent_task_state.py ready", dispatcher)
        self.assertIn("python3 scripts/agent_task_state.py complete", dispatcher)
        self.assertIn("python3 scripts/agent_task_state.py progress", dispatcher)

    def test_background_resume_cannot_fall_through_to_paid_cli_providers(self) -> None:
        failover = (SCRIPTS / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("SHOPVIVALIZ_RESUME_BACKGROUND", failover)
        self.assertIn("BACKGROUND_ORDER=(gemini)", failover)
        self.assertIn('ORDER=("${BACKGROUND_ORDER[@]}")', failover)
        self.assertIn("background_paid_fallback_forbidden=true", failover)

    def test_autonomous_loop_re_resolves_current_release_every_cycle(self) -> None:
        loop = (SCRIPTS / "autonomous-agent-loop.sh").read_text(encoding="utf-8")
        self.assertGreaterEqual(loop.count('cd "$PROJECT_DIR"'), 2)
        cycle = loop.split("run_cycle() {", 1)[1]
        self.assertLess(cycle.index('cd "$PROJECT_DIR"'), cycle.index("autonomous-continuous-cycle.py"))

    def test_autonomous_loop_reexecs_when_current_release_changes(self) -> None:
        loop = (SCRIPTS / "autonomous-agent-loop.sh").read_text(encoding="utf-8")
        self.assertIn("START_SCRIPT_REALPATH", loop)
        self.assertIn("CURRENT_SCRIPT_REALPATH", loop)
        self.assertIn("SHOPVIVALIZ_AGENT_REEXEC", loop)
        self.assertIn('exec /bin/bash "$PROJECT_DIR/scripts/autonomous-agent-loop.sh"', loop)
        cycle = loop.split("run_cycle() {", 1)[1]
        self.assertLess(
            cycle.index("CURRENT_SCRIPT_REALPATH"),
            cycle.index("autonomous-continuous-cycle.py"),
        )

    def test_autonomous_loop_orders_watchdog_dispatcher_then_worker(self) -> None:
        loop = (SCRIPTS / "autonomous-agent-loop.sh").read_text(encoding="utf-8")
        watchdog = loop.index("task_continuation_watchdog.py")
        dispatcher = loop.index("task_resume_dispatcher.py")
        worker = loop.index("agent-operations-worker.py")
        self.assertLess(watchdog, dispatcher)
        self.assertLess(dispatcher, worker)

    def test_policy_requires_detached_resume_contract(self) -> None:
        docs = (ROOT / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        validator = (SCRIPTS / "validate-task-continuity-enforcement.py").read_text(encoding="utf-8")
        failover = (SCRIPTS / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("DETACHED_CONTINUATION_EXECUTOR_V6", docs)
        self.assertIn("task_resume_dispatcher.py", validator)
        self.assertIn("tests.test_task_resume_dispatcher", validator)
        self.assertIn("SHOPVIVALIZ_RESUME_RESULT_MODE", failover)
        self.assertIn("task_state", failover)
    def test_executor_artifact_summary_is_sanitized_and_structured(self) -> None:
        dispatcher = load_dispatcher()
        workspace = self.root / "workspace"
        logs = workspace / "logs"
        logs.mkdir(parents=True)
        raw = (
            "prompt body must never persist\n"
            "SECRET_TOKEN=super-secret-value\n"
            "background_paid_fallback_forbidden=true\n"
            "background_gemini_error=tool_denied\n"
            "background_gemini_exit_code=75\n"
            "background_gemini_model=gemini-flash-latest\n"
        )
        (logs / "autonomous-provider-output.txt").write_text(raw, encoding="utf-8")
        (logs / "autonomous-provider-attempts.jsonl").write_text(
            json.dumps(
                {
                    "provider": "gemini",
                    "status": "no_task_state_progress",
                    "exit_code": 0,
                    "timestamp": "2026-09-27T02:00:00Z",
                }
            )
            + "\n",
            encoding="utf-8",
        )

        diagnostic = dispatcher._summarize_executor_artifacts(workspace)

        self.assertEqual(diagnostic["provider"], "gemini")
        self.assertEqual(diagnostic["provider_status"], "no_task_state_progress")
        self.assertEqual(diagnostic["provider_attempt_exit_code"], 0)
        self.assertEqual(diagnostic["background_gemini_error"], "tool_denied")
        self.assertEqual(diagnostic["background_gemini_exit_code"], 75)
        self.assertEqual(diagnostic["background_gemini_model"], "gemini-flash-latest")
        self.assertTrue(diagnostic["background_paid_fallback_forbidden"])
        self.assertGreater(diagnostic["provider_output_bytes"], 0)
        self.assertEqual(len(diagnostic["provider_output_sha256"]), 64)
        serialized = json.dumps(diagnostic, sort_keys=True)
        self.assertNotIn("super-secret-value", serialized)
        self.assertNotIn("prompt body", serialized)

    def test_executor_artifact_summary_extracts_sanitized_failure_reason(self) -> None:
        dispatcher = load_dispatcher()
        workspace = self.root / "workspace-reason"
        logs = workspace / "logs"
        logs.mkdir(parents=True)
        raw = (
            "Waiting for user confirmation to run this command\n"
            "background_gemini_reason=approval_required\n"
            "background_gemini_exit_code=1\n"
        )
        (logs / "autonomous-provider-output.txt").write_text(raw, encoding="utf-8")

        diagnostic = dispatcher._summarize_executor_artifacts(workspace)

        self.assertEqual(diagnostic["background_gemini_reason"], "approval_required")
        serialized = json.dumps(diagnostic, sort_keys=True)
        self.assertNotIn("Waiting for user confirmation", serialized)
        self.assertNotIn("SECRET_TOKEN", serialized)

    def test_run_once_persists_structured_diagnostic_in_ledger(self) -> None:
        dispatcher = load_dispatcher()
        state = self._state()
        self._request(state)
        original_execute = dispatcher._execute
        try:
            dispatcher._execute = lambda **kwargs: (
                "no_progress",
                75,
                state,
                {
                    "provider": "gemini",
                    "provider_status": "no_task_state_progress",
                    "provider_output_sha256": "a" * 64,
                    "provider_output_bytes": 123,
                },
            )
            result = dispatcher.run_once(
                runtime_dir=self.runtime,
                project_dir=self.project,
                timeout_seconds=30,
                max_requests=1,
            )
        finally:
            dispatcher._execute = original_execute

        self.assertEqual(result["no_progress"], 1)
        ledger = [
            json.loads(line)
            for line in (self.runtime / "_resume-executions.jsonl").read_text().splitlines()
            if line.strip()
        ]
        self.assertEqual(ledger[-1]["diagnostic"]["provider"], "gemini")
        self.assertEqual(ledger[-1]["diagnostic"]["provider_output_bytes"], 123)



if __name__ == "__main__":
    unittest.main()
