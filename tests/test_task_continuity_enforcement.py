from __future__ import annotations

import copy
import importlib.util
import sys
import tempfile
import unittest
import time
from unittest import mock
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import agent_task_state as state  # noqa: E402



def load_operations_worker():
    path = ROOT / "scripts" / "agent-operations-worker.py"
    spec = importlib.util.spec_from_file_location("agent_operations_worker_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load agent operations worker")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class AgentTaskStateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = Path(self.temp.name)

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def test_stale_background_cannot_complete_after_foreground_progress(self) -> None:
        state.start_task("race-proof", "verify deployment", "work")
        initial = state.load_task("race-proof")
        state.record_progress("race-proof", next_action="deployment still missing")
        with mock.patch.dict(state.os.environ, {
            "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
            "SHOPVIVALIZ_RESUME_REQUEST_ID": "resume-old",
            "SHOPVIVALIZ_RESUME_HISTORY_LENGTH": str(len(initial["history"])),
        }):
            with self.assertRaisesRegex(state.TaskStateError, "stale resume"):
                state.mark_ready("race-proof", evidence=["claimed PASS"], verification="claimed done")
        self.assertEqual(state.load_task("race-proof")["status"], "RUNNING")
        self.assertEqual(state.load_task("race-proof")["next_action"], "deployment still missing")

    def test_background_cannot_certify_terminal_without_pinned_completion_checks(self) -> None:
        state.start_task("owned-proof", "verify", "work")
        with mock.patch.dict(state.os.environ, {
            "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
            "SHOPVIVALIZ_RESUME_REQUEST_ID": "resume-current",
            "SHOPVIVALIZ_RESUME_HISTORY_LENGTH": "1",
        }):
            state.record_progress("owned-proof", next_action="verify next")
            with self.assertRaisesRegex(state.TaskStateError, "background terminal certification requires pinned completion checks"):
                state.mark_ready("owned-proof", evidence=["claimed PASS"], verification="claimed fresh")
        self.assertEqual(state.load_task("owned-proof")["status"], "RUNNING")

    def test_background_can_complete_with_pinned_completion_check(self) -> None:
        artifact = Path(self.temp.name) / "background-verified"
        artifact.touch()
        state.start_task("owned-proof-checked", "verify", "work", completion_checks=[["/usr/bin/test", "-f", str(artifact)]])
        with mock.patch.dict(state.os.environ, {
            "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
            "SHOPVIVALIZ_RESUME_REQUEST_ID": "resume-current",
            "SHOPVIVALIZ_RESUME_HISTORY_LENGTH": "1",
        }):
            state.record_progress("owned-proof-checked", next_action="verify next")
            state.mark_ready("owned-proof-checked", evidence=["observed PASS"], verification="fresh")
            self.assertEqual(state.complete_task("owned-proof-checked")["status"], "CONCLUIDO")

    def test_conversation_binding_is_explicit_idempotent_and_immutable(self) -> None:
        with mock.patch.object(
            state, "utc_now",
            side_effect=["2026-10-04T00:00:00Z", "2026-10-04T00:00:01Z"],
        ):
            started = state.start_task("bound-task", "verify", "work")
            bound = state.bind_conversation("bound-task", conversation_id="6abe0e00-42d4-83e9-b145-57b927e1b89b")
        self.assertEqual(bound["conversation_id"], "6abe0e00-42d4-83e9-b145-57b927e1b89b")
        self.assertEqual(
            bound["updated_at"], started["updated_at"],
            "conversation binding is routing metadata, not task progress and must not create a new resume fingerprint",
        )
        same = state.bind_conversation("bound-task", conversation_id="6abe0e00-42d4-83e9-b145-57b927e1b89b")
        self.assertEqual(same, bound)
        with self.assertRaisesRegex(state.TaskStateError, "cannot be replaced"):
            state.bind_conversation("bound-task", conversation_id="aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee")

    def test_recovery_state_machine_suppresses_foreground_and_claims_after_expiry(self) -> None:
        state.start_task("recovery-state", "recover", "work")
        state.bind_conversation("recovery-state", conversation_id="11111111-2222-3333-4444-555555555555")
        state.bind_browser_session("recovery-state", browser_session="fred")
        version = state.load_task("recovery-state")["checkpoint_version"]
        state.acquire_foreground_lease_for_task("recovery-state", owner_id="turn-1", ttl_seconds=1)
        blocked = state.claim_recovery_ownership("recovery-state", owner_id="recovery-1", allowed_actions=["continuation_send"], ttl_seconds=30)
        self.assertEqual(blocked["recovery_state"], "FOREGROUND_ACTIVE")
        time.sleep(1.05)
        claimed = state.claim_recovery_ownership("recovery-state", owner_id="recovery-1", allowed_actions=["continuation_send"], ttl_seconds=30)
        self.assertEqual(claimed["recovery_state"], "RECOVERY_CLAIMED")
        self.assertEqual(claimed["recovery_checkpoint_version"], version)
        transitions = [row["state"] for row in claimed["recovery_history"]]
        self.assertIn("LEASE_EXPIRED", transitions)
        self.assertEqual(transitions[-1], "RECOVERY_CLAIMED")

    def test_rebind_increments_checkpoint_and_invalidates_old_recovery_owner(self) -> None:
        state.start_task("rebind-recovery", "recover", "work")
        first = state.bind_conversation("rebind-recovery", conversation_id="11111111-2222-3333-4444-555555555555")
        state.bind_browser_session("rebind-recovery", browser_session="fred")
        before = state.load_task("rebind-recovery")["checkpoint_version"]
        state.acquire_foreground_lease_for_task("rebind-recovery", owner_id="turn-1", ttl_seconds=1)
        time.sleep(1.05)
        claimed = state.claim_recovery_ownership("rebind-recovery", owner_id="recovery-1", allowed_actions=["continuation_send"], ttl_seconds=30)
        old_lease = claimed["recovery_lease_id"]
        rebound = state.rebind_conversation("rebind-recovery", conversation_id="aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee", expected_checkpoint_version=before)
        self.assertGreater(rebound["checkpoint_version"], before)
        self.assertNotIn("recovery_lease_id", rebound)
        with self.assertRaisesRegex(state.TaskStateError, "conversation|checkpoint"):
            state.record_recovery_state("rebind-recovery", state="PROGRESS_CONFIRMED", expected_conversation_id="11111111-2222-3333-4444-555555555555", expected_checkpoint_version=before, real_response_observed=True)
        self.assertTrue(old_lease)

    def test_progress_confirmed_requires_real_assistant_response(self) -> None:
        state.start_task("real-response", "recover", "work")
        state.bind_conversation("real-response", conversation_id="11111111-2222-3333-4444-555555555555")
        state.bind_browser_session("real-response", browser_session="fred")
        version = state.load_task("real-response")["checkpoint_version"]
        state.record_recovery_state("real-response", state="RECOVERY_CLAIMED", expected_conversation_id="11111111-2222-3333-4444-555555555555", expected_checkpoint_version=version)
        state.record_recovery_state("real-response", state="RECOVERY_ACTIONED", expected_conversation_id="11111111-2222-3333-4444-555555555555", expected_checkpoint_version=version)
        state.record_recovery_state("real-response", state="WAITING_FOR_REAL_RESPONSE", expected_conversation_id="11111111-2222-3333-4444-555555555555", expected_checkpoint_version=version)
        with self.assertRaisesRegex(state.TaskStateError, "real assistant response"):
            state.record_recovery_state("real-response", state="PROGRESS_CONFIRMED", expected_conversation_id="11111111-2222-3333-4444-555555555555", expected_checkpoint_version=version, real_response_observed=False)
        confirmed = state.record_recovery_state("real-response", state="PROGRESS_CONFIRMED", expected_conversation_id="11111111-2222-3333-4444-555555555555", expected_checkpoint_version=version, real_response_observed=True)
        self.assertEqual(confirmed["recovery_state"], "PROGRESS_CONFIRMED")

    def test_duplicate_background_progress_is_exact_noop(self) -> None:
        state.start_task("duplicate-background", "verify", "work")
        state.record_progress("duplicate-background", next_action="deploy corrected release", evidence="foreground evidence")
        before = state.load_task("duplicate-background")
        before_bytes = state._path("duplicate-background").read_bytes()
        with mock.patch.dict(state.os.environ, {
            "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
            "SHOPVIVALIZ_RESUME_REQUEST_ID": "resume-current",
            "SHOPVIVALIZ_RESUME_HISTORY_LENGTH": str(len(before["history"])),
        }):
            returned = state.record_progress(
                "duplicate-background",
                next_action="deploy corrected release",
                evidence="background churn that must not be persisted",
            )
        after = state.load_task("duplicate-background")
        self.assertEqual(returned, before)
        self.assertEqual(after, before)
        self.assertEqual(state._path("duplicate-background").read_bytes(), before_bytes)
        self.assertNotIn("background churn that must not be persisted", after["evidence"])

    def test_stale_background_progress_cannot_replace_foreground_action(self) -> None:
        state.start_task("stale-write", "verify", "work")
        state.record_progress("stale-write", next_action="foreground deployment")
        with mock.patch.dict(state.os.environ, {
            "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
            "SHOPVIVALIZ_RESUME_REQUEST_ID": "old",
            "SHOPVIVALIZ_RESUME_HISTORY_LENGTH": "1",
        }):
            with self.assertRaisesRegex(state.TaskStateError, "stale resume"):
                state.record_progress("stale-write", next_action="claimed done")
        self.assertEqual(state.load_task("stale-write")["next_action"], "foreground deployment")

    def test_completion_checks_do_not_create_arbitrary_execution_capability(self) -> None:
        with self.assertRaisesRegex(state.TaskStateError, "bounded read-only"):
            state.start_task("unsafe-proof", "verify", completion_checks=[[sys.executable, "-c", "print('PASS')"]])
        self.assertFalse(state._path("unsafe-proof").exists())

    def test_pinned_completion_check_rejects_unverified_claim_and_rechecks(self) -> None:
        artifact = Path(self.temp.name) / "deployed"
        command = ["/usr/bin/test", "-f", str(artifact)]
        created = state.start_task("proof-command", "verify deployment", "work", completion_checks=[command])
        self.assertEqual(created["schema_version"], 2, "older clients must reject proof-bearing tasks rather than ignore pinned checks")
        with self.assertRaisesRegex(state.TaskStateError, "completion check"):
            state.mark_ready("proof-command", evidence=["claimed PASS"], verification="claimed done")
        self.assertEqual(state.load_task("proof-command")["status"], "RUNNING")
        artifact.touch()
        ready = state.mark_ready("proof-command", evidence=["observed deploy"], verification="fresh check")
        self.assertEqual(ready["completion_check_receipts"][0]["exit_code"], 0)
        artifact.unlink()
        with self.assertRaisesRegex(state.TaskStateError, "completion check"):
            state.complete_task("proof-command")
        failed = state.load_task("proof-command")
        self.assertEqual(failed["status"], "RUNNING", "verification failure must remain eligible for automatic recovery")
        self.assertTrue(failed["next_action"])
        artifact.touch()
        state.mark_ready("proof-command", evidence=["repaired deployment"], verification="fresh recheck")
        self.assertEqual(state.complete_task("proof-command")["status"], "CONCLUIDO")

    def test_privileged_atomic_write_inherits_runtime_directory_owner(self) -> None:
        runtime = Path(self.temp.name)
        target = runtime / "root-created.json"
        parent = runtime.stat()
        chown_calls: list[tuple[Path, int, int]] = []

        def record_chown(path, uid, gid):
            chown_calls.append((Path(path), uid, gid))

        with (
            mock.patch.object(state.os, "geteuid", return_value=0),
            mock.patch.object(state.os, "chown", side_effect=record_chown),
        ):
            state._atomic_write(target, {"schema_version": state.SCHEMA_VERSION})

        self.assertEqual(len(chown_calls), 1)
        self.assertEqual((chown_calls[0][1], chown_calls[0][2]), (parent.st_uid, parent.st_gid))
        self.assertEqual(target.stat().st_mode & 0o777, 0o600)

    def test_running_task_cannot_be_completed_before_ready_gate(self) -> None:
        current = state.start_task("task-simple", "Corrigir uma tarefa simples", "gpt")
        self.assertEqual(current["status"], "RUNNING")
        self.assertFalse(state.is_terminal(current))
        self.assertTrue(current["next_action"])

        with self.assertRaises(state.TaskStateError):
            state.complete_task("task-simple")

        ready = state.mark_ready(
            "task-simple",
            evidence=["teste focal PASS"],
            verification="pedido original confrontado com estado final",
        )
        self.assertEqual(ready["status"], "READY_TO_COMPLETE")
        self.assertEqual(ready["next_action"], "")

        completed = state.complete_task("task-simple")
        self.assertEqual(completed["status"], "CONCLUIDO")
        self.assertTrue(state.is_terminal(completed))

    def test_repeated_start_is_idempotent_but_identity_collision_is_rejected(self) -> None:
        state.start_task("stable-id", "original goal", "gpt")
        state.record_progress("stable-id", next_action="keep state", evidence="must survive")

        repeated = state.start_task("stable-id", "original goal", "another-agent")
        self.assertEqual(repeated["goal"], "original goal")
        self.assertIn("must survive", repeated["evidence"])
        self.assertEqual(len(repeated["history"]), 2)

        with self.assertRaises(state.TaskStateError):
            state.start_task("stable-id", "different goal", "gpt")

        preserved = state.load_task("stable-id")
        self.assertEqual(preserved["goal"], "original goal")
        self.assertIn("must survive", preserved["evidence"])
        self.assertEqual(len(preserved["history"]), 2)

    def test_chatgpt_freeze_task_requires_browser_progress_before_ready(self) -> None:
        task_id = "chatgpt-freeze-root-cause-20260929-g4"
        state.start_task(task_id, "Prove natural ChatGPT continuity after a freeze", "gpt")

        with self.assertRaisesRegex(state.TaskStateError, "PROGRESS_CONFIRMED"):
            state.mark_ready(
                task_id,
                evidence=["code and CI are green"],
                verification="implementation looks complete",
            )

        ledger = state.RUNTIME_DIR / "_chatgpt-continuity-nudges.jsonl"
        ledger.write_text(
            '{"task_id":"chatgpt-freeze-root-cause-20260929-g4","worker_status":"PROGRESS_CONFIRMED","worker_status_observed_at":"2026-09-29T01:30:00Z"}\n',
            encoding="utf-8",
        )
        ready = state.mark_ready(
            task_id,
            evidence=["browser worker observed assistant progress"],
            verification="natural freeze recovery verified",
        )
        self.assertEqual(ready["status"], "READY_TO_COMPLETE")

        with ledger.open("a", encoding="utf-8") as handle:
            handle.write(
                '{"task_id":"chatgpt-freeze-root-cause-20260929-g4","worker_status":"STALLED_NOT_CONFIRMED","worker_status_observed_at":"2026-09-29T01:31:00Z"}\n'
            )
        with self.assertRaisesRegex(state.TaskStateError, "PROGRESS_CONFIRMED"):
            state.complete_task(task_id)

        with ledger.open("a", encoding="utf-8") as handle:
            handle.write(
                '{"task_id":"chatgpt-freeze-root-cause-20260929-g4","worker_status":"PROGRESS_CONFIRMED","worker_status_observed_at":"2026-09-29T01:32:00Z"}\n'
            )
        completed = state.complete_task(task_id)
        self.assertEqual(completed["status"], "CONCLUIDO")

    def test_non_freeze_task_does_not_require_browser_progress_ledger(self) -> None:
        state.start_task("ordinary-task", "Finish ordinary work", "gpt")
        ready = state.mark_ready(
            "ordinary-task",
            evidence=["objective check passed"],
            verification="goal verified",
        )
        self.assertEqual(ready["status"], "READY_TO_COMPLETE")

    def test_completed_task_starts_explicit_successor_without_mutating_predecessor(self) -> None:
        state.start_task("task-v1", "Investigar falha original", "gpt")
        state.bind_conversation("task-v1", conversation_id="6ac0f8b7-f2f0-83e9-95c5-54be614b9dee")
        state.mark_ready(
            "task-v1",
            evidence=["evidencia original"],
            verification="estado original verificado",
        )
        completed = state.complete_task("task-v1")
        predecessor_updated_at = completed["updated_at"]

        successor = state.start_successor_task(
            "task-v2",
            predecessor_task_id="task-v1",
            goal="Continuar investigacao apos nova evidencia",
            agent_id="gpt",
        )

        self.assertEqual(successor["status"], "RUNNING")
        self.assertEqual(successor["predecessor_task_id"], "task-v1")
        self.assertEqual(successor["predecessor_status"], "CONCLUIDO")
        self.assertEqual(successor["conversation_id"], "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee")
        self.assertTrue(successor["next_action"])
        self.assertEqual(successor["history"][0]["event"], "started_successor")

        predecessor = state.load_task("task-v1")
        self.assertEqual(predecessor["status"], "CONCLUIDO")
        self.assertEqual(predecessor["updated_at"], predecessor_updated_at)

    def test_successor_rejects_noncompleted_predecessor_and_existing_target(self) -> None:
        state.start_task("task-running", "Ainda executando", "gpt")
        with self.assertRaises(state.TaskStateError):
            state.start_successor_task(
                "task-next",
                predecessor_task_id="task-running",
                goal="Nao deve iniciar",
                agent_id="gpt",
            )

        state.mark_ready(
            "task-running",
            evidence=["done"],
            verification="verified",
        )
        state.complete_task("task-running")
        state.start_task("task-next", "Destino ja existe", "gpt")
        with self.assertRaises(state.TaskStateError):
            state.start_successor_task(
                "task-next",
                predecessor_task_id="task-running",
                goal="Nao sobrescrever",
                agent_id="gpt",
            )

    def test_block_requires_external_impediment_and_exhausted_alternatives(self) -> None:
        state.start_task("task-block", "Resolver ate bloqueio real", "gpt")

        with self.assertRaises(state.TaskStateError):
            state.block_task(
                "task-block",
                blocker={"external": False, "description": "ferramenta falhou"},
                evidence=["tool error"],
                alternatives=["retry com a mesma ferramenta", "rota alternativa"],
                resume_condition="ferramenta voltar",
            )

        with self.assertRaises(state.TaskStateError):
            state.block_task(
                "task-block",
                blocker={"external": True, "description": "servico externo indisponivel"},
                evidence=["status oficial indisponivel"],
                alternatives=["uma unica tentativa"],
                resume_condition="servico externo voltar",
            )

        blocked = state.block_task(
            "task-block",
            blocker={"external": True, "description": "servico externo indisponivel"},
            evidence=["status oficial indisponivel"],
            alternatives=["rota primaria falhou", "rota secundaria falhou"],
            resume_condition="servico externo voltar",
        )
        self.assertEqual(blocked["status"], "BLOCKED_EXTERNAL")
        self.assertTrue(state.is_terminal(blocked))

    def test_progress_keeps_task_non_terminal_and_persists_next_action(self) -> None:
        state.start_task("task-progress", "Continuar sem abandono", "claude")
        current = state.record_progress(
            "task-progress",
            next_action="executar a proxima validacao",
            evidence="diagnostico concluido",
        )
        self.assertEqual(current["status"], "RUNNING")
        self.assertEqual(current["next_action"], "executar a proxima validacao")
        self.assertFalse(state.is_terminal(current))


    def test_runtime_state_uses_shared_directory_inside_immutable_deploy(self) -> None:
        deploy_root = Path("/home/ubuntu/shopvivaliz-deploy/releases/20260926-170000-abc")
        resolved = state.resolve_runtime_dir(deploy_root, configured="")
        self.assertEqual(
            resolved,
            Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"),
        )

        for checkout in ("repo", "sync-repo"):
            with self.subTest(checkout=checkout):
                mutable_checkout = Path("/home/ubuntu/shopvivaliz-deploy") / checkout
                self.assertEqual(
                    state.resolve_runtime_dir(mutable_checkout, configured=""),
                    Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"),
                )

        local_root = Path("/tmp/site-shopvivaliz")
        local = state.resolve_runtime_dir(local_root, configured="")
        self.assertEqual(local, local_root / "storage" / "private" / "agent-task-state")


class OperationsWorkerContinuityTests(unittest.TestCase):
    def test_pending_task_gets_owner_even_when_docs_preflight_is_not_ready(self) -> None:
        worker = load_operations_worker()
        queue = {
            "queue": [
                {
                    "id": "task-preflight",
                    "title": "Tarefa simples",
                    "description": "Executar sem abandono",
                    "status": "pending",
                    "priority": "medium",
                }
            ]
        }
        saved = []
        worker.load_queue = lambda: queue
        worker.save_queue = lambda value, runtime_actor=None: saved.append(copy.deepcopy(value))
        worker.choose_agent = lambda task: "gpt"
        worker.docs_preflight = lambda agent_id, task: (False, "missing docs receipt")
        worker.push_step = lambda *args, **kwargs: None
        worker.persist_task_continuity = lambda *args, **kwargs: None

        assigned = worker.assign_pending_tasks({"agents": {}})

        self.assertEqual(queue["queue"][0]["assigned_to"], ["gpt"])
        self.assertEqual(queue["queue"][0]["execution_phase"], "docs_preflight")
        self.assertEqual(assigned[0]["agent_id"], "gpt")
        self.assertEqual(assigned[0]["phase"], "docs_preflight")
        self.assertTrue(saved, "ownership must be persisted even before docs receipt")


class TaskContinuityPolicyTests(unittest.TestCase):
    def test_normative_entrypoints_override_generic_pause_gates(self) -> None:
        marker = "TASK_CONTINUITY_ENFORCEMENT_V3"
        paths = (
            ROOT / "AGENTS.md",
            ROOT / "AI-TO-CLI-PROTOCOL.md",
            ROOT / "docs" / "knowledge" / "agent-rules.md",
            ROOT / "CLAUDE.md",
            ROOT / "GEMINI.md",
            ROOT / ".github" / "copilot-instructions.md",
        )
        for path in paths:
            text = path.read_text(encoding="utf-8")
            self.assertIn(marker, text, str(path))
            self.assertIn("BLOCKED_EXTERNAL", text, str(path))

    def test_governance_executes_continuity_validator(self) -> None:
        governance = (ROOT / "scripts" / "repository-governance-validate.sh").read_text(encoding="utf-8")
        self.assertIn("python3 scripts/validate-task-continuity-enforcement.py", governance)


    def test_operations_worker_persists_nonterminal_continuation(self) -> None:
        worker = (ROOT / "scripts" / "agent-operations-worker.py").read_text(encoding="utf-8")
        self.assertIn("from agent_task_state import", worker)
        self.assertIn("persist_task_continuity", worker)
        self.assertIn("next_action=", worker)


    def test_pr_feedback_workflows_cancel_superseded_runs(self) -> None:
        workflows = (
            ROOT / ".github" / "workflows" / "mandatory-validation-gate.yml",
            ROOT / ".github" / "workflows" / "history-integrity.yml",
            ROOT / ".github" / "workflows" / "agents-hourly-deep-audit.yml",
        )
        for workflow in workflows:
            text = workflow.read_text(encoding="utf-8")
            self.assertIn("concurrency:", text, str(workflow))
            self.assertIn("cancel-in-progress: true", text, str(workflow))

    def test_production_e2e_gate_is_required_and_cannot_invoke_recovery_stages(self) -> None:
        probe = ROOT / "scripts" / "task_continuity_e2e.py"
        workflow = ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
        self.assertTrue(probe.is_file(), "production continuity E2E probe is required")
        self.assertTrue(workflow.is_file(), "production continuity E2E workflow is required")

        probe_text = probe.read_text(encoding="utf-8")
        self.assertIn("continuity_e2e_pass", probe_text)
        self.assertNotIn("task_continuation_watchdog", probe_text)
        self.assertNotIn("task_resume_dispatcher", probe_text)
        self.assertNotIn("run_once(", probe_text)

        workflow_text = workflow.read_text(encoding="utf-8")
        self.assertIn("scripts/task_continuity_e2e.py", workflow_text)
        self.assertIn("shopvivaliz-backend-browser", workflow_text)
        self.assertNotIn("shopvivaliz-a1-deploy", workflow_text)

        validator = (ROOT / "scripts" / "validate-task-continuity-enforcement.py").read_text(encoding="utf-8")
        self.assertIn("task_continuity_e2e.py", validator)
        self.assertIn("task-continuity-production-e2e.yml", validator)

        docs = (ROOT / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("DETACHED_TASK_RECOVERY_E2E_V7", docs)
        self.assertIn("continuity_e2e_pass", docs)

        audit = (ROOT / "AUDIT_POLICY.md").read_text(encoding="utf-8")
        self.assertIn("continuity_e2e_pass", audit)



if __name__ == "__main__":
    unittest.main()
