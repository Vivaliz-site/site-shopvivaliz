"""Regression tests for the scoped, non-secret Marina login MCP command."""
import ast
import importlib.util
import json
import subprocess
from pathlib import Path
from unittest import TestCase, main, mock

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "scripts" / "marina_chatgpt_login.py"
SERVER = ROOT / "remote-control-mcp" / "server.py"
spec = importlib.util.spec_from_file_location("marina_login_test_target", HELPER)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class HelperContract(TestCase):
    def setUp(self):
        with mock.patch.object(mod, "readiness"), mock.patch.object(
            mod, "browser_window", return_value=("12345", "Log in or sign up - OpenAI - Mozilla Firefox")
        ), mock.patch.object(mod, "run_xdotool", return_value="") as gui:
            self.gui = gui
            self.result = mod.execute("dev", "status")

    def test_uses_account_scoped_fixed_actions(self):
        self.assertEqual(mod.ACCOUNT, "dev")
        self.assertEqual(mod.ACTIONS, ("status", "open_login", "fill_email", "continue"))
        with self.assertRaisesRegex(mod.LoginUnavailable, "invalid_account_or_action"):
            mod.execute("atendimento", "status")
        with self.assertRaisesRegex(mod.LoginUnavailable, "invalid_account_or_action"):
            mod.execute("dev", "fill_password")

    def test_status_never_claims_identity_authenticated(self):
        self.assertEqual(self.result["stage"], "login_or_home")
        self.assertIs(self.result["identity_verified"], False)
        self.assertNotIn("url", self.result)

    def test_challenge_and_home_are_not_login_email_stage(self):
        for title in (
            "Just a moment... - Mozilla Firefox",
            "ChatGPT - Mozilla Firefox",
            "Enter your password - OpenAI - Mozilla Firefox",
        ):
            with self.subTest(title=title), mock.patch.object(mod, "readiness"), \
                 mock.patch.object(mod, "browser_window", return_value=("123", title)), \
                 mock.patch.object(mod, "run_xdotool") as gui:
                with self.assertRaisesRegex(mod.LoginUnavailable, "login_email_stage_required"):
                    mod.execute("dev", "fill_email")
                gui.assert_not_called()

    def test_email_is_fixed_to_corporate_dev(self):
        with mock.patch.object(mod, "readiness"), \
             mock.patch.object(mod, "browser_window", return_value=("123", "Log in or sign up - OpenAI - Mozilla Firefox")), \
             mock.patch.object(mod, "run_xdotool") as gui:
            result = mod.execute("dev", "fill_email")
        self.assertTrue(result["ok"])
        self.assertIn(mock.call("type", "--clearmodifiers", "dev@shopvivaliz.com.br"), gui.call_args_list)
        self.assertIs(result["identity_verified"], False)

    def test_open_login_only_uses_hardcoded_official_address(self):
        with mock.patch.object(mod, "readiness"), \
             mock.patch.object(mod, "browser_window", return_value=("123", "ChatGPT - Mozilla Firefox")), \
             mock.patch.object(mod, "run_xdotool") as gui:
            mod.execute("dev", "open_login")
        self.assertIn(mock.call("type", "--clearmodifiers", "https://chatgpt.com/auth/login"), gui.call_args_list)
        self.assertFalse(any("javascript:" in str(x) for x in gui.call_args_list))

    def test_continue_never_submits_blank_password_or_mfa(self):
        for title in (
            "Just a moment... - Mozilla Firefox",
            "ChatGPT - Mozilla Firefox",
            "Enter your password - OpenAI - Mozilla Firefox",
            "Verify your identity - OpenAI - Mozilla Firefox",
        ):
            with self.subTest(title=title), mock.patch.object(mod, "readiness"), \
                 mock.patch.object(mod, "browser_window", return_value=("123", title)), \
                 mock.patch.object(mod, "run_xdotool") as gui:
                with self.assertRaisesRegex(mod.LoginUnavailable, "official_login_stage_required"):
                    mod.execute("dev", "continue")
                gui.assert_not_called()

    def test_main_returns_sanitized_outcomes(self):
        with mock.patch.object(mod, "readiness", side_effect=mod.LoginUnavailable("correct_user_required")), \
             mock.patch("sys.argv", ["tool", "--session", "dev", "--action", "status"]), \
             mock.patch("builtins.print") as output:
            self.assertEqual(mod.main(), 1)
        payload = json.loads(output.call_args.args[0])
        self.assertFalse(payload["ok"])
        self.assertEqual(payload["reason"], "correct_user_required")


class MCPContract(TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SERVER.read_text()
        cls.tree = ast.parse(cls.source)

    def test_tool_exposed_with_fixed_login_scope(self):
        self.assertIn('("marina_chatgpt_login",', self.source)
        self.assertIn("MARINA_CHATGPT_LOGIN_HELPER", self.source)
        self.assertIn('MARINA_CHATGPT_LOGIN_ACTIONS = frozenset({', self.source)

    def test_reuses_existing_maintenance_lease_and_approved_marina_grant(self):
        method = next(n for n in self.tree.body if isinstance(n, ast.FunctionDef)
                      and n.name == "marina_chatgpt_login_tool")
        code = ast.get_source_segment(self.source, method)
        self.assertIn("marina_central_admin_grant_active()", code)
        self.assertIn("assert_runtime_lock(", code)
        self.assertIn('"browser_auth_action"', code)
        self.assertIn('"shopvivaliz-account-auth:" + session', code)
        self.assertIn("marina_admin=True", code)
        self.assertIn("recover_transport=False", code)

    def test_does_not_accept_credentials_or_arbitrary_urls(self):
        method = next(n for n in self.tree.body if isinstance(n, ast.FunctionDef)
                      and n.name == "marina_chatgpt_login_tool")
        code = ast.get_source_segment(self.source, method)
        self.assertIn('{"session", "action", "runtime_lease_id", "runtime_fencing_token"}', code)
        self.assertNotIn('args.get("password")', code)
        self.assertNotIn('args.get("url")', code)
        self.assertNotIn('args.get("value")', code)
        self.assertNotIn("read_password", HELPER.read_text())
        self.assertNotIn("_local_totp", HELPER.read_text())


if __name__ == "__main__":
    main()
