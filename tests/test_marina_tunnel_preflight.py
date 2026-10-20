#!/usr/bin/env python3
"""Regression tests for the Marina dedicated-tunnel preflight only."""
from __future__ import annotations

import importlib.util
import os
import stat
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

MODPATH = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "operator_preflight.py"
spec = importlib.util.spec_from_file_location("marina_tunnel_preflight", MODPATH)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = m
spec.loader.exec_module(m)


class PreflightSafetyTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)

    def private_file(self, name="auth-token", content=b"x" * 64):
        f = self.root / name
        f.write_bytes(content)
        f.chmod(0o600)
        return f

    def test_secret_0600_metadata_only(self):
        path = self.private_file()
        with patch.object(Path, "read_bytes", side_effect=AssertionError("no secret reads")), \
             patch.object(Path, "open", side_effect=AssertionError("no secret opens")):
            self.assertEqual(m.protected_file_status(path, os.geteuid(), 32, 256),
                             "metadata_ok_not_auth_tested")

    def test_secret_missing_symlink_and_broad_permissions_fail(self):
        self.assertEqual(m.protected_file_status(self.root / "absent", os.geteuid(), 32, 256),
                         "not_provisioned")
        path = self.private_file()
        link = self.root / "link"
        link.symlink_to(path)
        self.assertEqual(m.protected_file_status(link, os.geteuid(), 32, 256),
                         "unsafe_file_type")
        path.chmod(0o644)
        self.assertEqual(m.protected_file_status(path, os.geteuid(), 32, 256),
                         "ownership_or_mode_unsafe")
        path.chmod(0o600)
        self.assertEqual(m.protected_file_status(path, os.geteuid() + 100, 32, 256),
                         "ownership_or_mode_unsafe")
        path.write_bytes(b"short")
        self.assertEqual(m.protected_file_status(path, os.geteuid(), 32, 256),
                         "unexpected_size")

    def test_http_listener_loopback_only(self):
        self.assertEqual(m.parse_listener_status(
            'LISTEN 0 128 127.0.0.1:5580 0.0.0.0:*'), "loopback_only")
        self.assertEqual(m.parse_listener_status(
            'LISTEN 0 128 0.0.0.0:5580 0.0.0.0:*'), "unsafe_non_loopback_or_ipv6")
        self.assertEqual(m.parse_listener_status(
            'LISTEN 0 128 [::]:5580 [::]:*'), "unsafe_non_loopback_or_ipv6")
        self.assertEqual(m.parse_listener_status(
            'LISTEN 0 128 127.0.0.1:5580 0.0.0.0:*\n'
            'LISTEN 0 128 0.0.0.0:5580 0.0.0.0:*'), "unsafe_non_loopback_or_ipv6")
        self.assertEqual(m.parse_listener_status(
            'LISTEN 0 128 127.0.0.1:13312 0.0.0.0:*'), "not_listening")

    def test_service_allowlist_cannot_be_injected(self):
        with self.assertRaisesRegex(ValueError, "unit_not_allowlisted"):
            m.unit_snapshot("ssh.service; sudo id")

    def test_binary_metadata_requires_safe_owner_and_modes(self):
        f = self.private_file(name="tunnel-client")
        f.chmod(0o755)
        self.assertEqual(m.binary_status(f, os.geteuid()),
                         "metadata_ok_version_unverified")
        f.chmod(0o777)
        self.assertEqual(m.binary_status(f, os.geteuid()),
                         "unsafe_binary_metadata")
        f.chmod(0o755)
        symlink = self.root / "client-link"
        symlink.symlink_to(f)
        self.assertEqual(m.binary_status(symlink, os.geteuid()),
                         "unsafe_binary_metadata")

    def test_wrong_identity_and_missing_directory_block_candidate(self):
        with patch.object(m.socket, "gethostname", return_value="source-vm"), \
             patch.object(m, "unit_snapshot", return_value={"LoadState": "loaded"}), \
             patch.object(m.subprocess, "run",
                          return_value=types.SimpleNamespace(returncode=0, stdout="")):
            report = m.collect(home=self.root)
        self.assertEqual(report["host_identity"], "host_mismatch")
        self.assertFalse(report["local_preflight_candidate_only"])
        self.assertEqual(report["migration_status"], "NO_CUT_NAO_APTO")
        self.assertEqual(report["chatgpt_mcp_connected"], "not_tested")

    def test_local_ready_does_not_claim_tunnel_auth(self):
        directory = self.root / ".config" / "shopvivaliz-marina-mcp"
        directory.mkdir(parents=True, mode=0o700)
        directory.chmod(0o700)
        for name, lower, upper in m.SECRET_SIZES.values():
            f = directory / name
            f.write_bytes(b"X" * min(max(lower, 64), upper))
            f.chmod(0o600)
        fake_account = types.SimpleNamespace(pw_name=m.SERVICE_USER, pw_dir=str(self.root))
        fake_ss = types.SimpleNamespace(returncode=0,
                                        stdout="LISTEN 0 128 127.0.0.1:5580 0.0.0.0:*")
        service = {
            "LoadState": "loaded", "ActiveState": "active", "UnitFileState": "enabled",
            "NoNewPrivileges": "yes", "User": m.SERVICE_USER,
        }
        with patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST), \
             patch.object(m.pwd, "getpwuid", return_value=fake_account), \
             patch.object(m, "unit_snapshot", return_value=service), \
             patch.object(m, "binary_status", return_value="metadata_ok_version_unverified"), \
             patch.object(m.subprocess, "run", return_value=fake_ss):
            report = m.collect(home=self.root)
        self.assertTrue(report["local_preflight_candidate_only"])
        self.assertEqual(report["tunnel_authenticated"], "not_tested")
        self.assertEqual(report["chatgpt_mcp_connected"], "not_tested")
        self.assertEqual(report["migration_status"], "NO_CUT_NAO_APTO")

    def test_disabled_tunnel_unit_blocks_candidate(self):
        with patch.object(m.socket, "gethostname", return_value=m.EXPECTED_HOST), \
             patch.object(m, "unit_snapshot", return_value={
                 "LoadState": "loaded", "ActiveState": "active",
                 "UnitFileState": "disabled", "NoNewPrivileges": "yes",
                 "User": m.SERVICE_USER,
             }):
            result = m.collect(home=self.root)
        self.assertFalse(result["local_preflight_candidate_only"])


if __name__ == "__main__":
    unittest.main()
