"""Negative/positive contracts for owner-gated central Marina route activation.

Tests never contact GitHub, start a service, or read real credentials.
"""
import base64
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "ops" / "activate-mcp-central-marina-readonly.py"
spec = importlib.util.spec_from_file_location("marina_central_activation", SCRIPT)
assert spec and spec.loader
a = importlib.util.module_from_spec(spec)
spec.loader.exec_module(a)

PR_NUMBER = 3478
HEAD = "a" * 40
MERGE = "b" * 40


def sample_pr():
    return {
        "merged": True, "state": "closed", "auto_merge": None,
        "user": {"login": "fredmourao-ai"}, "merged_by": {"login": a.OWNER},
        "head": {"sha": HEAD}, "merge_commit_sha": MERGE,
    }


def sample_grant():
    return {
        "schema": a.PR_SCHEMA, "repository": a.REPOSITORY,
        "pr_number": PR_NUMBER, "resource": "mcp:central:marina",
        "principal": "shopvivaliz-central-mcp",
        "host": a.MARINA, "controller_host": a.CONTROLLER,
        "capabilities": ["marina_readonly"],
        "expires_at_utc": "2099-02-01T00:00:00Z",
    }


class OwnerActivationTests(unittest.TestCase):
    def proof(self, pr=None, grant=None):
        pr = pr if pr is not None else sample_pr()
        grant = grant if grant is not None else sample_grant()

        def fake_api(endpoint, _token):
            if endpoint == f"pulls/{PR_NUMBER}":
                return pr
            if endpoint.startswith("contents/security/owner-approvals/"):
                return {
                    "encoding": "base64",
                    "content": base64.b64encode(json.dumps(grant).encode()).decode(),
                }
            raise AssertionError(f"unrecognized endpoint {endpoint}")

        with patch.object(a, "github", side_effect=fake_api), patch.object(
                a, "run", return_value="OWNER_PR_GRANT=AUTHORIZED"):
            return a.grant_proof(PR_NUMBER, "fixture-token")

    def test_exact_owner_approved_grant_produces_read_only_attestation(self):
        found = self.proof()
        self.assertEqual(found["capabilities"], ["marina_readonly"])
        self.assertEqual(found["principal"], "shopvivaliz-central-mcp")
        self.assertEqual(found["head_sha"], HEAD)
        self.assertEqual(found["merge_sha"], MERGE)
        self.assertEqual(found["merged_by"], a.OWNER)
        self.assertEqual(found["reviewer"], a.OWNER)

    def test_pr_requires_human_owner_merge_actor_distinct_from_author(self):
        for field, bad in (
            ("merged", False), ("state", "open"),
            ("auto_merge", {"merge_method": "squash"}),
            ("merged_by", {"login": "fredmourao-ai"}),
            ("user", {"login": a.OWNER}),
            ("head", {"sha": "not-valid"}),
        ):
            with self.subTest(field=field):
                pr = dict(sample_pr(), **{field: bad})
                with self.assertRaises(a.ActivationDenied):
                    self.proof(pr=pr)

    def test_owner_approved_auto_merge_allows_scoped_central_activation(self):
        for method in ("merge", "squash", "rebase"):
            with self.subTest(method=method):
                pr = dict(sample_pr(), auto_merge={
                    "merge_method": method,
                    "enabled_by": {"login": a.OWNER},
                })
                found = self.proof(pr=pr)
                self.assertEqual(found["merged_by"], a.OWNER)
                self.assertEqual(found["head_sha"], HEAD)

    def test_automated_or_untrusted_auto_merge_enablement_denied(self):
        bad_meta = (
            {"merge_method": "merge", "enabled_by": {"login": "fredmourao-ai"}},
            {"merge_method": "merge", "enabled_by": {"login": "github-actions[bot]"}},
            {"merge_method": "merge"},
            {"enabled_by": {"login": a.OWNER}},
            {"merge_method": "unexpected", "enabled_by": {"login": a.OWNER}},
        )
        for auto_merge in bad_meta:
            with self.subTest(auto_merge=auto_merge):
                pr = dict(sample_pr(), auto_merge=auto_merge)
                with self.assertRaises(a.ActivationDenied):
                    self.proof(pr=pr)

    def test_exact_grant_host_principal_and_scope(self):
        for field, bad in (
            ("resource", "mcp:marina"),
            ("principal", "root"),
            ("host", "shopvivaliz-free-a1"),
            ("controller_host", "shopvivaliz-free-a1"),
            ("capabilities", ["marina_readonly", "admin_command_run"]),
            ("pr_number", 3477),
            ("expires_at_utc", "2020-01-01T00:00:00Z"),
        ):
            with self.subTest(field=field):
                with self.assertRaises(a.ActivationDenied):
                    self.proof(grant=dict(sample_grant(), **{field: bad}))

    def test_fail_closed_on_official_verifier_rejection(self):
        def fake_api(endpoint, _token):
            if endpoint == f"pulls/{PR_NUMBER}":
                return sample_pr()
            raise AssertionError("must not fetch grant when verification fails")
        with patch.object(a, "github", side_effect=fake_api), patch.object(
                a, "run", side_effect=a.ActivationDenied("official_verification_or_command_failed")):
            with self.assertRaises(a.ActivationDenied):
                a.grant_proof(PR_NUMBER, "fixture-token")

    def test_preflight_and_activate_require_explicit_non_revoked_pr(self):
        for action in ("preflight", "activate"):
            with self.subTest(action=action), patch.object(
                    a, "preflight") as checker, patch.object(
                    a, "write_private") as writer, patch.object(
                    sys, "argv", ["activation", action]):
                self.assertEqual(a.main(), 1)
                checker.assert_not_called()
                writer.assert_not_called()

    def test_non_root_cannot_mutate_or_run_preflight(self):
        with patch.object(a.os, "geteuid", return_value=1000):
            with self.assertRaises(a.ActivationDenied):
                a.preflight(PR_NUMBER)

    def test_activation_failure_does_not_touch_service_or_state(self):
        with patch.object(a, "preflight", side_effect=a.ActivationDenied("unmerged")), \
             patch.object(a, "write_private") as write, \
             patch.object(a, "restart_and_verify") as restart, \
             patch.object(sys, "argv", ["activation", "activate", "--pr", str(PR_NUMBER)]):
            self.assertEqual(a.main(), 1)
            write.assert_not_called()
            restart.assert_not_called()

    def test_private_state_file_is_written_atomically_0600(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "grant.json"
            a.write_private(path, json.dumps(sample_grant()))
            self.assertEqual(json.loads(path.read_text()), sample_grant())
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)

    def test_revocation_requires_root_on_correct_host(self):
        with patch.object(a.os, "geteuid", return_value=1000), \
             patch.object(a, "restart_and_verify") as restart, \
             patch.object(sys, "argv", ["activation", "revoke"]):
            self.assertEqual(a.main(), 1)
            restart.assert_not_called()


    def test_browser_bridge_repair_only_after_exact_owner_attestation(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            att = self.proof()
            state = root / "grant.json"
            base_dropin = root / "base.conf"
            browser_dropin = root / "browser.conf"
            state.write_text(json.dumps(att))
            base_dropin.write_text('[Service]\nEnvironment=SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED=approved-v1\n')
            with patch.object(a, "GRANT_STATE", state), \
                 patch.object(a, "DROPIN", base_dropin), \
                 patch.object(a, "BROWSER_DROPIN", browser_dropin), \
                 patch.object(a, "preflight", return_value=att), \
                 patch.object(a, "restart_browser_and_verify") as restart_browser, \
                 patch.object(a, "restart_and_verify") as restart_base, \
                 patch.object(sys, "argv", ["activation", "repair-browser", "--pr", str(PR_NUMBER)]):
                self.assertEqual(a.main(), 0)
                self.assertEqual(browser_dropin.read_text(), base_dropin.read_text())
                self.assertEqual(stat.S_IMODE(browser_dropin.stat().st_mode), 0o644)
                restart_browser.assert_called_once()
                restart_base.assert_not_called()
                self.assertEqual(json.loads(state.read_text()), att)

    def test_browser_bridge_repair_denies_stale_or_replaced_grant(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            att = self.proof()
            state = root / "grant.json"
            base_dropin = root / "base.conf"
            browser_dropin = root / "browser.conf"
            state.write_text(json.dumps({**att, "pr_number": 3477}))
            base_dropin.write_text('[Service]\nEnvironment=SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED=approved-v1\n')
            with patch.object(a, "GRANT_STATE", state), \
                 patch.object(a, "DROPIN", base_dropin), \
                 patch.object(a, "BROWSER_DROPIN", browser_dropin), \
                 patch.object(a, "preflight", return_value=att), \
                 patch.object(a, "restart_browser_and_verify") as restart_browser, \
                 patch.object(sys, "argv", ["activation", "repair-browser", "--pr", str(PR_NUMBER)]):
                self.assertEqual(a.main(), 1)
                self.assertFalse(browser_dropin.exists())
                restart_browser.assert_not_called()

    def test_browser_bridge_repair_rollback_preserves_base_grant(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            att = self.proof()
            state = root / "grant.json"
            base_dropin = root / "base.conf"
            browser_dropin = root / "browser.conf"
            state.write_text(json.dumps(att))
            base_dropin.write_text('[Service]\nEnvironment=SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED=approved-v1\n')
            with patch.object(a, "GRANT_STATE", state), \
                 patch.object(a, "DROPIN", base_dropin), \
                 patch.object(a, "BROWSER_DROPIN", browser_dropin), \
                 patch.object(a, "preflight", return_value=att), \
                 patch.object(a, "restart_browser_and_verify",
                              side_effect=[a.ActivationDenied("browser_unhealthy"), None]) as reboot, \
                 patch.object(a, "restart_and_verify") as restart_base, \
                 patch.object(sys, "argv", ["activation", "repair-browser", "--pr", str(PR_NUMBER)]):
                self.assertEqual(a.main(), 1)
                self.assertEqual(reboot.call_count, 2)
                self.assertFalse(browser_dropin.exists())
                self.assertTrue(state.exists())
                restart_base.assert_not_called()

    def test_source_contains_no_automatic_privilege_bypass(self):
        server = (ROOT / "remote-control-mcp" / "server.py").read_text()
        self.assertIn("marina_central_readonly_grant_active()", server)
        self.assertIn("os.O_NOFOLLOW", server)
        self.assertIn("st.st_nlink != 1", server)
        self.assertNotIn("marina_central_mutation_enabled = True", server)
        script = SCRIPT.read_text()
        self.assertIn('"--capability", "marina_readonly"', script)
        self.assertIn("GRANT_STATE.unlink", script)
        self.assertNotIn("StrictHostKeyChecking=no", script)


if __name__ == "__main__":
    unittest.main()
