import base64
import importlib.util
import json
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

try:
    import pwd  # noqa: F401
except ImportError:  # Windows developer machines; the operator script runs on Linux
    sys.modules["pwd"] = types.ModuleType("pwd")

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ops/activate-mcp-central-marina-admin.py"
HEAD = "a" * 40
MERGE = "b" * 40


def load():
    spec = importlib.util.spec_from_file_location("activate_admin_under_test", SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def grant(pr=3670, caps=("marina_admin_command",), **extra):
    data = {
        "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1", "repository": "Vivaliz-site/site-shopvivaliz",
        "pr_number": pr, "resource": "mcp:central:marina", "principal": "shopvivaliz-central-mcp",
        "host": "marina-a1-4c24g", "controller_host": "always-free-arm-1787907847-26",
        "capabilities": list(caps),
    }
    data.update(extra)
    return data


def fake_github(pr_overrides=None, grant_doc=None):
    pr = {"auto_merge": None, "merged": True, "state": "closed", "user": {"login": "fredmourao-ai"},
          "merged_by": {"login": "shopvivaliz-human"}, "head": {"sha": HEAD}, "merge_commit_sha": MERGE}
    pr.update(pr_overrides or {})
    doc = grant_doc if grant_doc is not None else grant()
    content = base64.b64encode(json.dumps(doc).encode()).decode()

    def call(endpoint, token):
        return pr if endpoint.startswith("pulls/") else {"encoding": "base64", "content": content}
    return call


class MarinaAdminActivationTests(unittest.TestCase):
    def setUp(self):
        self.m = load()
        patcher = mock.patch.object(self.m.base, "run", return_value="")
        self.run = patcher.start()
        self.addCleanup(patcher.stop)

    def proof(self, **kwargs):
        with mock.patch.object(self.m.base, "github", fake_github(**kwargs)):
            return self.m.grant_proof(3670, "token")

    def test_attestation_is_admin_only_and_matches_server_schema(self):
        att = self.proof()
        self.assertEqual(att["capabilities"], ["marina_admin_command"])
        self.assertEqual(att["resource"], "mcp:central:marina")
        self.assertEqual((att["reviewer"], att["merged_by"]), ("shopvivaliz-human", "shopvivaliz-human"))
        self.assertNotIn("expires_at_utc", att)

    def test_validator_runs_with_admin_capability(self):
        self.proof()
        argv = self.run.call_args.args[0]
        self.assertEqual(argv[argv.index("--capability") + 1], "marina_admin_command")

    def test_readonly_grant_document_is_rejected(self):
        with self.assertRaises(self.m.ActivationDenied):
            self.proof(grant_doc=grant(caps=("marina_readonly",)))

    def test_unmerged_or_foreign_merger_is_rejected(self):
        for override in ({"merged": False}, {"merged_by": {"login": "someone-else"}},
                         {"user": {"login": "shopvivaliz-human"}}):
            with self.subTest(override=override), self.assertRaises(self.m.ActivationDenied):
                self.proof(pr_overrides=override)

    def test_expired_grant_is_rejected_and_future_is_kept(self):
        with self.assertRaises(self.m.ActivationDenied):
            self.proof(grant_doc=grant(expires_at_utc="2020-01-01T00:00:00Z"))
        att = self.proof(grant_doc=grant(expires_at_utc="2999-01-01T00:00:00Z"))
        self.assertEqual(att["expires_at_utc"], "2999-01-01T00:00:00Z")

    def test_paths_never_collide_with_readonly_route(self):
        self.assertNotEqual(self.m.ADMIN_STATE, self.m.base.GRANT_STATE)
        self.assertNotEqual(self.m.ADMIN_DROPIN, self.m.base.DROPIN)
        self.assertIn("MARINA_CENTRAL_ADMIN_ENABLED", self.m.ADMIN_FLAG_TEXT)
        self.assertNotIn("READONLY", self.m.ADMIN_FLAG_TEXT)

    def test_activate_requires_explicit_pr(self):
        with mock.patch.object(sys, "argv", ["x", "activate"]):
            self.assertEqual(self.m.main(), 1)

    def test_revoke_removes_only_admin_artifacts(self):
        with tempfile.TemporaryDirectory() as tmp:
            state, dropin = Path(tmp, "admin.json"), Path(tmp, "93.conf")
            readonly = Path(tmp, "readonly.json")
            for path in (state, dropin, readonly):
                path.write_text("x")
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                    mock.patch.object(self.m, "ADMIN_DROPIN", dropin), \
                    mock.patch.object(self.m.base, "GRANT_STATE", readonly), \
                    mock.patch.object(self.m.os, "geteuid", return_value=0, create=True), \
                    mock.patch.object(self.m.socket, "gethostname", return_value=self.m.base.CONTROLLER), \
                    mock.patch.object(self.m.base, "restart_and_verify"), \
                    mock.patch.object(sys, "argv", ["x", "revoke"]):
                self.assertEqual(self.m.main(), 0)
            self.assertFalse(state.exists() or dropin.exists())
            self.assertTrue(readonly.exists())


    def test_admin_browser_flag_is_separate(self):
        self.assertNotEqual(self.m.ADMIN_BROWSER_DROPIN, self.m.ADMIN_DROPIN)
        self.assertNotEqual(self.m.ADMIN_BROWSER_DROPIN, self.m.base.BROWSER_DROPIN)
        self.assertIn("SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED", self.m.ADMIN_FLAG_TEXT)
        self.assertEqual(self.m.ADMIN_FLAG_TEXT, "[Service]\nEnvironment=SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED=approved-v1\n")

    def test_browser_repair_requires_explicit_owner_grant(self):
        with mock.patch.object(sys, "argv", ["x", "repair-browser"]), \
             mock.patch.object(self.m, "preflight") as preflight:
            self.assertEqual(self.m.main(), 1)
            preflight.assert_not_called()

    def test_browser_repair_preserves_existing_admin_grant_and_base_service(self):
        with tempfile.TemporaryDirectory() as tmp:
            att = self.proof()
            state = Path(tmp, "admin-grant.json")
            base_dropin = Path(tmp, "base-admin.conf")
            browser_dropin = Path(tmp, "browser-admin.conf")
            state.write_text(json.dumps(att))
            state.chmod(0o600)
            base_dropin.write_text(self.m.ADMIN_FLAG_TEXT)
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                 mock.patch.object(self.m, "ADMIN_DROPIN", base_dropin), \
                 mock.patch.object(self.m, "ADMIN_BROWSER_DROPIN", browser_dropin), \
                 mock.patch.object(self.m, "preflight", return_value=att), \
                 mock.patch.object(self.m, "restart_browser_and_verify") as browser_restart, \
                 mock.patch.object(self.m.base, "restart_and_verify") as base_restart, \
                 mock.patch.object(sys, "argv", ["x", "repair-browser", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 0)
            self.assertEqual(browser_dropin.read_text(), self.m.ADMIN_FLAG_TEXT)
            self.assertEqual(browser_dropin.stat().st_mode & 0o777, 0o644)
            self.assertEqual(json.loads(state.read_text()), att)
            self.assertEqual(base_dropin.read_text(), self.m.ADMIN_FLAG_TEXT)
            browser_restart.assert_called_once_with()
            base_restart.assert_not_called()

    def test_browser_repair_denies_stale_owner_attestation(self):
        with tempfile.TemporaryDirectory() as tmp:
            att = self.proof()
            state = Path(tmp, "admin-grant.json")
            base_dropin = Path(tmp, "base-admin.conf")
            browser_dropin = Path(tmp, "browser-admin.conf")
            state.write_text(json.dumps({**att, "pr_number": 1234}))
            state.chmod(0o600)
            base_dropin.write_text(self.m.ADMIN_FLAG_TEXT)
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                 mock.patch.object(self.m, "ADMIN_DROPIN", base_dropin), \
                 mock.patch.object(self.m, "ADMIN_BROWSER_DROPIN", browser_dropin), \
                 mock.patch.object(self.m, "preflight", return_value=att), \
                 mock.patch.object(self.m, "restart_browser_and_verify") as restart, \
                 mock.patch.object(sys, "argv", ["x", "repair-browser", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 1)
            self.assertFalse(browser_dropin.exists())
            restart.assert_not_called()

    def test_browser_repair_denies_unexpected_existing_dropin(self):
        with tempfile.TemporaryDirectory() as tmp:
            att = self.proof()
            state = Path(tmp, "admin-grant.json")
            base_dropin = Path(tmp, "base-admin.conf")
            browser_dropin = Path(tmp, "browser-admin.conf")
            state.write_text(json.dumps(att))
            state.chmod(0o600)
            base_dropin.write_text(self.m.ADMIN_FLAG_TEXT)
            browser_dropin.write_text("[Service]\nEnvironment=UNEXPECTED=1\n")
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                 mock.patch.object(self.m, "ADMIN_DROPIN", base_dropin), \
                 mock.patch.object(self.m, "ADMIN_BROWSER_DROPIN", browser_dropin), \
                 mock.patch.object(self.m, "preflight", return_value=att), \
                 mock.patch.object(self.m, "restart_browser_and_verify") as restart, \
                 mock.patch.object(sys, "argv", ["x", "repair-browser", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 1)
            self.assertIn("UNEXPECTED", browser_dropin.read_text())
            restart.assert_not_called()

    def test_browser_repair_rolls_back_its_flag_not_the_base_grant(self):
        with tempfile.TemporaryDirectory() as tmp:
            att = self.proof()
            state = Path(tmp, "admin-grant.json")
            base_dropin = Path(tmp, "base-admin.conf")
            browser_dropin = Path(tmp, "browser-admin.conf")
            state.write_text(json.dumps(att))
            state.chmod(0o600)
            base_dropin.write_text(self.m.ADMIN_FLAG_TEXT)
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                 mock.patch.object(self.m, "ADMIN_DROPIN", base_dropin), \
                 mock.patch.object(self.m, "ADMIN_BROWSER_DROPIN", browser_dropin), \
                 mock.patch.object(self.m, "preflight", return_value=att), \
                 mock.patch.object(self.m, "restart_browser_and_verify",
                                   side_effect=[self.m.ActivationDenied("browser_unhealthy"), None]) as restart, \
                 mock.patch.object(self.m.base, "restart_and_verify") as base_restart, \
                 mock.patch.object(sys, "argv", ["x", "repair-browser", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 1)
            self.assertEqual(restart.call_count, 2)
            self.assertFalse(browser_dropin.exists())
            self.assertTrue(state.exists() and base_dropin.exists())
            base_restart.assert_not_called()

    def test_revoke_removes_admin_browser_but_preserves_readonly(self):
        with tempfile.TemporaryDirectory() as tmp:
            state = Path(tmp, "admin.json")
            base_dropin = Path(tmp, "admin-base.conf")
            browser_dropin = Path(tmp, "admin-browser.conf")
            readonly = Path(tmp, "readonly.json")
            for path in (state, base_dropin, browser_dropin, readonly):
                path.write_text("x")
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                 mock.patch.object(self.m, "ADMIN_DROPIN", base_dropin), \
                 mock.patch.object(self.m, "ADMIN_BROWSER_DROPIN", browser_dropin), \
                 mock.patch.object(self.m.base, "GRANT_STATE", readonly), \
                 mock.patch.object(self.m.os, "geteuid", return_value=0, create=True), \
                 mock.patch.object(self.m.socket, "gethostname", return_value=self.m.base.CONTROLLER), \
                 mock.patch.object(self.m.base, "restart_and_verify") as base_restart, \
                 mock.patch.object(self.m, "restart_browser_and_verify") as browser_restart, \
                 mock.patch.object(sys, "argv", ["x", "revoke"]):
                self.assertEqual(self.m.main(), 0)
            self.assertFalse(state.exists() or base_dropin.exists() or browser_dropin.exists())
            self.assertTrue(readonly.exists())
            base_restart.assert_called_once()
            browser_restart.assert_called_once_with(expected_enabled=False)

    def test_browser_restart_checks_exact_effective_admin_flag(self):
        self.m.restart_browser_and_verify()
        calls = [c.args[0] for c in self.run.call_args_list]
        self.assertIn(["systemctl", "restart", self.m.BROWSER_SERVICE], calls)
        check = next(argv[-1] for argv in calls if argv[:2] == ["/usr/bin/bash", "-c"])
        self.assertIn("MainPID", check)
        self.assertIn("/proc/$pid/environ", check)
        self.assertIn("SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED=approved-v1", check)
        self.assertNotIn("printenv", check)


if __name__ == "__main__":
    unittest.main()
