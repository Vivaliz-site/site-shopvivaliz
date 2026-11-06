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
        self.assertNotEqual(self.m.BROWSER_ADMIN_DROPIN, self.m.base.BROWSER_DROPIN)
        self.assertIn("shopvivaliz-remote-control-browser-mcp.service.d",
                      self.m.BROWSER_ADMIN_DROPIN.as_posix())
        self.assertIn("MARINA_CENTRAL_ADMIN_ENABLED", self.m.ADMIN_FLAG_TEXT)
        self.assertNotIn("READONLY", self.m.ADMIN_FLAG_TEXT)

    def test_activate_requires_explicit_pr(self):
        for action in ("activate", "repair-browser"):
            with self.subTest(action=action), mock.patch.object(
                    sys, "argv", ["x", action]):
                self.assertEqual(self.m.main(), 1)

    def test_revoke_removes_only_admin_artifacts(self):
        with tempfile.TemporaryDirectory() as tmp:
            state, dropin = Path(tmp, "admin.json"), Path(tmp, "93.conf")
            browser = Path(tmp, "browser-admin.conf")
            readonly = Path(tmp, "readonly.json")
            for path in (state, dropin, browser, readonly):
                path.write_text("x")
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                    mock.patch.object(self.m, "ADMIN_DROPIN", dropin), \
                    mock.patch.object(self.m, "BROWSER_ADMIN_DROPIN", browser), \
                    mock.patch.object(self.m.base, "GRANT_STATE", readonly), \
                    mock.patch.object(self.m.os, "geteuid", return_value=0, create=True), \
                    mock.patch.object(self.m.socket, "gethostname", return_value=self.m.base.CONTROLLER), \
                    mock.patch.object(self.m.base, "restart_and_verify") as restart_base, \
                    mock.patch.object(self.m, "restart_browser_admin_and_verify") as restart_browser, \
                    mock.patch.object(sys, "argv", ["x", "revoke"]):
                self.assertEqual(self.m.main(), 0)
            self.assertFalse(state.exists() or dropin.exists() or browser.exists())
            self.assertTrue(readonly.exists())
            restart_base.assert_called_once()
            restart_browser.assert_called_once_with(expected_enabled=False)

    def test_repair_browser_preserves_admin_base_and_readonly_state(self):
        with tempfile.TemporaryDirectory() as tmp:
            state, base_flag = Path(tmp, "admin.json"), Path(tmp, "93.conf")
            browser, readonly = Path(tmp, "browser.conf"), Path(tmp, "readonly.json")
            state.write_text("attestation")
            base_flag.write_text(self.m.ADMIN_FLAG_TEXT)
            readonly.write_text("read-only is untouched")
            with mock.patch.object(self.m, "preflight", return_value={"approved": True}), \
                    mock.patch.object(self.m, "verify_current_admin_state") as verify, \
                    mock.patch.object(self.m, "validate_browser_admin_dropin", return_value=False), \
                    mock.patch.object(self.m, "ADMIN_STATE", state), \
                    mock.patch.object(self.m, "ADMIN_DROPIN", base_flag), \
                    mock.patch.object(self.m, "BROWSER_ADMIN_DROPIN", browser), \
                    mock.patch.object(self.m.base, "GRANT_STATE", readonly), \
                    mock.patch.object(self.m.base, "restart_and_verify") as restart_base, \
                    mock.patch.object(self.m, "restart_browser_admin_and_verify") as restart_browser, \
                    mock.patch.object(sys, "argv", ["x", "repair-browser", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 0)
            verify.assert_called_once_with({"approved": True})
            restart_browser.assert_called_once_with()
            restart_base.assert_not_called()
            self.assertEqual(browser.read_text(), self.m.ADMIN_FLAG_TEXT)
            self.assertEqual(base_flag.read_text(), self.m.ADMIN_FLAG_TEXT)
            self.assertEqual(state.read_text(), "attestation")
            self.assertEqual(readonly.read_text(), "read-only is untouched")

    def test_repair_browser_fails_closed_without_exact_attestation(self):
        with tempfile.TemporaryDirectory() as tmp:
            browser = Path(tmp, "browser.conf")
            with mock.patch.object(self.m, "preflight", return_value={"approved": True}), \
                    mock.patch.object(self.m, "verify_current_admin_state",
                                      side_effect=self.m.ActivationDenied("not_exact")), \
                    mock.patch.object(self.m, "BROWSER_ADMIN_DROPIN", browser), \
                    mock.patch.object(self.m, "restart_browser_admin_and_verify") as restart_browser, \
                    mock.patch.object(self.m.base, "restart_and_verify") as restart_base, \
                    mock.patch.object(sys, "argv", ["x", "repair-browser", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 1)
            self.assertFalse(browser.exists())
            restart_browser.assert_not_called()
            restart_base.assert_not_called()

    def test_repair_browser_rollback_does_not_remove_base_admin_or_readonly_grants(self):
        with tempfile.TemporaryDirectory() as tmp:
            browser, base_flag, readonly = (
                Path(tmp, "browser.conf"), Path(tmp, "base.conf"), Path(tmp, "readonly.json"))
            base_flag.write_text(self.m.ADMIN_FLAG_TEXT)
            readonly.write_text("keep")
            with mock.patch.object(self.m, "preflight", return_value={"approved": True}), \
                    mock.patch.object(self.m, "verify_current_admin_state"), \
                    mock.patch.object(self.m, "validate_browser_admin_dropin", return_value=False), \
                    mock.patch.object(self.m, "BROWSER_ADMIN_DROPIN", browser), \
                    mock.patch.object(self.m, "ADMIN_DROPIN", base_flag), \
                    mock.patch.object(self.m.base, "GRANT_STATE", readonly), \
                    mock.patch.object(self.m, "restart_browser_admin_and_verify",
                                      side_effect=[self.m.ActivationDenied("browser_not_ready"), None]) as restart_browser, \
                    mock.patch.object(self.m.base, "restart_and_verify") as restart_base, \
                    mock.patch.object(sys, "argv", ["x", "repair-browser", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 1)
            self.assertEqual(restart_browser.call_count, 2)
            restart_base.assert_not_called()
            self.assertFalse(browser.exists())
            self.assertTrue(base_flag.exists() and readonly.exists())

    def test_repair_browser_rejects_untrusted_existing_dropin(self):
        if sys.platform == "win32":
            self.skipTest("Linux ownership and mode enforcement")
        with tempfile.TemporaryDirectory() as tmp:
            browser = Path(tmp, "browser.conf")
            browser.write_text(self.m.ADMIN_FLAG_TEXT)
            browser.chmod(0o666)
            with mock.patch.object(self.m, "BROWSER_ADMIN_DROPIN", browser):
                with self.assertRaises(self.m.ActivationDenied):
                    self.m.validate_browser_admin_dropin()

    def test_browser_admin_check_uses_shell_tr_nul_not_python_nul(self):
        self.m.restart_browser_admin_and_verify()
        commands = [call.args[0] for call in self.run.call_args_list]
        self.assertIn(["systemctl", "restart", self.m.base.BROWSER_SERVICE], commands)
        shell = next(cmd[-1] for cmd in commands if cmd[:2] == ["/usr/bin/bash", "-c"])
        self.assertNotIn("\x00", shell)
        self.assertIn(r"tr '\0' '\n'", shell)
        self.assertIn("SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED=approved-v1", shell)
        self.assertNotIn("SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED", shell)

    def test_activation_writes_only_admin_dropins_for_two_endpoints(self):
        with tempfile.TemporaryDirectory() as tmp:
            state, base_flag, browser = (
                Path(tmp, "admin.json"), Path(tmp, "base.conf"), Path(tmp, "browser.conf"))
            readonly = Path(tmp, "readonly.json")
            readonly.write_text("preserve")
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                    mock.patch.object(self.m, "ADMIN_DROPIN", base_flag), \
                    mock.patch.object(self.m, "BROWSER_ADMIN_DROPIN", browser), \
                    mock.patch.object(self.m.base, "GRANT_STATE", readonly), \
                    mock.patch.object(self.m, "preflight", return_value={"approved": True}), \
                    mock.patch.object(self.m.base, "restart_and_verify") as restart_base, \
                    mock.patch.object(self.m, "restart_browser_admin_and_verify") as restart_browser, \
                    mock.patch.object(sys, "argv", ["x", "activate", "--pr", "3670"]):
                self.assertEqual(self.m.main(), 0)
            self.assertEqual(state.read_text(), '{"approved": true}\n')
            self.assertEqual(base_flag.read_text(), self.m.ADMIN_FLAG_TEXT)
            self.assertEqual(browser.read_text(), self.m.ADMIN_FLAG_TEXT)
            self.assertEqual(readonly.read_text(), "preserve")
            restart_base.assert_called_once()
            restart_browser.assert_called_once()

    @unittest.skipIf(sys.platform == "win32", "Linux file ownership and O_NOFOLLOW")
    def test_existing_admin_grant_is_exact_and_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            state, base_flag = Path(tmp, "admin.json"), Path(tmp, "base.conf")
            att = {"approved": True, "capabilities": ["marina_admin_command"]}
            state.write_text(json.dumps(att))
            state.chmod(0o600)
            base_flag.write_text(self.m.ADMIN_FLAG_TEXT)
            base_flag.chmod(0o644)
            with mock.patch.object(self.m, "ADMIN_STATE", state), \
                    mock.patch.object(self.m, "ADMIN_DROPIN", base_flag):
                self.m.verify_current_admin_state(att)
                with self.assertRaises(self.m.ActivationDenied):
                    self.m.verify_current_admin_state({"approved": False})
                state.unlink()
                state.symlink_to(base_flag)
                with self.assertRaises(self.m.ActivationDenied):
                    self.m.verify_current_admin_state(att)


if __name__ == "__main__":
    unittest.main()
