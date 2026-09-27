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
            "human_authorized_executors": ["codex"],
            "human_authorizations": [{"executor": "codex", "evidence": "explicit user authorization"}],
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
    "authorized_executors": os.environ.get("SHOPVIVALIZ_RESUME_HUMAN_AUTHORIZED_EXECUTORS"),
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
        self.assertEqual(capture["authorized_executors"], "codex")
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

    def test_detached_prompt_names_the_headless_approved_task_state_command(self) -> None:
        dispatcher = (SCRIPTS / "task_resume_dispatcher.py").read_text(encoding="utf-8")
        self.assertIn("python3 scripts/agent_task_state.py ready", dispatcher)
        self.assertIn("python3 scripts/agent_task_state.py complete", dispatcher)
        self.assertIn("python3 scripts/agent_task_state.py progress", dispatcher)

    def test_background_resume_may_use_codex_only_when_human_authorized(self) -> None:
        failover = (SCRIPTS / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("SHOPVIVALIZ_RESUME_HUMAN_AUTHORIZED_EXECUTORS", failover)
        self.assertIn("background_human_authorized_codex=true", failover)
        self.assertIn("ORDER=(codex gemini)", failover)
        self.assertIn("env -u OPENAI_API_KEY -u CODEX_API_KEY codex exec", failover)

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


if __name__ == "__main__":
    unittest.main()
