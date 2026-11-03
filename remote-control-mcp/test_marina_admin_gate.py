"""Gate tests for the Marina administrative command route.

These tests never touch the real Marina key, pinned hosts or attestation:
paths are redirected to a temporary directory and the route is exercised
only up to the SSH argv construction (no process is spawned).
"""

import json
import os
import stat
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest import mock

_STATE = tempfile.mkdtemp(prefix="marina-admin-gate-")
os.environ["SHOPVIVALIZ_REMOTE_MCP_STATE"] = _STATE

import server  # noqa: E402

SHA = "a" * 40


def _write_grant(path: Path, capabilities: list[str], merger: str = "shopvivaliz-human") -> None:
    grant = {
        "schema": "SHOPVIVALIZ_CENTRAL_MARINA_RUNTIME_GRANT_V1",
        "repository": "Vivaliz-site/site-shopvivaliz",
        "pr_number": 1,
        "resource": "mcp:central:marina",
        "principal": "shopvivaliz-central-mcp",
        "host": server.MARINA_HOST,
        "controller_host": server.CONTROLLER_BACKEND_HOST,
        "capabilities": capabilities,
        "head_sha": SHA,
        "merge_sha": SHA,
        "reviewer": "shopvivaliz-human",
        "merged_by": merger,
        "expires_at_utc": (datetime.now(timezone.utc) + timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    path.write_text(json.dumps(grant))
    path.chmod(0o600)


class MarinaAdminGateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="marina-admin-case-"))
        self.key = self.tmp / "key"
        self.pinned = self.tmp / "known_hosts"
        self.grant = self.tmp / "grant.json"
        self.admin_grant = self.tmp / "admin-grant.json"
        for p in (self.key, self.pinned):
            p.write_text("x")
            p.chmod(0o600)
        self.patches = [
            mock.patch.object(server, "MARINA_SSH_KEY", self.key),
            mock.patch.object(server, "MARINA_PINNED_HOSTS", self.pinned),
            mock.patch.object(server, "MARINA_CENTRAL_OWNER_ATTESTATION", self.grant),
            mock.patch.object(server, "MARINA_CENTRAL_ADMIN_OWNER_ATTESTATION", self.admin_grant),
        ]
        for patch in self.patches:
            patch.start()
        self.addCleanup(lambda: [p.stop() for p in self.patches])
        self.env = mock.patch.dict(os.environ, {
            "SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED": "approved-v1",
        })
        self.env.start()
        self.addCleanup(self.env.stop)

    def test_admin_denied_without_flag(self) -> None:
        with mock.patch.dict(os.environ, {}, clear=False):
            os.environ.pop("SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED", None)
            _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY])
            with self.assertRaises(PermissionError):
                server.marina_admin_ssh_invocation("echo hi")

    def test_admin_denied_without_attestation(self) -> None:
        with self.assertRaises(PermissionError):
            server.marina_admin_ssh_invocation("echo hi")

    def test_admin_grant_never_authorizes_readonly_route(self) -> None:
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY])
        self.assertFalse(server.marina_central_readonly_grant_active())
        self.assertTrue(server.marina_central_admin_grant_active())

    def test_enabling_admin_does_not_touch_readonly_attestation(self) -> None:
        _write_grant(self.grant, ["marina_readonly"])
        before = self.grant.read_text()
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY])
        self.assertEqual(self.grant.read_text(), before)
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED": "approved-v1"}):
            self.assertTrue(server.marina_central_readonly_grant_active())

    def test_readonly_grant_never_authorizes_admin(self) -> None:
        _write_grant(self.grant, ["marina_readonly"])
        with self.assertRaises(PermissionError):
            server.marina_admin_ssh_invocation("echo hi")

    def test_admin_grant_does_not_authorize_readonly_allowlist(self) -> None:
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY])
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED": "approved-v1"}):
            with self.assertRaises(PermissionError):
                server.marina_readonly_ssh_invocation(server.health_command("linux"))

    def test_admin_granted_builds_hardened_argv(self) -> None:
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY])
        argv = server.marina_admin_ssh_invocation("echo hi")
        self.assertEqual(argv[:4], ["/usr/sbin/runuser", "-u", "ubuntu", "--"])
        self.assertIn("StrictHostKeyChecking=yes", argv)
        self.assertIn("ForwardAgent=no", argv)
        self.assertNotIn("sudo", argv)
        self.assertEqual(argv[-1], "sh -c 'echo hi'")

    def test_empty_and_oversized_commands_rejected(self) -> None:
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY])
        for bad in ("", "   ", "x" * (server.MAX_MARINA_ADMIN_COMMAND_BYTES + 1)):
            with self.assertRaises(ValueError):
                server.marina_admin_ssh_invocation(bad)

    def test_key_with_group_or_world_access_rejected(self) -> None:
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY])
        self.key.chmod(0o640)
        with self.assertRaises(PermissionError):
            server.marina_admin_ssh_invocation("echo hi")

    def test_either_owner_account_may_merge_admin_grant(self) -> None:
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY], merger="fredmourao-ai")
        self.assertTrue(server.marina_central_admin_grant_active())

    def test_unknown_merger_cannot_authorize_admin_grant(self) -> None:
        _write_grant(self.admin_grant, [server.MARINA_ADMIN_CAPABILITY], merger="some-agent-account")
        self.assertFalse(server.marina_central_admin_grant_active())

    def test_marina_durable_jobs_stay_disabled(self) -> None:
        self.assertEqual(server.HOST_DURABLE_LIMITS[server.MARINA_HOST], 0)

    def test_admin_flag_rejected_for_other_hosts(self) -> None:
        with self.assertRaises(ValueError):
            server.run_host_command("Fred-Win", "echo hi", marina_admin=True)

    def test_retired_owner_grant_marker_removed(self) -> None:
        self.assertNotEqual(
            server.HOSTS[server.MARINA_HOST].get("capability"),
            "read_only_pending_owner_grant",
        )


if __name__ == "__main__":
    unittest.main()
