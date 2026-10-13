"""Logout safety tests for authenticated corporate ChatGPT browser MCP tools.

No real session is logged out; generated CDP commands are inspected and the
pre-click policy is exercised in a synthetic Node environment.
"""
import base64
import importlib.util
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / "remote-control-mcp/server.py"
BROWSER = ROOT / "remote-control-browser-mcp/server.py"


def load_base():
    spec = importlib.util.spec_from_file_location("svv_logout_guard_base", BASE)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class CorporateLogoutSafetyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.base = load_base()

    def test_logout_paths_rejected_without_navigation(self):
        bad = [
            "https://chatgpt.com/auth/logout",
            "https://chatgpt.com/auth/signout",
            "https://chatgpt.com/api/auth/sign-out",
            "https://auth.openai.com/logout",
            "https://chatgpt.com/%6cogout",
            "https://chatgpt.com/%256cogout",
            "https://chatgpt.com/auth%2flogout",
        ]
        for url in bad:
            with self.subTest(url=url):
                with self.assertRaisesRegex(ValueError, "corporate_logout_guard_denied"):
                    self.base._assert_no_corporate_logout_url(url)
                for fn, args in (
                    (self.base.browser_open_command, (url,)),
                    (self.base.browser_navigate_command, ("AA" * 16, url)),
                    (self.base.browser_focused_navigate_command, (url,)),
                ):
                    with self.assertRaises(ValueError):
                        fn(*args)

    def test_regular_corporate_work_remains_allowed(self):
        for url in [
            "https://chatgpt.com/",
            "https://chatgpt.com/c/12345678-1234-1234-1234-123456789abc",
            "https://chatgpt.com/settings",
            "https://chatgpt.com/help/logout-support",
            "https://auth.openai.com/log-in",
        ]:
            with self.subTest(url=url):
                self.base._assert_no_corporate_logout_url(url)
                self.assertIsInstance(self.base.browser_open_command(url), str)
                self.assertIsInstance(self.base.browser_navigate_command("AA" * 16, url), str)

    def test_click_and_control_commands_enforce_before_click(self):
        def expr(command):
            value = re.search(r"SHOPVIVALIZ_EXPR_B64=([A-Za-z0-9+/=]+)", command)
            self.assertIsNotNone(value)
            return base64.b64decode(value.group(1)).decode()
        commands = [
            self.base.browser_click_command("AA" * 16, "button"),
            self.base.browser_click_control_command("AA" * 16, 0),
        ]
        for command in commands:
            source = expr(command)
            self.assertIn("corporate_logout_guard_denied", source)
            self.assertIn("e.click()", source)
            self.assertLess(source.index("corporate_logout_guard_denied"), source.index("e.click()"))
            self.assertIn("denyCorporateLogout(e)", source)

    def test_submit_and_gui_navigation_have_guard(self):
        source = BASE.read_text(encoding="utf8")
        self.assertIn("if(submitted&&e.form)", source)
        self.assertIn("corporate_logout_guard_denied", self.base.BROWSER_TYPE_NODE_SCRIPT)
        self.assertIn("corporate_logout_guard_denied", self.base.BROWSER_FOCUSED_TYPE_NODE_SCRIPT)
        gui = BROWSER.read_text(encoding="utf8")
        self.assertIn("base._assert_no_corporate_logout_url(url)", gui)
        self.assertIn("base._assert_no_corporate_logout_url(text)", gui)

    @unittest.skipUnless(shutil.which("node"), "Node is needed for synthetic JS policy test")
    def test_click_guard_rejects_signout_but_not_send(self):
        guard = self.base._CORPORATE_LOGOUT_CLICK_JS
        program = """
        const host='chatgpt.com';
        globalThis.location={hostname:host,href:'https://chatgpt.com/c/a'};
        const make=(label,href='')=>({
          innerText:label, textContent:label,
          getAttribute(name){return name==='href'?href:null;},
          closest(sel){return sel==='form'?null:this;}
        });
        const execute=e=> {
          try {
            Function('e', %s + '\\nreturn true;')(e);
            return 'ALLOW';
          } catch(ex) {
            return String(ex.message);
          }
        };
        const results=[
          execute(make('Sign out')),
          execute(make('Sair')),
          execute(make('Log out')),
          execute(make('Send')),
          execute(make('View logout statistics')),
          execute(make('Account','/auth/logout'))
        ];
        console.log(JSON.stringify(results));
        """ % (json.dumps(guard),)
        proc = subprocess.run(["node", "-e", program], text=True, capture_output=True, timeout=9)
        self.assertEqual(proc.returncode, 0, proc.stderr[:1000])
        self.assertEqual(json.loads(proc.stdout), [
            "corporate_logout_guard_denied",
            "corporate_logout_guard_denied",
            "corporate_logout_guard_denied",
            "ALLOW",
            "ALLOW",
            "corporate_logout_guard_denied",
        ])


if __name__ == "__main__":
    unittest.main()
