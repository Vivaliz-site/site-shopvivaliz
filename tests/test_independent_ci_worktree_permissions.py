"""Regression: root-owned, UMask=0077 checkout must be readable by UID 65534 in Docker.

This test deliberately never fetches GitHub, invokes Docker, starts a service,
loads credentials, or changes any production path.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path
import os
import stat
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ops/shopvivaliz-independent-ci.py"
SPEC = importlib.util.spec_from_file_location("shopvivaliz_independent_ci", SCRIPT)
assert SPEC and SPEC.loader
ci = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ci)


class IndependentCiCheckoutAccessTests(unittest.TestCase):
    def test_checkout_visible_to_non_root_only_inside_readonly_mount(self):
        with tempfile.TemporaryDirectory() as outer:
            locked_host_parent = Path(outer) / "private-workspace"
            locked_host_parent.mkdir(mode=0o700)
            checkout = locked_host_parent / "checkout"
            checkout.mkdir(mode=0o700)
            nested = checkout / "scripts"
            nested.mkdir(mode=0o700)
            hidden_git = checkout / ".git"
            hidden_git.mkdir(mode=0o700)
            plain = nested / "agent_task_state.py"
            plain.write_text("print('ok')\n")
            plain.chmod(0o600)
            executable = checkout / "check.sh"
            executable.write_text("#!/bin/sh\nexit 0\n")
            executable.chmod(0o700)
            tracked = hidden_git / "HEAD"
            tracked.write_text("ref: refs/heads/main\n")
            tracked.chmod(0o600)

            self.assertFalse(plain.stat().st_mode & stat.S_IROTH)
            self.assertFalse(checkout.stat().st_mode & stat.S_IXOTH)
            ci.prepare_public_checkout_for_isolated_tests(checkout)

            # The checkout is now readable *inside* the bind mount.
            for directory in (checkout, nested, hidden_git):
                self.assertTrue(directory.stat().st_mode & stat.S_IROTH)
                self.assertTrue(directory.stat().st_mode & stat.S_IXOTH)
                self.assertFalse(directory.stat().st_mode & stat.S_IWOTH)
            for file in (plain, tracked, executable):
                self.assertTrue(file.stat().st_mode & stat.S_IROTH)
                self.assertFalse(file.stat().st_mode & stat.S_IWOTH)
            self.assertTrue(executable.stat().st_mode & stat.S_IXOTH)
            self.assertFalse(plain.stat().st_mode & stat.S_IXOTH)
            # Host path stays private; no chmod on workspace or ancestors.
            self.assertEqual(stat.S_IMODE(locked_host_parent.stat().st_mode), 0o700)

    def test_symlink_target_outside_checkout_remains_private(self):
        with tempfile.TemporaryDirectory() as outer:
            parent = Path(outer)
            protected = parent / "private-token-like-file"
            protected.write_text("canary-no-token")
            protected.chmod(0o600)
            checkout = parent / "checkout"
            checkout.mkdir(mode=0o700)
            (checkout / "external-link").symlink_to(protected)
            (checkout / "external-directory").symlink_to(parent, target_is_directory=True)
            ci.prepare_public_checkout_for_isolated_tests(checkout)
            self.assertEqual(stat.S_IMODE(protected.stat().st_mode), 0o600)

    def test_refuse_symlink_as_checkout_root(self):
        with tempfile.TemporaryDirectory() as outer:
            base = Path(outer)
            real = base / "checkout"
            real.mkdir()
            link = base / "borrowed-checkout"
            link.symlink_to(real, target_is_directory=True)
            with self.assertRaisesRegex(RuntimeError, "checkout_root_not_a_real_directory"):
                ci.prepare_public_checkout_for_isolated_tests(link)

    def test_reject_special_files_without_chmod(self):
        if not hasattr(os, "mkfifo"):
            self.skipTest("POSIX FIFO required")
        with tempfile.TemporaryDirectory() as outer:
            checkout = Path(outer) / "checkout"
            checkout.mkdir(mode=0o700)
            fifo = checkout / "special"
            os.mkfifo(fifo, 0o600)
            with self.assertRaisesRegex(RuntimeError, "checkout_contains_special_file"):
                ci.prepare_public_checkout_for_isolated_tests(checkout)
            self.assertEqual(stat.S_IMODE(fifo.lstat().st_mode), 0o600)

    def test_docker_is_still_offline_readonly_and_unprivileged(self):
        source = SCRIPT.read_text()
        for invariant in (
            '"--network", "none"',
            '"--read-only"',
            '"--cap-drop", "ALL"',
            '"--security-opt", "no-new-privileges"',
            '"--user", "65534:65534"',
            '"--mount", "type=bind,source="',
            '",target=/work,readonly"',
            '"GITHUB_TOKEN="',
            '"GH_TOKEN="',
            'prepare_public_checkout_for_isolated_tests(checkout)',
        ):
            self.assertIn(invariant, source)
        self.assertNotIn("sudoers", source)
        self.assertNotIn("chmod -R 777", source)


if __name__ == "__main__":
    unittest.main()
