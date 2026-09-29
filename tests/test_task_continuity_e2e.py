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

    def test_workflow_is_dispatch_only_and_router_owns_comment_command(self) -> None:
        workflow = (
            ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
        ).read_text(encoding="utf-8")
        router = (
            ROOT / ".github" / "workflows" / "comment-command-router.yml"
        ).read_text(encoding="utf-8")
        self.assertNotIn("issue_comment:", workflow)
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn('"/continuity-e2e": "continuity_e2e"', router)
        self.assertIn("task-continuity-production-e2e.yml", router)


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
    def _base_observation(self) -> dict:
        return {
            "task_id": "continuity-e2e-fixture",
            "observed_request": True,
            "execution": {
                "task_id": "continuity-e2e-fixture",
                "result": "terminal",
                "diagnostic": {
                    "background_paid_fallback_forbidden": True,
                    "provider": "gemini",
                },
            },
            "final_state": {
                "status": "CONCLUIDO",
                "verification": "continuity_e2e_pass",
            },
        }

    def test_full_real_evidence_passes(self) -> None:
        probe = load_probe()
        ok, reasons = probe.evaluate(self._base_observation())
        self.assertTrue(ok)
        self.assertEqual(reasons, [])

    def test_progress_result_is_accepted_as_valid_terminal_path(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["execution"]["result"] = "progress"
        ok, reasons = probe.evaluate(observation)
        self.assertTrue(ok)

    # --- Fault injection: each single missing/incorrect piece must fail RED ---

    def test_fails_when_no_resume_request_was_observed(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["observed_request"] = False
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("no resume request" in reason for reason in reasons))

    def test_fails_when_dispatcher_never_executed(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["execution"] = None
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("no matching execution ledger row" in reason for reason in reasons))

    def test_fails_when_ledger_result_is_no_progress(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["execution"]["result"] = "no_progress"
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("no_progress" in reason for reason in reasons))

    def test_fails_when_ledger_result_is_unrecognized(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["execution"]["result"] = "executor_error"
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)

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

    def test_fails_when_diagnostic_does_not_prove_gemini_only_background(self) -> None:
        probe = load_probe()
        observation = self._base_observation()
        observation["execution"]["diagnostic"]["background_paid_fallback_forbidden"] = False
        ok, reasons = probe.evaluate(observation)
        self.assertFalse(ok)
        self.assertTrue(any("background_paid_fallback_forbidden" in reason for reason in reasons))

    def test_ledger_row_belonging_to_a_different_task_is_not_matched(self) -> None:
        probe = load_probe()
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp)
            task_id = "continuity-e2e-real"
            (runtime / "_resume-requests.jsonl").write_text(
                json.dumps({"task_id": "some-other-task"}) + "\n", encoding="utf-8"
            )
            (runtime / "_resume-executions.jsonl").write_text(
                json.dumps({"task_id": "some-other-task", "result": "terminal"}) + "\n",
                encoding="utf-8",
            )
            (runtime / f"{task_id}.json").write_text(
                json.dumps({"status": "RUNNING"}), encoding="utf-8"
            )

            observation = probe.poll_for_terminal_evidence(
                runtime_dir=runtime,
                task_id=task_id,
                repository="Vivaliz-site/site-shopvivaliz",
                timeout_seconds=1,
                poll_interval_seconds=1,
                sleep=lambda _seconds: None,
            )
            ok, reasons = probe.evaluate(observation)
            self.assertFalse(observation["observed_request"])
            self.assertIsNone(observation["execution"])
            self.assertFalse(ok)

    def test_poll_observes_matching_task_and_stops_early_once_terminal(self) -> None:
        probe = load_probe()
        with tempfile.TemporaryDirectory() as tmp:
            runtime = Path(tmp)
            task_id = "continuity-e2e-real"
            (runtime / "_resume-requests.jsonl").write_text(
                json.dumps({"task_id": task_id}) + "\n", encoding="utf-8"
            )
            (runtime / "_resume-executions.jsonl").write_text(
                json.dumps(
                    {
                        "task_id": task_id,
                        "result": "terminal",
                        "diagnostic": {"background_paid_fallback_forbidden": True},
                    }
                )
                + "\n",
                encoding="utf-8",
            )
            (runtime / f"{task_id}.json").write_text(
                json.dumps({"status": "CONCLUIDO", "verification": "continuity_e2e_pass"}),
                encoding="utf-8",
            )

            clock = {"value": 0.0}

            def fake_now() -> float:
                return clock["value"]

            def fake_sleep(seconds: float) -> None:
                clock["value"] += seconds

            observation = probe.poll_for_terminal_evidence(
                runtime_dir=runtime,
                task_id=task_id,
                repository="Vivaliz-site/site-shopvivaliz",
                timeout_seconds=600,
                poll_interval_seconds=5,
                sleep=fake_sleep,
                now=fake_now,
            )
            ok, reasons = probe.evaluate(observation)
            self.assertTrue(ok, reasons)
            # Terminal evidence was already on disk; the loop must not have
            # needed to consume the whole timeout budget.
            self.assertLess(clock["value"], 600)


if __name__ == "__main__":
    unittest.main()
