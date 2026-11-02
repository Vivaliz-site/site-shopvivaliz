"""Negative authorization, stale SHA, exact grant and fencing regressions."""
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import patch, Mock

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/continuity/owner_authorized_rollout.py"
spec = importlib.util.spec_from_file_location("owner_rollout", SCRIPT)
roll = importlib.util.module_from_spec(spec)
spec.loader.exec_module(roll)
SHA = "a" * 40
PR_HEAD = "b" * 40
PR_MERGE = "c" * 40

class OwnerRolloutSafetyTests(unittest.TestCase):
    def prepare(self, root):
        release = root / "worktree"
        for item in ["scripts/controller-recovery-with-lease.py",
                     "scripts/install-chatgpt-continuity-backend-bridge.sh",
                     "scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs"]:
            path = release / item
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("safe worker source")
        return release

    def base_patches(self):
        return [patch.object(roll.os, "geteuid", return_value=1000),
                patch.object(roll.socket, "gethostname", return_value=roll.HOST),
                patch.object(roll.pwd, "getpwuid", return_value=types.SimpleNamespace(pw_name="ubuntu"))]

    def test_bot_merge_or_missing_review_aborts_before_any_mutation(self):
        with tempfile.TemporaryDirectory() as d:
            release = self.prepare(Path(d))
            with self.base_patches()[0], self.base_patches()[1], self.base_patches()[2], \
                 patch.object(roll, "assert_exact_main"), \
                 patch.object(roll, "assert_owner_grant", side_effect=roll.RolloutDenied("bot_merge")), \
                 patch.object(roll, "call") as called:
                with self.assertRaises(roll.RolloutDenied):
                    roll.execute(release, SHA, 3502, PR_HEAD, PR_MERGE)
                called.assert_not_called()

    def test_stale_main_denied_before_owner_or_installer(self):
        with tempfile.TemporaryDirectory() as d:
            release = self.prepare(Path(d))
            with self.base_patches()[0], self.base_patches()[1], self.base_patches()[2], \
                 patch.object(roll, "call", return_value="wrong-sha") as called, \
                 patch.object(roll, "assert_owner_grant") as owner:
                with self.assertRaisesRegex(roll.RolloutDenied, "source_release_sha_mismatch"):
                    roll.execute(release, SHA, 3502, PR_HEAD, PR_MERGE)
                owner.assert_not_called()
                self.assertEqual(called.call_count, 1)

    def test_foreign_runtime_lease_cannot_be_stolen_for_bridge(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            release = self.prepare(root)
            module = types.SimpleNamespace(acquire_runtime_lock=Mock(side_effect=RuntimeError("held")))
            with self.base_patches()[0], self.base_patches()[1], self.base_patches()[2], \
                 patch.object(roll, "assert_exact_main"), patch.object(roll, "assert_owner_grant"), \
                 patch.object(roll, "INSTALLED_LOCK", root), patch.dict(sys.modules, {"runtime_lock": module}), \
                 patch.object(roll, "call") as called:
                with self.assertRaisesRegex(RuntimeError, "held"):
                    roll.execute(release, SHA, 3502, PR_HEAD, PR_MERGE)
                module.acquire_runtime_lock.assert_called_once()
                self.assertEqual(called.call_count, 1)
                self.assertIn("controller-recovery-with-lease.py", str(called.call_args.args[0]))

    def test_success_has_separate_fenced_bridge_lease_and_checks_worker(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            release = self.prepare(root)
            installed = root / "installed.mjs"
            installed.write_text("safe worker source")
            lease = {"lease_id": "unique-lease", "fencing_token": 44}
            module = types.SimpleNamespace(acquire_runtime_lock=Mock(return_value=lease),
                     assert_runtime_lock=Mock(return_value={"owner_kind": "maintenance"}),
                     release_runtime_lock=Mock(return_value={}))
            with self.base_patches()[0], self.base_patches()[1], self.base_patches()[2], \
                 patch.object(roll, "assert_exact_main"), patch.object(roll, "assert_owner_grant"), \
                 patch.object(roll, "INSTALLED_LOCK", root), patch.object(roll, "INSTALLED_WORKER", installed), \
                 patch.dict(sys.modules, {"runtime_lock": module}), patch.object(roll, "call") as called:
                roll.execute(release, SHA, 3502, PR_HEAD, PR_MERGE)
                self.assertEqual(called.call_count, 3)
                self.assertEqual(module.assert_runtime_lock.call_args.args[2], "continuity_bridge_install")
                self.assertEqual(module.release_runtime_lock.call_args.args[:2], ("unique-lease", 44))

    def test_exact_scoped_grant_denies_extra_capabilities_and_false_fencing(self):
        with tempfile.TemporaryDirectory() as d:
            release = self.prepare(Path(d))
            path = release / "security/owner-approvals/3502.json"
            path.parent.mkdir(parents=True)
            required = {"schema":"SHOPVIVALIZ_OWNER_PR_GRANT_V1",
                        "repository":roll.REPO, "pr_number":3502,
                        "resource":roll.RESOURCE,"principal":roll.PRINCIPAL,
                        "host":roll.HOST,"capabilities":["controller_promote","continuity_bridge_install"],
                        "operation":roll.OPERATION,"target_repository":roll.REPO,
                        "target_merge_sha":roll.TECHNICAL_MERGE,
                        "requires_remote_main_sha_match":True,
                        "requires_free_runtime_lock":True,
                        "requires_exclusive_maintenance_lease":True,
                        "requires_fencing":True,
                        "forbid_foreign_lease_release":True,
                        "expires_at_utc":"2026-11-09T00:00:00Z"}
            path.write_text(json.dumps(required))
            roll.assert_scoped_grant(release,3502)
            for key, replacement in [
                ("requires_fencing",False),
                ("capabilities",["controller_promote","shell"]),
                ("host","shopvivaliz-free-a1"),
                ("operation","arbitrary_admin")]:
                bad=dict(required,**{key:replacement})
                path.write_text(json.dumps(bad))
                with self.subTest(key=key), self.assertRaises(roll.RolloutDenied):
                    roll.assert_scoped_grant(release,3502)
            path.write_text(json.dumps(dict(required, unexpected="admin")))
            with self.assertRaises(roll.RolloutDenied):
                roll.assert_scoped_grant(release,3502)

if __name__ == "__main__":
    unittest.main(verbosity=2)
