#!/usr/bin/env python3
"""Regression tests for pre-login account-scoped MCP operations."""
from __future__ import annotations

import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
os.environ["SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER"] = str(ROOT / "remote-control-mcp" / "server.py")
os.environ["SHOPVIVALIZ_REMOTE_MCP_STATE"] = "/tmp/shopvivaliz-account-auth-contract-tests"
os.environ["SHOPVIVALIZ_REMOTE_MCP_PORT"] = "0"
SPEC = importlib.util.spec_from_file_location("browser_auth_contract", ROOT / "remote-control-browser-mcp/server.py")
assert SPEC and SPEC.loader
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)

def allowed_lease(session="dev"):
    return {
        "owner_kind": "maintenance",
        "owner_id": "shopvivaliz-account-auth:" + session,
        "allowed_actions": ["browser_auth_action", "browser_auth_open"],
        "lease_id": "example-lease",
        "fencing_token": 1,
    }

def payload(session="dev", action="fill_email", value="dev@shopvivaliz.com.br"):
    return {
        "session": session, "tab_id": "example-tab",
        "action": action, "value": value,
        "runtime_lease_id": "example-lease", "runtime_fencing_token": 1,
    }

class AccountAuthGateTests(unittest.TestCase):
    def test_new_tools_are_explicit_and_report_sensitive_mutation(self):
        specs = {s["name"]: s for s in m.tool_specs()}
        self.assertIn("browser_auth_action", specs)
        self.assertIn("browser_auth_tabs", specs)
        self.assertIn("browser_auth_open", specs)
        self.assertFalse(specs["browser_auth_open"]["annotations"]["readOnlyHint"])
        self.assertFalse(specs["browser_auth_open"]["annotations"]["destructiveHint"])
        self.assertFalse(specs["browser_auth_action"]["annotations"]["readOnlyHint"])
        self.assertTrue(specs["browser_auth_action"]["annotations"]["destructiveHint"])
        self.assertTrue(specs["browser_auth_tabs"]["annotations"]["readOnlyHint"])

    def test_without_runtime_lease_auth_is_denied(self):
        args = payload()
        args.pop("runtime_lease_id")
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with self.assertRaisesRegex(ValueError, "account_auth_runtime_lock_required"):
                m.browser_auth_action(args)

    def test_disabled_handoff_auth_is_denied(self):
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=False):
            with self.assertRaisesRegex(ValueError, "account_auth_lease_mode_required"):
                m.browser_auth_action(payload())

    def test_invalid_or_unrelated_lease_auth_is_denied(self):
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock", return_value={
                **allowed_lease(), "owner_id": "different-workflow"
            }):
                with self.assertRaisesRegex(ValueError, "account_auth_maintenance_owner_required"):
                    m.browser_auth_action(payload())

    def test_cross_account_or_nonofficial_email_denied(self):
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock", return_value=allowed_lease("dev")):
                with self.assertRaisesRegex(ValueError, "account_auth_maintenance_owner_required"):
                    m.browser_auth_action(payload(session="atendimento",value="atendimento@shopvivaliz.com.br"))
                with self.assertRaisesRegex(ValueError, "account_auth_email_mismatch"):
                    m.browser_auth_action(payload(value="atendimento@shopvivaliz.com.br"))

    def test_password_and_code_are_stdin_only(self):
        fake = {"exit_code": 0, "stdout": '{"ok":true}', "stderr": ""}
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock", return_value=allowed_lease("dev")):
                with mock.patch.object(m.base, "run_local_command_with_stdin", return_value=fake) as run:
                    result = m.browser_auth_action(payload(action="fill_password", value="test-password-placeholder"))
                    self.assertTrue(result["ok"])
                    argv, text, *_ = run.call_args.args
                    self.assertEqual("test-password-placeholder", text)
                    self.assertNotIn(text, " ".join(argv))
                    self.assertIn("Input.insertText", argv[3])
                    self.assertEqual(["dev", "example-tab", "fill_password"], argv[-3:])

    def test_verification_code_format_is_constrained(self):
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock", return_value=allowed_lease("dev")):
                with self.assertRaisesRegex(ValueError, "account_auth_code_invalid"):
                    m.browser_auth_action(payload(action="fill_code",value="123;echo bad"))
                with self.assertRaisesRegex(ValueError, "account_auth_value_not_allowed"):
                    m.browser_auth_action(payload(action="continue",value="unexpected"))

    def test_audit_never_records_submitted_value(self):
        audit_args = {}
        def fake_audit(_tool, _host, args, _ok, _summary):
            audit_args.update(args)
            return "audit-test"
        with mock.patch.object(m, "BASE_AUDIT", fake_audit):
            m.audit("browser_auth_action", None,
                    {"session": "dev", "action": "fill_code", "value": "CODE_PLACEHOLDER"}, True, "ok")
        self.assertNotIn("value", audit_args)
        self.assertEqual(len("CODE_PLACEHOLDER"), audit_args["value_length"])

    def test_url_filter_rejects_consent_and_outside_hosts(self):
        self.assertEqual("/email-verification",m._auth_stage("https://auth.openai.com/email-verification?code=SECRET"))
        for url in ("https://chatgpt.com/c/123", "https://auth.openai.com/authorize?scope=all",
                    "https://example.com/log-in", "http://auth.openai.com/log-in"):
            self.assertIsNone(m._auth_stage(url))

    def test_official_method_selection_stages_are_sanitized(self):
        self.assertEqual("/log-in-or-create-account", m._auth_stage(
            "https://auth.openai.com/log-in-or-create-account?nonce=SECRET"
        ))
        self.assertEqual("/auth/login_with", m._auth_stage(
            "https://chatgpt.com/auth/login_with?state=SECRET"
        ))
        for url in (
            "https://auth.openai.com/log-in-or-create-account/consent",
            "https://chatgpt.com/auth/login_with/authorize",
            "https://accounts.google.com/auth/login_with",
            "http://chatgpt.com/auth/login_with",
        ):
            self.assertIsNone(m._auth_stage(url))

    def test_prelogin_navigation_actions_require_account_scoped_maintenance_lease(self):
        fake = {"exit_code": 0, "stdout": '{"ok":true}', "stderr": ""}
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock", return_value=allowed_lease("atendimento")):
                with mock.patch.object(m.base, "run_local_command_with_stdin", return_value=fake) as run:
                    for action in ("back_to_methods", "open_login", "continue_google", "continue_microsoft"):
                        args = payload(session="atendimento", action=action, value="")
                        result = m.browser_auth_action(args)
                        self.assertTrue(result["ok"])
                        argv, value, *_ = run.call_args.args
                        self.assertEqual(value, "")
                        self.assertEqual(argv[-3:], ["atendimento", "example-tab", action])
                        with self.assertRaisesRegex(ValueError, "account_auth_value_not_allowed"):
                            m.browser_auth_action({**args, "value": "unexpected"})
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock", return_value=allowed_lease("dev")):
                with self.assertRaisesRegex(ValueError, "account_auth_maintenance_owner_required"):
                    m.browser_auth_action(payload(session="atendimento", action="continue_google", value=""))

    def test_prelogin_links_are_exact_path_and_host_scoped(self):
        source = m.ACCOUNT_AUTH_NODE_SCRIPT
        for item in (
            "back_to_methods", "open_login", "continue_google",
            "u.pathname!=='/log-in/password'",
            "u.origin!=='https://auth.openai.com'",
            "u.pathname!=='/log-in-or-create-account'",
            "u.pathname!=='/log-in'",
            "u.pathname==='/auth/login_with'",
            "u.pathname==='/log-in'",
            "safeLink(a,'https://auth.openai.com','/log-in-or-create-account')",
            "safeLink(a,'https://chatgpt.com','/auth/login_with')",
            "new RegExp('^continue with '+provider+'$','i')",
            "if(choices.length!==1)",
        ):
            self.assertIn(item, source)

    def test_log_in_link_is_allowed_only_on_two_exact_official_stages(self):
        source = m.ACCOUNT_AUTH_NODE_SCRIPT
        self.assertIn(
            "if(u.origin!=='https://auth.openai.com'||(u.pathname!=='/log-in-or-create-account'&&u.pathname!=='/log-in'))throw Error('auth_stage_not_allowed');",
            source,
        )
        self.assertIn(
            "safeLink(a,'https://chatgpt.com','/auth/login_with')&&/^log in$/i.test(label(a))",
            source,
        )
        self.assertEqual("/log-in", m._auth_stage("https://auth.openai.com/log-in?state=SECRET"))
        self.assertIsNone(m._auth_stage("https://auth.openai.com/oauth/consent"))
        self.assertIsNone(m._auth_stage("https://example.com/log-in"))

    def test_sanitized_tabs_return_no_auth_query_or_tokens(self):
        pages = [
            {"type": "page", "id": "tab_a", "url": "https://auth.openai.com/email-verification?state=SECRET"},
            {"type": "page", "id": "tab_b", "url": "https://chatgpt.com/c/OTHER"},
            {"type": "page", "id": "tab_c", "url": "https://auth.openai.com/authorize?scope=SECRET"},
        ]
        with mock.patch.object(m, "urlopen", return_value=io.BytesIO(json.dumps(pages).encode())):
            result = m.browser_auth_tabs({"session":"dev"})
        self.assertEqual([{"tab_id":"tab_a","stage":"/email-verification","session":"dev"}], result["tabs"])
        self.assertNotIn("SECRET", json.dumps(result))

    def test_login_tab_activation_is_bounded_and_after_official_origin_check(self):
        script = m.ACCOUNT_AUTH_NODE_SCRIPT
        self.assertIn('c = new Cdp(ws, { commandTimeoutMs: 7000 })', script)
        self.assertIn('await c.send("Page.bringToFront")', script)
        self.assertIn("auth_tab_activate_failed", script)
        self.assertLess(script.index("if (!validStage(targetUrl))"),
                        script.index('await c.send("Page.bringToFront")'))
        self.assertLess(script.index('await c.send("Page.bringToFront")'),
                        script.index('c.send("Runtime.evaluate"'))
        self.assertNotIn('c.send("Page.reload")', script)
        self.assertNotIn('c.send("Page.navigate")', script)

    def test_resend_email_matches_real_openai_login_button_without_consent_controls(self):
        script = m.ACCOUNT_AUTH_NODE_SCRIPT
        marker = "const labels=action==='resend'?/"
        self.assertIn(marker, script)
        expression = script.split(marker, 1)[1].split("/i:", 1)[0]
        js = (
            "const match=new RegExp(" + json.dumps(expression) + ",'i');"
            "const good=['Resend email','Resend e-mail','Resend code','Resend',"
            "'Send a new code','Reenviar código','Reenviar e-mail'];"
            "const bad=['Advanced','Allow access','Grant permissions','Login'];"
            "if(good.some(x=>!match.test(x))||bad.some(x=>match.test(x)))process.exit(3);"
        )
        proc = subprocess.run(["node", "--input-type=module", "-e", js],
                              text=True, capture_output=True, check=False)
        self.assertEqual(0, proc.returncode, proc.stderr)

    def test_auth_open_requires_live_maintenance_lease_and_matching_account(self):
        args = {
            "session": "atendimento",
            "runtime_lease_id": "example-lease", "runtime_fencing_token": 1,
        }
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with self.assertRaisesRegex(ValueError, "account_auth_runtime_lock_required"):
                m.browser_auth_open({"session": "atendimento"})
            with self.assertRaisesRegex(ValueError, "account_auth_session_invalid"):
                m.browser_auth_open({**args, "session": "other"})
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock",
                                   return_value=allowed_lease("dev")):
                with self.assertRaisesRegex(ValueError, "account_auth_maintenance_owner_required"):
                    m.browser_auth_open(args)
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=False):
            with self.assertRaisesRegex(ValueError, "account_auth_lease_mode_required"):
                m.browser_auth_open(args)

    def test_auth_open_never_transmits_secret_or_arbitrary_url(self):
        args = {
            "session": "atendimento",
            "runtime_lease_id": "example-lease", "runtime_fencing_token": 1,
        }
        fake = {"exit_code": 0, "stdout": '{"ok":true}', "stderr": ""}
        with mock.patch.object(m.base, "_durable_handoff_enabled", return_value=True):
            with mock.patch.object(m.base.runtime_lock, "assert_runtime_lock",
                                   return_value=allowed_lease("atendimento")):
                with mock.patch.object(m.base, "run_local_command_with_stdin",
                                       return_value=fake) as run:
                    result = m.browser_auth_open(args)
        self.assertTrue(result["ok"])
        invocation, typed, *_ = run.call_args.args
        self.assertEqual("", typed)
        self.assertEqual(invocation[-1], "atendimento")
        self.assertEqual(invocation[3], m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT)
        self.assertIn("https://chatgpt.com/auth/login", m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT)
        self.assertIn('Target.createTarget', m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT)
        self.assertIn('auth_stage_already_open', m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT)
        self.assertNotIn('Target.closeTarget', m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT)
        self.assertNotIn('Page.navigate', m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT)
        self.assertNotIn('password_value', m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT)
        proc = subprocess.run(["node", "--check", "--input-type=module"],
                              input=m.ACCOUNT_AUTH_OPEN_NODE_SCRIPT,
                              text=True, capture_output=True, check=False)
        self.assertEqual(0, proc.returncode, proc.stderr)

    def test_node_script_parses_without_exposing_values(self):
        proc = subprocess.run(["node","--check","--input-type=module"],input=m.ACCOUNT_AUTH_NODE_SCRIPT,
                              text=True,capture_output=True,check=False)
        self.assertEqual(0,proc.returncode,proc.stderr)
        self.assertIn("Input.insertText",m.ACCOUNT_AUTH_NODE_SCRIPT)
        self.assertIn("auth_stage_not_allowed",m.ACCOUNT_AUTH_NODE_SCRIPT)

if __name__ == "__main__":
    unittest.main()
