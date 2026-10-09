"""Contract coverage for the first three named desktop/browser tools."""
from pathlib import Path
import ast
import unittest

SOURCE = Path(__file__).resolve().parents[1] / "remote-control-browser-mcp" / "server.py"

class FirstDesktopBrowserTools(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SOURCE.read_text(encoding="utf-8")
        cls.tree = ast.parse(cls.source)
        cls.functions = {node.name for node in cls.tree.body if isinstance(node, ast.FunctionDef)}

    def test_named_operations_exported(self):
        for name in ("desktop.session.attach", "browser.dom.inspect", "browser.click"):
            self.assertIn(f'"name": "{name}"', self.source)
            self.assertIn(f'if name == "{name}":', self.source)

    def test_handlers_present(self):
        for name in ("desktop_session_attach", "browser_dom_inspect", "browser_click_index"):
            self.assertIn(name, self.functions)

    def test_reuse_of_authorized_browser_controls(self):
        self.assertIn('BASE_EXECUTE_TOOL("browser_controls"', self.source)
        self.assertIn('BASE_EXECUTE_TOOL("browser_click_control"', self.source)
        self.assertIn('base._assert_runtime_mutation("browser_click_control"', self.source)

    def test_click_index_bound(self):
        self.assertIn('not 0 <= index < 120', self.source)
        self.assertIn('isinstance(index, bool)', self.source)

    def test_desktop_host_allowlist(self):
        self.assertIn('base.validate_desktop_host(host)', self.source)

if __name__ == "__main__":
    unittest.main()
