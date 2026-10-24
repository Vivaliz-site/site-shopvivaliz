"""Focused regression tests for the atomic Orquestrador checkpoint tool."""
from __future__ import annotations

import importlib.util
import json
import os
import stat
import tempfile
import unittest
from pathlib import Path
from unittest import mock

SOURCE = Path(__file__).resolve().parents[1] / "scripts" / "continuity" / "checkpoint_update_atomic.py"
spec = importlib.util.spec_from_file_location("checkpoint_update_atomic_tested", SOURCE)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class AtomicCheckpointTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name)
        (self.path / m.LOCK_NAME).write_bytes(b"")
        self.checkpoint = self.path / m.FILENAME
        self.original = {
            "task": m.EXPECTED_TASK,
            "schema_version": 1,
            "status": "RUNNING",
            "last_checked_at": "2026-10-11T09:14:45.140825+00:00",
            "other_work_preserved": {"lease_id": "existing-metadata-only"},
            "audit_ids": [],
        }
        self.checkpoint.write_text(json.dumps(self.original))
        os.chmod(self.checkpoint, 0o600)
        self.root_patch = mock.patch.object(m, "ROOT", self.path)
        self.root_patch.start()
        self.addCleanup(self.root_patch.stop)
        self.args = {
            "request_id": "gpt6-checkpoint-maintenance-20261011-v1",
            "expected_last_checked_at": self.original["last_checked_at"],
            "rules_sha_by_path": {k: "a" * 40 for k in m.REQUIRED_RULES},
            "audit_ids": ["11111111-2222-3333-4444-555555555555"],
            "next_action": "Wait for authorized original conversation evidence, then verify an exact route without binding guesses.",
        }
        self.live = {
            "controller": {
                "service_active": True, "active_sha": "b" * 40,
                "state": {"dispatcher": {"deferred_unbound": 9, "recovered": 0},
                          "route_recovery": {"bound": 0, "skipped_no_proof": 9}},
            },
            "continuity_ready": False,
            "chatgpt_monitor": {"fresh": True},
            "chatgpt_browser": {"authenticated": False, "session_state": "AUTH_FLOW"},
            "degraded_reasons": ["dispatcher_unbound_conversation"],
        }

    def test_atomic_update_preserves_metadata_and_other_fields(self):
        old_mode = stat.S_IMODE(self.checkpoint.stat().st_mode)
        result = m.update_checkpoint(self.args, self.live)
        saved = json.loads(self.checkpoint.read_text())
        self.assertTrue(result["saved"])
        self.assertTrue(result["verified"])
        self.assertFalse(result["deduplicated"])
        self.assertEqual(saved["status"], "RUNNING")
        self.assertEqual(saved["other_work_preserved"], self.original["other_work_preserved"])
        self.assertNotEqual(saved["last_checked_at"], self.original["last_checked_at"])
        self.assertEqual(saved["observed_state"]["dispatcher_deferred_unbound"], 9)
        self.assertEqual(saved["observed_state"]["dispatcher_recovered"], 0)
        self.assertEqual(saved["rules_loaded"]["ref"], "main")
        self.assertEqual(saved["audit_ids"], self.args["audit_ids"])
        self.assertEqual(stat.S_IMODE(self.checkpoint.stat().st_mode), old_mode)

    def test_duplicate_is_idempotent_and_stale_request_is_rejected(self):
        first = m.update_checkpoint(self.args, self.live)
        stat_before = self.checkpoint.stat()
        second = m.update_checkpoint(self.args, self.live)
        self.assertTrue(second["deduplicated"])
        self.assertEqual(second["last_checked_at"], first["last_checked_at"])
        self.assertEqual(stat_before.st_mtime_ns, self.checkpoint.stat().st_mtime_ns)
        changed = {**self.args, "request_id": "gpt6-checkpoint-maintenance-20261011-v2"}
        with self.assertRaisesRegex(ValueError, "optimistic_lock_conflict"):
            m.update_checkpoint(changed, self.live)

    def test_duplicate_id_with_modified_payload_is_not_accepted(self):
        m.update_checkpoint(self.args, self.live)
        changed = {**self.args, "next_action": "This is a different longer next action for the same request id."}
        with self.assertRaisesRegex(ValueError, "request_id_conflict"):
            m.update_checkpoint(changed, self.live)

    def test_terminal_checkpoint_is_not_modified(self):
        original = {**self.original, "status": "CONCLUIDO"}
        self.checkpoint.write_text(json.dumps(original))
        before = self.checkpoint.read_bytes()
        with self.assertRaisesRegex(ValueError, "status_not_running"):
            m.update_checkpoint(self.args, self.live)
        self.assertEqual(before, self.checkpoint.read_bytes())

    def test_symlink_checkpoint_denied(self):
        actual = self.path / "other"
        actual.write_text(json.dumps(self.original))
        self.checkpoint.unlink()
        self.checkpoint.symlink_to(actual)
        with self.assertRaises(OSError):
            m.update_checkpoint(self.args, self.live)

    def test_graphical_recovery_runbook_sha_is_allowed_without_relaxing_validation(self):
        runbook = "docs/knowledge/shopvivaliz-graphical-session-recovery.md"
        self.assertIn(runbook, m.ALLOWED_RULES)
        updated = {
            **self.args,
            "rules_sha_by_path": {
                **self.args["rules_sha_by_path"],
                runbook: "c" * 40,
            },
        }
        result = m.update_checkpoint(updated, self.live)
        self.assertTrue(result["verified"])
        saved = json.loads(self.checkpoint.read_text())
        self.assertEqual(saved["rules_loaded"]["sha_by_path"][runbook], "c" * 40)
        invalid = {
            **updated,
            "request_id": "invalid-extra-runbook-rule-test",
            "rules_sha_by_path": {**updated["rules_sha_by_path"], "secrets/password.txt": "f" * 40},
        }
        with self.assertRaisesRegex(ValueError, "checkpoint_update_invalid_rules"):
            m._validate(invalid)

    def test_chatgpt_vm_auth_runbook_sha_is_allowed_with_closed_validation(self):
        runbook = "docs/knowledge/chatgpt-vm-auth.md"
        self.assertIn(runbook, m.ALLOWED_RULES)
        updated = {
            **self.args,
            "rules_sha_by_path": {
                **self.args["rules_sha_by_path"],
                runbook: "d" * 40,
            },
        }
        result = m.update_checkpoint(updated, self.live)
        self.assertTrue(result["verified"])
        saved = json.loads(self.checkpoint.read_text())
        self.assertEqual(saved["status"], "RUNNING")
        self.assertEqual(saved["rules_loaded"]["sha_by_path"][runbook], "d" * 40)
        malformed = {
            **updated,
            "rules_sha_by_path": {**updated["rules_sha_by_path"], runbook: "not-a-sha"},
        }
        with self.assertRaisesRegex(ValueError, "checkpoint_update_invalid_rules_sha"):
            m._validate(malformed)

    def test_rejects_unverified_or_secret_note(self):
        cases = [
            {**self.args, "next_action": "Bearer abcdefghijklmnopqrst"},
            {**self.args, "rules_sha_by_path": {"AGENTS.md": "a" * 40}},
            {**self.args, "path": "/etc/passwd"},
        ]
        for case in cases:
            with self.subTest(case=sorted(case)):
                with self.assertRaises(ValueError):
                    m.update_checkpoint(case, self.live)

    def test_failure_before_replace_preserves_original_and_cleans_tmp(self):
        raw = self.checkpoint.read_bytes()
        with mock.patch.object(m.os, "replace", side_effect=OSError("simulated replacement failure")):
            with self.assertRaises(OSError):
                m.update_checkpoint(self.args, self.live)
        self.assertEqual(self.checkpoint.read_bytes(), raw)
        self.assertFalse(list(self.path.glob("._orquestrador-checkpoint-*.tmp")))

    def test_audit_metadata_never_contains_note_body(self):
        server_path = Path(__file__).resolve().parents[1] / "remote-control-mcp" / "server.py"
        source = server_path.read_text()
        self.assertIn('if tool == "checkpoint_update_atomic":', source)
        self.assertIn('"next_action_length":', source)
        self.assertIn('("checkpoint_update_atomic",', source)


if __name__ == "__main__":
    unittest.main()
