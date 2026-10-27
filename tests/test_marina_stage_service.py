#!/usr/bin/env python3
"""Offline checks for the operator-only Marina MCP service stager."""
from __future__ import annotations

import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "operator_stage_service.sh"


class StagingTests(unittest.TestCase):
    def test_bash_parses(self):
        self.assertEqual(subprocess.run(["bash", "-n", str(SCRIPT)], check=False).returncode, 0)

    def test_unknown_host_rejected_without_mutation(self):
        """A hostile PATH must not spoof Marina's identity or execute as root."""
        import socket
        if socket.gethostname().split('.')[0] == 'marina-a1-4c24g':
            self.skipTest('requires a non-Marina CI runner')
        with tempfile.TemporaryDirectory() as tmp:
            marker = Path(tmp) / 'host-probe-executed'
            fake_hostname = Path(tmp) / 'hostname'
            fake_hostname.write_text(
                '#!/bin/sh\n: > "' + str(marker) +
                '"\nprintf "marina-a1-4c24g\n"\n'
            )
            fake_hostname.chmod(0o755)
            env = dict(os.environ, PATH=tmp + os.pathsep + os.environ['PATH'])
            result = subprocess.run(['/bin/bash', str(SCRIPT), '--stage'], env=env,
                                    text=True, capture_output=True, timeout=8, check=False)
            self.assertEqual(result.returncode, 3, result.stderr)
            self.assertIn('host_identity_mismatch', result.stderr)
            self.assertFalse(marker.exists(), 'untrusted hostname executed')

    def test_inspect_does_not_provision_or_claim_ready(self):
        """Inspect result is only a negative preflight, never an installation."""
        function = self._bash_function('inspect_only')
        result = self._run_guard(function, dict(os.environ), 'inspect_only')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('MARINA_MCP_STAGE=INSPECT_ONLY', result.stdout)
        self.assertIn('MIGRATION_STATUS=NO_CUT_NAO_APTO', result.stdout)

    def test_required_operator_gates_and_no_autostart(self):
        source = SCRIPT.read_text()
        for required in (
            '"$EUID" -eq 0', 'NoNewPrivs:', 'SHOPVIVALIZ_MARINA_APPROVED_SHA',
            'source_not_root_owned', 'unapproved_checkout', 'unclean_checkout',
            'unit_identity_invalid', 'unit_privilege_invalid',
            'active_unit_mutation_denied', 'concurrent_operator_stage',
            'different_release_active', 'NoNewPrivileges=yes',
            'source_systemd_dir_not_root_owned', 'unsafe_lock_symlink',
            'existing_target_wrong_mode', 'service_group_missing',
            'checkout_status_unavailable', 'service_state_unavailable', 'unsafe_lock_file',
            'MIGRATION_STATUS=NO_CUT_NAO_APTO',
        ):
            self.assertIn(required, source)
        for forbidden in ('systemctl enable', 'systemctl start', 'systemctl restart',
                          'systemctl reboot', 'cloudflared tunnel', 'curl -H'):
            self.assertNotIn(forbidden, source)


    @staticmethod
    def _bash_function(name):
        source = SCRIPT.read_text()
        match = re.search(r"(?ms)^" + re.escape(name) + r"\(\) \{\n.*?^\}", source)
        if match is None:
            raise AssertionError(f"Missing guard function: {name}")
        return match.group(0)

    @staticmethod
    def _run_guard(function, env, argument):
        snippet = (
            'refuse() { echo "MARINA_MCP_STAGE_REFUSED=$1" >&2; exit 3; }\n'
            + function + '\n' + argument + '\n'
        )
        return subprocess.run(["bash", "-c", snippet], env=env,
                              text=True, capture_output=True, timeout=8, check=False)

    def test_privileged_git_status_cannot_execute_fsmonitor_hook(self):
        """RED: Git status runs a configured fsmonitor. GREEN: safe_git suppresses it."""
        function = self._bash_function("safe_git")
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "repo"
            root.mkdir()
            subprocess.run(["/usr/bin/git", "init", "-q", str(root)], check=True)
            source = root / "marina-direct-mcp"
            source.mkdir()
            (source / "server.py").write_text("marker = 1\n")
            subprocess.run(["/usr/bin/git", "-C", str(root), "add", "."], check=True)
            subprocess.run(["/usr/bin/git", "-C", str(root),
                            "-c", "user.name=Fixture", "-c", "user.email=test@example.com",
                            "commit", "-qm", "fixture"], check=True)
            marker = Path(tmp) / "fsmonitor-executed"
            hook = Path(tmp) / "fsmonitor.sh"
            hook.write_text('#!/bin/sh\n: > "' + str(marker) + '"\nexit 0\n')
            hook.chmod(0o755)
            subprocess.run(["/usr/bin/git", "-C", str(root), "config",
                            "core.fsmonitor", str(hook)], check=True)
            env = dict(os.environ, ROOT=str(root),
                       GIT_CONFIG_COUNT="1", GIT_CONFIG_KEY_0="core.fsmonitor",
                       GIT_CONFIG_VALUE_0=str(hook))
            snippet = function + '\nsource_root="$ROOT"\nsafe_git status --porcelain -- marina-direct-mcp\n'
            result = subprocess.run(["/bin/bash", "-c", snippet], env=env,
                                    text=True, capture_output=True, timeout=10)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertFalse(marker.exists(), "untrusted Git fsmonitor hook executed as stager")

    def test_git_source_metadata_must_be_operator_owned(self):
        """The approved checkout may not delegate its .git/config to another user."""
        source = SCRIPT.read_text()
        for required in ("git_metadata_unsafe", "git_metadata_not_root_owned",
                         "git_config_unsafe", "git_config_not_root_owned",
                         "core.fsmonitor=false", "core.hooksPath=/dev/null",
                         "/usr/bin/env -i", "safe_git rev-parse HEAD",
                         "safe_git status --porcelain"):
            self.assertIn(required, source)

    def test_unit_boot_autostart_fail_closed(self):
        """Exercise the real shell guard with systemctl states, not text matching."""
        function = self._bash_function("assert_unit_not_autostart")
        with tempfile.TemporaryDirectory() as tmp:
            fake = Path(tmp) / "systemctl"
            fake.write_text("""#!/bin/sh
case "$1" in
  show) printf '%s\\n' "$UNIT_ACTIVE"; exit 0 ;;
  is-enabled) printf '%s\\n' "$UNIT_ENABLED"
    case "$UNIT_ENABLED" in enabled|static|linked) exit 0 ;; *) exit 1 ;; esac ;;
esac
exit 4
""")
            fake.chmod(0o755)
            for active, enabled, allowed in (
                ("inactive", "enabled", False),
                ("inactive", "enabled-runtime", False),
                ("inactive", "static", False),
                ("inactive", "linked", False),
                ("inactive", "disabled", True),
                ("inactive", "not-found", True),
                ("active", "disabled", False),
            ):
                with self.subTest(active=active, enabled=enabled):
                    env = dict(os.environ, PATH=tmp + os.pathsep + os.environ["PATH"],
                               UNIT_ACTIVE=active, UNIT_ENABLED=enabled)
                    result = self._run_guard(function, env,
                                             "assert_unit_not_autostart test.service")
                    self.assertEqual(result.returncode, 0 if allowed else 3, result.stderr)
                    if active == "inactive" and not allowed:
                        self.assertIn("unit_may_start_on_boot", result.stderr)

    def test_existing_home_guard_prevents_implicit_chown(self):
        """A foreign/preexisting service home must fail before install -d can chown."""
        function = self._bash_function("assert_existing_service_home")
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            home = base / "existing-home"
            home.mkdir()
            home.chmod(0o755)
            (base / "getent").write_text("""#!/bin/sh
[ "$1" = passwd ] && [ "$ACCOUNT_PRESENT" = 1 ] && exit 0
[ "$1" = group ] && [ "$GROUP_PRESENT" = 1 ] && exit 0
exit 2
""")
            (base / "id").write_text("""#!/bin/sh
case "$1" in
  -u) echo "$EXPECTED_UID" ;;
  -g) echo "$EXPECTED_GID" ;;
  -gn) echo shopvivaliz-marina-mcp ;;
  *) exit 2 ;;
esac
""")
            (base / "getent").chmod(0o755)
            (base / "id").chmod(0o755)
            env = dict(os.environ, PATH=tmp + os.pathsep + os.environ["PATH"],
                       ACCOUNT_PRESENT="1", GROUP_PRESENT="1",
                       EXPECTED_UID=str(os.getuid()), EXPECTED_GID=str(os.getgid()))
            call = "assert_existing_service_home " + str(home)
            before = home.stat()
            result = self._run_guard(function, env, call)
            self.assertEqual(result.returncode, 3, result.stderr)
            self.assertIn("existing_home_owner_mode_mismatch", result.stderr)
            after = home.stat()
            self.assertEqual((before.st_uid, before.st_gid, before.st_mode),
                             (after.st_uid, after.st_gid, after.st_mode))
            home.chmod(0o700)
            result = self._run_guard(function, env, call)
            self.assertEqual(result.returncode, 0, result.stderr)
            env["ACCOUNT_PRESENT"] = "0"
            result = self._run_guard(function, env, call)
            self.assertEqual(result.returncode, 3, result.stderr)
            self.assertIn("orphaned_service_home", result.stderr)


    def test_untrusted_source_parent_rejected(self):
        """No root stage from a checkout under a renameable ancestor."""
        source = SCRIPT.read_text()
        self.assertIn('trusted_source_ancestors "$source_root"', source)
        guard = self._bash_function("trusted_source_ancestors")
        stubs = (
            "refuse_writable() { :; }\n"
            'stat() { if [[ "$3" == "$BLOCKED_PARENT" ]]; then echo 1001; else echo 0; fi; }\n'
        )
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "trusted"
            root.mkdir()
            checkout = root / "checkout"
            checkout.mkdir()
            env = dict(os.environ, BLOCKED_PARENT="/does-not-exist")
            result = self._run_guard(stubs + guard, env,
                                     "trusted_source_ancestors " + str(checkout))
            self.assertEqual(result.returncode, 0, result.stderr)
            env["BLOCKED_PARENT"] = str(root)
            result = self._run_guard(stubs + guard, env,
                                     "trusted_source_ancestors " + str(checkout))
            self.assertEqual(result.returncode, 3, result.stderr)
            self.assertIn("source_parent_not_root_owned", result.stderr)
            linked = Path(tmp) / "linked"
            linked.symlink_to(root, target_is_directory=True)
            env["BLOCKED_PARENT"] = "/does-not-exist"
            result = self._run_guard(stubs + guard, env,
                                     "trusted_source_ancestors " + str(linked / "checkout"))
            self.assertEqual(result.returncode, 3, result.stderr)
            self.assertIn("source_parent_unsafe", result.stderr)

if __name__ == '__main__':
    unittest.main()
