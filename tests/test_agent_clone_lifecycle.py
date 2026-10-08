#!/usr/bin/env python3
"""Safe agent-owned clone lifecycle. All Git repos are synthetic test fixtures."""
import json
from pathlib import Path
import os
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import agent_clone_lifecycle as lifecycle


class CloneLifecycleTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="agent-clone-test-")
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.state = self.base / "state"
        self.state.mkdir()
        self.allowed = self.base / "clones"
        self.allowed.mkdir()
        self.env = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
        self.env["GIT_CONFIG_GLOBAL"] = os.devnull
        self.env["GIT_CONFIG_SYSTEM"] = os.devnull
        self.env["HOME"] = str(self.base)
        self.origin = self.base / "origin.git"
        self.work = self.base / "seed"
        self.git("init", "--bare", "--initial-branch=main", str(self.origin), cwd=self.base)
        self.git("clone", str(self.origin), str(self.work), cwd=self.base)
        (self.work / "tracked.txt").write_text("stable\n")
        self.git("add", ".", cwd=self.work)
        self.git("-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-m", "base", cwd=self.work)
        self.git("push", "origin", "HEAD:main", cwd=self.work)
        self.clone = self.allowed / "task-one"
        self.git("clone", str(self.origin), str(self.clone), cwd=self.base)
        self.task_id = "task-fixture"
        self.write_task("RUNNING")

    def git(self, *args, cwd):
        return subprocess.run(["git", *args], cwd=cwd, env=self.env, check=True, capture_output=True, text=True, timeout=15)

    def write_task(self, status):
        payload = {"task_id": self.task_id, "status": status}
        if status == "CONCLUIDO":
            payload.update({"completed_at": "2026-10-08T11:00:00Z",
                            "evidence": ["synthetic verified test"], "verification": "all checks passed"})
        (self.state / (self.task_id + ".json")).write_text(json.dumps(payload))

    def register(self, path=None):
        return lifecycle.register_clone(self.task_id, path or self.clone, state_dir=self.state, allowed_roots=(self.allowed,))

    def cleanup(self):
        return lifecycle.cleanup_task(self.task_id, state_dir=self.state, allowed_roots=(self.allowed,))

    def test_terminal_task_deletes_clean_pushed_clone(self):
        self.register()
        self.assertEqual(self.cleanup()["removed"], 0)
        self.assertTrue(self.clone.exists(), "running work must survive")
        self.write_task("CONCLUIDO")
        self.assertEqual(self.cleanup()["removed"], 1)
        self.assertFalse(self.clone.exists())
        self.assertEqual(self.cleanup()["removed"], 0, "idempotent cleanup")

    def test_forged_terminal_without_evidence_preserves_clone(self):
        self.register()
        state_path = self.state / (self.task_id + ".json")
        state_path.write_text(json.dumps({"task_id": self.task_id, "status": "CONCLUIDO"}))
        self.assertEqual(self.cleanup()["removed"], 0)
        self.assertTrue(self.clone.exists())

    def test_dirty_clone_is_preserved_and_retryable(self):
        self.register()
        (self.clone / "pending.patch").write_text("must preserve")
        self.write_task("CONCLUIDO")
        result = self.cleanup()
        self.assertEqual(result["removed"], 0)
        self.assertTrue(self.clone.exists())
        (self.clone / "pending.patch").unlink()
        self.assertEqual(self.cleanup()["removed"], 1)

    def test_unpushed_commit_preserved(self):
        self.register()
        (self.clone / "tracked.txt").write_text("local change\n")
        self.git("add", ".", cwd=self.clone)
        self.git("-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-m", "local only", cwd=self.clone)
        self.write_task("CONCLUIDO")
        result = self.cleanup()
        self.assertEqual(result["removed"], 0)
        self.assertTrue(self.clone.exists())

    def test_untracked_and_symlinks_are_not_deleted(self):
        other = self.base / "valuable"
        other.mkdir()
        (other / "important.txt").write_text("preserve")
        with self.assertRaises(ValueError):
            self.register(other)
        link = self.allowed / "link"
        link.symlink_to(other, target_is_directory=True)
        with self.assertRaises(ValueError):
            self.register(link)
        self.assertTrue((other / "important.txt").exists())

    def test_reject_terminal_registration(self):
        self.write_task("CONCLUIDO")
        with self.assertRaises(ValueError):
            self.register()

    def test_background_sweep_cleans_only_completed_owned_clones(self):
        self.register()
        self.assertEqual(lifecycle.sweep_completed(state_dir=self.state, allowed_roots=(self.allowed,))["removed"], 0)
        self.write_task("CONCLUIDO")
        self.assertEqual(lifecycle.sweep_completed(state_dir=self.state, allowed_roots=(self.allowed,))["removed"], 1)

    def test_active_process_cwd_preserved(self):
        self.register()
        self.write_task("CONCLUIDO")
        child = subprocess.Popen(["sleep", "20"], cwd=self.clone, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            self.assertEqual(self.cleanup()["removed"], 0)
            self.assertTrue(self.clone.exists())
        finally:
            child.terminate()
            child.wait(timeout=5)
        self.assertEqual(self.cleanup()["removed"], 1)

    def test_locked_worktree_preserved(self):
        wt = self.allowed / "worktree"
        self.git("worktree", "add", "-b", "fixture-locked", str(wt), "HEAD", cwd=self.work)
        self.git("worktree", "lock", "--reason", "queued task", str(wt), cwd=self.work)
        self.register(wt)
        self.write_task("CONCLUIDO")
        self.assertEqual(self.cleanup()["removed"], 0)
        self.assertTrue(wt.exists())
        self.git("worktree", "unlock", str(wt), cwd=self.work)
        self.assertEqual(self.cleanup()["removed"], 1)
        self.assertFalse(wt.exists())

    def test_task_state_complete_invokes_cleanup_after_transition(self):
        from unittest.mock import patch
        import agent_task_state
        terminal = {"task_id": self.task_id, "status": "CONCLUIDO"}
        with patch.object(agent_task_state, "_complete_task_transition", return_value=terminal) as transition:
            with patch.object(lifecycle, "cleanup_task", return_value={"ok": True, "removed": 1}) as cleanup:
                result = agent_task_state.complete_task(self.task_id)
        transition.assert_called_once_with(self.task_id)
        cleanup.assert_called_once_with(self.task_id, state_dir=agent_task_state.RUNTIME_DIR)
        self.assertEqual(result["clone_cleanup"]["removed"], 1)

    def test_reject_noncompleted_blocked_task_cleanup(self):
        self.register()
        self.write_task("BLOCKED_EXTERNAL")
        self.assertEqual(self.cleanup()["removed"], 0)
        self.assertTrue(self.clone.exists())

    def test_missing_task_preserves_registered_work(self):
        self.register()
        (self.state / (self.task_id + ".json")).unlink()
        self.assertEqual(self.cleanup()["removed"], 0)
        self.assertTrue(self.clone.exists())


if __name__ == "__main__":
    unittest.main()
