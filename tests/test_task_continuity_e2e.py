from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PROBE_PATH = ROOT / "scripts" / "task_continuity_e2e.py"


def load_probe():
    import importlib.util

    spec = importlib.util.spec_from_file_location("task_continuity_e2e_test", PROBE_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load task continuity e2e probe")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ProbeStaticContractTests(unittest.TestCase):
    def test_probe_never_imports_or_calls_internal_recovery_modules(self) -> None:
        text = PROBE_PATH.read_text(encoding="utf-8")
        self.assertNotIn("task_continuation_watchdog", text)
        self.assertNotIn("task_resume_dispatcher", text)
        self.assertNotIn("run_once(", text)
        self.assertIn("continuity_e2e_pass", text)
        self.assertIn("DETACHED_TASK_RECOVERY_E2E_V7", text)

    def test_probe_next_action_uses_only_allowlisted_commands(self) -> None:
        probe = load_probe()
        next_action = probe.build_sentinel_next_action("continuity-e2e-fixture")
        self.assertIn("python3 scripts/agent_task_state.py ready", next_action)
        self.assertIn("python3 scripts/agent_task_state.py complete", next_action)
        self.assertIn("continuity_e2e_pass", next_action)
        self.assertIn("--conversation-id", PROBE_PATH.read_text(encoding="utf-8"))
        self.assertIn("bind-conversation", PROBE_PATH.read_text(encoding="utf-8"))

    def test_workflow_has_audited_issue_trigger_for_current_tooling(self) -> None:
        workflow = (
            ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
        ).read_text(encoding="utf-8")
        self.assertIn("workflow_call:", workflow)
        self.assertIn("if: inputs.conversation_id != ''", workflow)
        self.assertIn("workflow_dispatch:", workflow)
        dispatcher = (
            ROOT / ".github" / "workflows" / "issue-comment-dispatcher.yml"
        ).read_text(encoding="utf-8")
        self.assertIn("conversation_id: ${{ steps.route.outputs.conversation_id }}", dispatcher)
        self.assertIn("conversation_id: ${{ needs.classify.outputs.conversation_id }}", dispatcher)


class ProbeWorkflowRuntimeDirTests(unittest.TestCase):
    """Regression for a real production escape: the workflow pointed the probe at
    a runtime dir that does not exist, silently falling back to the ephemeral
    Actions checkout instead of the real daemon's state directory, so the
    watchdog never saw the synthetic checkpoint (observed_request stayed False
    for the full timeout budget)."""

    def test_workflow_uses_the_exact_production_runtime_dir(self) -> None:
        import sys

        sys.path.insert(0, str(ROOT / "scripts"))
        import importlib

        agent_task_state = importlib.import_module("agent_task_state")
        expected = agent_task_state.resolve_runtime_dir(
            Path("/home/ubuntu/shopvivaliz-deploy/releases/20260101-000000-deadbeef"),
            "",
        )
        self.assertEqual(str(expected), "/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")

        workflow = (
            ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
        ).read_text(encoding="utf-8")
        self.assertIn(str(expected), workflow)
        self.assertNotIn("shared/storage/agent-task-state", workflow)

    def test_workflow_fails_closed_instead_of_silently_falling_back(self) -> None:
        workflow = (
            ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
        ).read_text(encoding="utf-8")
        self.assertNotIn('|| runtime_dir="$PWD', workflow)


class ProbeReportOutputTests(unittest.TestCase):
    def test_write_report_creates_single_valid_json_document(self) -> None:
        probe = load_probe()
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "nested" / "report.json"
            report = {
                "task_id": "continuity-e2e-fixture",
                "repository": "Vivaliz-site/site-shopvivaliz",
                "pass": True,
            }
            text = probe.write_report(report, str(path))
            self.assertEqual(json.loads(text), report)
            self.assertEqual(json.loads(path.read_text(encoding="utf-8")), report)

    def test_probe_exposes_report_path_for_global_certification(self) -> None:
        text = PROBE_PATH.read_text(encoding="utf-8")
        self.assertIn("--report-path", text)
        self.assertIn("write_report(report, args.report_path)", text)


class ProbeEvaluationTests(unittest.TestCase):
    CONVERSATION_ID = "12345678-2222-3333-4444-555555555555"
    FINGERPRINT = "f" * 64

    def _base_observation(self) -> dict:
        return {
            "task_id": "continuity-e2e-fixture",
            "repository": "Vivaliz-site/site-shopvivaliz",
            "conversation_id": self.CONVERSATION_ID,
            "observed_request": True,
            "request": {
                "task_id": "continuity-e2e-fixture",
                "repository": "Vivaliz-site/site-shopvivaliz",
                "fingerprint": self.FINGERPRINT,
            },
            "chatgpt_nudge": {
                "task_id": "continuity-e2e-fixture",
                "repository": "Vivaliz-site/site-shopvivaliz",
                "fingerprint": self.FINGERPRINT,
                "worker_status": "PROGRESS_CONFIRMED",
                "conversation_id": self.CONVERSATION_ID,
            },
            "execution": {
                "task_id": "continuity-e2e-fixture",
                "result": "terminal",
                "diagnostic": {
                    "background_paid_fallback_forbidden": True,
                    "provider": "gemini",
                },
            },
            "final_state": {
                "repository": "Vivaliz-site/site-shopvivaliz",
                "conversation_id": self.CONVERSATION_ID,
                "status": "CONCLUIDO",
                "verification": "continuity_e2e_pass",
            },
        }

    def test_browser_confirmed_bound_evidence_passes(self) -> None:
        probe = load_probe()
        ok, reasons = probe.evaluate(self._base_observation())
        self.assertTrue(ok)
        self.assertEqual(reasons, [])

    def test_detached_terminal_alone_does_not_certify_conversation_resume(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["chatgpt_nudge"] = None
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("ChatGPT nudge evidence" in reason for reason in reasons))

    def test_fails_when_worker_did_not_confirm_progress(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["chatgpt_nudge"]["worker_status"] = "SENT_UNCONFIRMED"
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("PROGRESS_CONFIRMED" in reason for reason in reasons))

    def test_fails_when_conversation_binding_differs(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["chatgpt_nudge"]["conversation_id"] = "87654321-2222-3333-4444-555555555555"
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("conversation_id" in reason for reason in reasons))

    def test_fails_when_fingerprint_is_historical_or_mismatched(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["chatgpt_nudge"]["fingerprint"] = "e" * 64
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("fingerprint" in reason for reason in reasons))

    def test_fails_when_checkpoint_does_not_reach_terminal(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["final_state"]["status"] = "RUNNING"
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("CONCLUIDO" in reason for reason in reasons))

    def test_fails_when_verification_is_missing_or_wrong(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["final_state"]["verification"] = None
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("continuity_e2e_pass" in reason for reason in reasons))

    def test_poll_requires_same_request_fingerprint_and_bound_conversation(self) -> None:
        probe = load_probe()
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp)
            task_id = "continuity-e2e-real"
            repository = "Vivaliz-site/site-shopvivaliz"
            (runtime / "_resume-requests.jsonl").write_text(
                json.dumps({"task_id": task_id, "repository": repository, "fingerprint": self.FINGERPRINT}) + "\n",
                encoding="utf-8",
            )
            (runtime / "_chatgpt-continuity-nudges.jsonl").write_text(
                json.dumps({
                    "task_id": task_id,
                    "repository": repository,
                    "fingerprint": self.FINGERPRINT,
                    "worker_status": "PROGRESS_CONFIRMED",
                    "conversation_id": self.CONVERSATION_ID,
                }) + "\n",
                encoding="utf-8",
            )
            (runtime / "_resume-executions.jsonl").write_text("", encoding="utf-8")
            (runtime / f"{task_id}.json").write_text(
                json.dumps({
                    "repository": repository,
                    "conversation_id": self.CONVERSATION_ID,
                    "status": "CONCLUIDO",
                    "verification": "continuity_e2e_pass",
                }),
                encoding="utf-8",
            )
            observation = probe.poll_for_terminal_evidence(
                runtime_dir=runtime,
                task_id=task_id,
                repository=repository,
                conversation_id=self.CONVERSATION_ID,
                timeout_seconds=1,
                poll_interval_seconds=1,
                sleep=lambda _seconds: None,
            )
            ok, reasons = probe.evaluate(observation)
            self.assertTrue(ok, reasons)

    def test_failed_synthetic_checkpoint_is_preserved_outside_active_runtime(self) -> None:
        probe = load_probe()
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp) / "agent-task-state"
            runtime.mkdir()
            task_id = "continuity-e2e-failed"
            source = runtime / f"{task_id}.json"
            source.write_text(json.dumps({"task_id": task_id, "status": "RUNNING"}), encoding="utf-8")
            destination = probe.quarantine_failed_probe(runtime_dir=runtime, task_id=task_id)
            self.assertIsNotNone(destination)
            self.assertFalse(source.exists())
            self.assertTrue(destination.is_file())
            self.assertTrue((destination.parent / f"{task_id}.sha256").is_file())

    def test_quarantine_falls_back_inside_runtime_when_archive_is_not_writable(self) -> None:
        probe = load_probe()
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp) / "agent-task-state"
            runtime.mkdir()
            task_id = "continuity-e2e-permission-fallback"
            source = runtime / f"{task_id}.json"
            source.write_text(json.dumps({"task_id": task_id, "status": "RUNNING"}), encoding="utf-8")

            original_replace = probe.os.replace
            calls = {"count": 0}

            def permission_then_replace(src, dst):
                calls["count"] += 1
                if calls["count"] == 1:
                    raise PermissionError("archive directory is not writable")
                return original_replace(src, dst)

            probe.os.replace = permission_then_replace
            try:
                destination = probe.quarantine_failed_probe(runtime_dir=runtime, task_id=task_id)
            finally:
                probe.os.replace = original_replace

            self.assertEqual(destination, runtime / f"_e2e-failure-{task_id}.json")
            self.assertFalse(source.exists())
            self.assertTrue(destination.is_file())
            digest = runtime / f"_e2e-failure-{task_id}.sha256"
            self.assertTrue(digest.is_file())
            self.assertIn(destination.name, digest.read_text(encoding="utf-8"))

    def test_quarantine_refuses_non_e2e_task(self) -> None:
        probe = load_probe()
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaises(ValueError):
                probe.quarantine_failed_probe(runtime_dir=Path(tmp), task_id="real-production-task")

    def test_create_synthetic_task_binds_before_progress(self) -> None:
        probe = load_probe()
        calls = []

        def fake_runner(argv, **kwargs):
            calls.append(list(argv))
            return None

        with tempfile.TemporaryDirectory() as tmp:
            probe.create_synthetic_task(
                runtime_dir=Path(tmp),
                task_id="continuity-e2e-bound",
                repository="Vivaliz-site/site-shopvivaliz",
                agent_task_state_script=Path("scripts/agent_task_state.py"),
                conversation_id=self.CONVERSATION_ID,
                runner=fake_runner,
            )
        commands = [call[2] for call in calls]
        self.assertEqual(commands, ["start", "bind-conversation", "progress"])
        self.assertIn(self.CONVERSATION_ID, calls[1])



class ContinuityWorkflowTopologyTest(unittest.TestCase):
    def test_production_e2e_runs_on_backend_controller_runner(self) -> None:
        workflow = (
            ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
        ).read_text(encoding="utf-8")
        self.assertIn(
            "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]",
            workflow,
        )
        self.assertNotIn(
            "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]",
            workflow,
        )


if __name__ == "__main__":
    unittest.main()
