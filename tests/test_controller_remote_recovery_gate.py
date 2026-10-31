from __future__ import annotations

import importlib.util
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "scripts" / "controller-recovery-with-lease.py"
WORKFLOW = ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml"
SHA = "a" * 40


def load_helper():
    spec = importlib.util.spec_from_file_location("controller_recovery_lease_test", HELPER)
    if spec is None or spec.loader is None:
        raise RuntimeError("controller_recovery_helper_missing")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class FakeRuntime:
    class RuntimeLockConflict(RuntimeError):
        pass

    def __init__(self, *, busy=False, busy_attempts=0):
        self.busy = busy
        self.busy_attempts = busy_attempts
        self.attempts = 0
        self.live = False
        self.events = []

    def acquire_runtime_lock(self, kind, owner, ttl, actions):
        self.attempts += 1
        if self.busy or self.attempts <= self.busy_attempts:
            raise self.RuntimeLockConflict("runtime lock already held")
        assert kind == "maintenance"
        assert owner.startswith("github-actions-controller:")
        assert ttl >= 950
        assert actions == ["controller_promote"]
        self.live = True
        self.events.append("acquire")
        return {"lease_id": "lease-fixture", "fencing_token": 12}

    def assert_runtime_lock(self, lease_id, token, action):
        assert self.live and lease_id == "lease-fixture" and token == 12
        assert action == "controller_promote"
        self.events.append("assert")

    def release_runtime_lock(self, lease_id, token, reason):
        assert self.live and lease_id == "lease-fixture" and token == 12
        assert reason
        self.live = False
        self.events.append("release")


class ControllerRecoveryWorkflowTests(unittest.TestCase):
    def test_workflow_stages_complete_immutable_controller_payload(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        match = re.search(r"^            gemini_24x7_controller_install\)(.*?)^            chatgpt_continuity_repair\)", workflow, re.M | re.S)
        self.assertIsNotNone(match, "missing canonical backend recovery action")
        stage = match.group(1)
        self.assertIn("task_resume_worker.py", stage)
        self.assertIn("shopvivaliz-task-resume-worker.service", stage)
        self.assertIn("scripts/continuity/*.py", stage)
        self.assertIn("controller-recovery-with-lease.py", stage)
        self.assertNotIn("bash '$remote_dir/scripts/install-gemini-24x7-controller.sh'", stage)

    def test_workflow_stages_every_script_required_by_controller_installer(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        match = re.search(r"^            gemini_24x7_controller_install\)(.*?)^            chatgpt_continuity_repair\)", workflow, re.M | re.S)
        self.assertIsNotNone(match, "missing canonical backend recovery action")
        stage = match.group(1)
        required = re.search(r"for source in (.*?)\; do", installer, re.S)
        self.assertIsNotNone(required, "installer source list missing")
        for source in required.group(1).split():
            self.assertIn(source, stage, f"workflow does not stage installer dependency {source}")

    def test_rejects_invalid_commit_before_lock(self):
        helper = load_helper()
        runtime = FakeRuntime()
        with self.assertRaises(ValueError):
            helper.promote(Path("/tmp/no-release"), "main", runtime=runtime)
        self.assertEqual([], runtime.events)

    def test_exclusive_lease_held_during_install_and_released_on_success(self):
        helper = load_helper()
        runtime = FakeRuntime()
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            installer = root / "scripts" / "install-gemini-24x7-controller.sh"
            installer.parent.mkdir()
            installer.write_text("#!/bin/bash\n")
            def run(cmd, **kwargs):
                if cmd[0] == "git":
                    self.assertTrue(runtime.live)
                    self.assertEqual(
                        ["-c", f"safe.directory={helper.CONTROLLER_REPO}", "-C", str(helper.CONTROLLER_REPO)],
                        cmd[1:5],
                    )
                    return subprocess.CompletedProcess(cmd, 0, stdout=SHA + "\trefs/heads/main\n")
                if cmd[0] == "bash":
                    self.assertTrue(runtime.live)
                    runtime.events.append("install")
                    self.assertEqual(str(installer), cmd[1])
                    self.assertEqual(SHA, cmd[3])
                    return subprocess.CompletedProcess(cmd, 0)
                if cmd[0] == "systemctl":
                    self.assertTrue(runtime.live)
                    runtime.events.append("health")
                    return subprocess.CompletedProcess(cmd, 0)
                raise AssertionError(cmd)
            result = helper.promote(root, SHA, runtime=runtime, run=run)
        self.assertEqual(SHA, result["promoted_sha"])
        self.assertEqual(["acquire", "assert", "install", "health", "release"], runtime.events)
        self.assertFalse(runtime.live)

    def test_installer_failure_releases_lease(self):
        helper = load_helper()
        runtime = FakeRuntime()
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            installer = root / "scripts" / "install-gemini-24x7-controller.sh"
            installer.parent.mkdir()
            installer.write_text("#!/bin/bash\n")
            def run(cmd, **kwargs):
                if cmd[0] == "git":
                    return subprocess.CompletedProcess(cmd, 0, stdout=SHA + "\trefs/heads/main\n")
                raise subprocess.CalledProcessError(13, cmd)
            with self.assertRaises(subprocess.CalledProcessError):
                helper.promote(root, SHA, runtime=runtime, run=run)
        self.assertEqual(["acquire", "assert", "release"], runtime.events)
        self.assertFalse(runtime.live)

    def test_lock_contention_is_bounded_and_never_stolen(self):
        helper = load_helper()
        runtime = FakeRuntime(busy=True)
        clock = [0.0]
        waits = []
        def pause(seconds):
            waits.append(seconds)
            clock[0] += seconds
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            installer = root / "scripts" / "install-gemini-24x7-controller.sh"
            installer.parent.mkdir()
            installer.write_text("#!/bin/bash\n")
            with self.assertRaisesRegex(runtime.RuntimeLockConflict, "already held"):
                helper.promote(
                    root, SHA, runtime=runtime, lock_wait_seconds=12,
                    monotonic=lambda: clock[0], sleep=pause,
                    run=lambda *a, **k: self.fail("installer must not run while locked"),
                )
        self.assertEqual([5, 5, 2], waits)
        self.assertEqual(4, runtime.attempts)
        self.assertEqual([], runtime.events)
        self.assertFalse(runtime.live)

    def test_lock_acquisition_succeeds_after_legitimate_release(self):
        helper = load_helper()
        runtime = FakeRuntime(busy_attempts=2)
        clock = [0.0]
        waits = []
        def pause(seconds):
            waits.append(seconds)
            clock[0] += seconds
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            installer = root / "scripts" / "install-gemini-24x7-controller.sh"
            installer.parent.mkdir()
            installer.write_text("#!/bin/bash\n")
            def run(cmd, **kwargs):
                if cmd[0] == "git":
                    self.assertTrue(runtime.live)
                    return subprocess.CompletedProcess(cmd, 0, stdout=SHA + "\trefs/heads/main\n")
                if cmd[0] in ("bash", "systemctl"):
                    self.assertTrue(runtime.live)
                    return subprocess.CompletedProcess(cmd, 0)
                raise AssertionError(cmd)
            result = helper.promote(
                root, SHA, runtime=runtime, lock_wait_seconds=15,
                monotonic=lambda: clock[0], sleep=pause, run=run,
            )
        self.assertEqual(SHA, result["promoted_sha"])
        self.assertEqual([5, 5], waits)
        self.assertEqual(3, runtime.attempts)
        self.assertEqual(["acquire", "assert", "release"], runtime.events)
        self.assertFalse(runtime.live)

    def test_lock_wait_does_not_mask_other_errors_or_allow_unbounded_wait(self):
        helper = load_helper()
        runtime = FakeRuntime(busy=True)
        with self.assertRaisesRegex(ValueError, "lock_wait_seconds_out_of_range"):
            helper.promote(Path("/tmp/fake"), SHA, runtime=runtime, lock_wait_seconds=3600)
        self.assertEqual(0, runtime.attempts)
        self.assertIn("lock_wait_seconds=120", HELPER.read_text(encoding="utf-8"))

    def test_stale_main_ref_rejected_without_install(self):
        helper = load_helper()
        runtime = FakeRuntime()
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            installer = root / "scripts" / "install-gemini-24x7-controller.sh"
            installer.parent.mkdir()
            installer.write_text("#!/bin/bash\n")
            def run(cmd, **kwargs):
                self.assertEqual("git", cmd[0], "a stale ref may not install")
                return subprocess.CompletedProcess(cmd, 0, stdout="b" * 40 + "\trefs/heads/main\n")
            with self.assertRaisesRegex(ValueError, "expected_sha_not_remote_main"):
                helper.promote(root, SHA, runtime=runtime, run=run)
        self.assertEqual(["acquire", "assert", "release"], runtime.events)


if __name__ == "__main__":
    unittest.main()
