"""MANDATORY_AGENT_CHECKLIST_V1: negative + end-to-end tests for all agent families."""
from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import agent_task_state as state

SPEC = importlib.util.spec_from_file_location(
    "check_universal_agent_policy", ROOT / "scripts/check_universal_agent_policy.py"
)
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)


class MandatoryChecklistTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = Path(self.temp.name)
        self.addCleanup(setattr, state, "RUNTIME_DIR", self.original_runtime)
        self.env = mock.patch.dict(os.environ, {"SHOPVIVALIZ_REQUIRE_AGENT_CHECKLIST": "1"})
        self.env.start()
        self.addCleanup(self.env.stop)

    def new(self, name="plan-test", agent="claude"):
        return state.start_task(name, "deliver verified task", agent)

    def test_every_agent_family_is_subject_to_the_same_strict_gate(self):
        for index, agent in enumerate(("claude", "gemini", "codex", "gpt6", "copilot", "gepeto", "worker")):
            with self.subTest(agent=agent):
                task = state.start_task("agent-" + str(index), "finish without abandoned steps", agent)
                self.assertEqual(task["checklist"], {"schema_version": 1, "required": True, "items": []})
                with self.assertRaisesRegex(state.TaskStateError, "explicit action plan"):
                    state.mark_ready(task["task_id"], evidence=["not proven"], verification="not proven")

    def test_progress_and_ready_fail_when_no_plan_exists(self):
        self.new()
        with self.assertRaisesRegex(state.TaskStateError, "plan required"):
            state.record_progress("plan-test", next_action="pretend progress", evidence="claim")
        with self.assertRaisesRegex(state.TaskStateError, "explicit action plan"):
            state.mark_ready("plan-test", evidence=["claim"], verification="claim")
        self.assertEqual(state.load_task("plan-test")["status"], "RUNNING")

    def test_cannot_skip_steps_and_completion_requires_evidence(self):
        self.new()
        state.set_checklist("plan-test", steps=["investigate actual state", "repair and validate runtime"])
        with self.assertRaisesRegex(state.TaskStateError, "cannot skip"):
            state.complete_checklist_step("plan-test", step_id=2, evidence="valid source", verification="readback")
        with self.assertRaisesRegex(state.TaskStateError, "actual evidence"):
            state.complete_checklist_step("plan-test", step_id=1, evidence="", verification="done")
        state.complete_checklist_step("plan-test", step_id=1, evidence="log#123", verification="independent result")
        current = state.load_task("plan-test")
        self.assertEqual(current["checklist"]["items"][0]["status"], "CONCLUIDO")
        self.assertEqual(current["checklist"]["items"][1]["status"], "PENDENTE")
        self.assertEqual(current["next_action"], "repair and validate runtime")
        with self.assertRaisesRegex(state.TaskStateError, "step 2"):
            state.mark_ready("plan-test", evidence=["claim"], verification="claim")
        state.complete_checklist_step("plan-test", step_id=2, evidence="E2E real PASS", verification="runtime SHA checked")
        state.mark_ready("plan-test", evidence=["E2E final proof"], verification="original goal confirmed")
        self.assertEqual(state.complete_task("plan-test")["status"], "CONCLUIDO")
        self.assertEqual(state.load_task("plan-test")["checklist"]["items"][1]["status"], "CONCLUIDO")

    def test_immutable_plan_preserves_checked_work_and_new_discovery(self):
        self.new()
        initial = state.set_checklist("plan-test", steps=["one", "two"])
        self.assertEqual(state.set_checklist("plan-test", steps=["one", "two"]), initial)
        with self.assertRaisesRegex(state.TaskStateError, "immutable"):
            state.set_checklist("plan-test", steps=["renamed"])
        state.complete_checklist_step("plan-test", step_id=1, evidence="artifact-1", verification="sha-1")
        with self.assertRaisesRegex(state.TaskStateError, "immutable"):
            state.complete_checklist_step("plan-test", step_id=1, evidence="forged", verification="sha-2")
        state.add_checklist_step("plan-test", description="new issue found")
        self.assertEqual(len(state.load_task("plan-test")["checklist"]["items"]), 3)
        state.complete_checklist_step("plan-test", step_id=2, evidence="artifact-2", verification="sha-2")
        with self.assertRaisesRegex(state.TaskStateError, "step 3"):
            state.mark_ready("plan-test", evidence=["claim"], verification="claim")
        self.assertEqual(state.load_task("plan-test")["status"], "RUNNING")

    def test_forged_completed_flag_without_proof_fails_closed(self):
        self.new()
        state.set_checklist("plan-test", steps=["verify security controls"])
        path = state.RUNTIME_DIR / "plan-test.json"
        row = json.loads(path.read_text())
        row["checklist"]["items"][0]["status"] = "CONCLUIDO"
        row["checklist"]["items"][0]["completed_at"] = "2026-10-31T00:00:00Z"
        path.write_text(json.dumps(row))
        with self.assertRaisesRegex(state.TaskStateError, "not certified"):
            state.mark_ready("plan-test", evidence=["claimed"], verification="claimed")
        self.assertEqual(state.load_task("plan-test")["status"], "RUNNING")

    def test_completed_task_cannot_be_replanned(self):
        self.new()
        state.set_checklist("plan-test", steps=["one"])
        state.complete_checklist_step("plan-test", step_id=1, evidence="real", verification="checked")
        state.mark_ready("plan-test", evidence=["final"], verification="final")
        state.complete_task("plan-test")
        with self.assertRaisesRegex(state.TaskStateError, "RUNNING"):
            state.add_checklist_step("plan-test", description="rewrite finished proof")

    def test_cli_entire_lifecycle_persists_between_processes(self):
        script = ROOT / "scripts/agent_task_state.py"
        env = {**os.environ, "SHOPVIVALIZ_AGENT_TASK_STATE_DIR": self.temp.name,
               "SHOPVIVALIZ_REQUIRE_AGENT_CHECKLIST": "1"}
        def run(*argv, expected=0):
            result = subprocess.run([sys.executable, str(script), *argv], env=env,
                                    text=True, capture_output=True, timeout=30)
            self.assertEqual(result.returncode, expected, result.stdout + result.stderr)
            return json.loads(result.stdout)
        run("start", "--task", "subprocess-case", "--goal", "business proof", "--agent", "codex")
        run("plan-checklist", "--task", "subprocess-case", "--step", "capture",
            "--step", "validate")
        row = run("next-step", "--task", "subprocess-case")
        self.assertEqual(row["remaining"], 2)
        self.assertEqual(row["next_step"]["description"], "capture")
        run("check-step", "--task", "subprocess-case", "--step-id", "1",
            "--evidence", "trace artifact", "--verification", "file verified")
        row = run("next-step", "--task", "subprocess-case")
        self.assertEqual(row["remaining"], 1)
        run("check-step", "--task", "subprocess-case", "--step-id", "2",
            "--evidence", "real result", "--verification", "independent reviewer")
        self.assertEqual(run("next-step", "--task", "subprocess-case")["remaining"], 0)
        run("ready", "--task", "subprocess-case", "--evidence", "goal verified",
            "--verification", "independent goal review")
        run("complete", "--task", "subprocess-case")
        self.assertEqual(run("terminal", "--task", "subprocess-case")["status"], "CONCLUIDO")

    def test_existing_legacy_states_remain_without_implicit_mutation(self):
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_REQUIRE_AGENT_CHECKLIST": "0"}):
            task = state.start_task("legacy-case", "legacy goal", "worker")
        self.assertNotIn("checklist", task)
        self.assertEqual(state.start_task("legacy-case", "legacy goal", "worker"), task)


class MandatoryPolicyTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for rel in policy.REQUIRED:
            path = self.root / rel
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(policy.CHECKLIST_MARKER + "\n" + policy.MARKER +
                            "\nMCP/Remote Control GitHub CLI/API lease/fencing nao contornar MFA checkpoint")
        state_file = self.root / "scripts/agent_task_state.py"
        state_file.parent.mkdir(parents=True)
        state_file.write_text(" ".join(("MANDATORY_AGENT_CHECKLIST_V1", "set_checklist",
                                       "complete_checklist_step", "_require_checklist_complete",
                                       "add_checklist_step", "CANONICAL_CONTROLLER_RUNTIME_DIR")))
        flow = self.root / ".github/workflows/mandatory-validation-gate.yml"
        flow.parent.mkdir(parents=True, exist_ok=True)
        flow.write_text("tests.test_mandatory_agent_checklist")

    def test_policy_contract_accepts_full_surface(self):
        self.assertEqual(policy.validate(self.root), [])

    def test_removed_agent_marker_fails_closed(self):
        path = self.root / "GEMINI.md"
        path.write_text("not governed")
        self.assertTrue(any("GEMINI.md" in error for error in policy.validate(self.root)))

    def test_missing_required_ci_test_fails_closed(self):
        path = self.root / ".github/workflows/mandatory-validation-gate.yml"
        path.write_text("skipped")
        self.assertTrue(any("Required PR validation" in error for error in policy.validate(self.root)))

    def test_removed_runtime_enforcement_fails_closed(self):
        path = self.root / "scripts/agent_task_state.py"
        path.write_text("legacy without guard")
        self.assertTrue(any("Missing checklist state invariant" in error for error in policy.validate(self.root)))


if __name__ == "__main__":
    unittest.main(verbosity=2)
