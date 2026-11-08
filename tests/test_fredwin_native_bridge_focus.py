"""Regression contract for the Windows MCP graphical focus bridge.

A remote click can activate Chrome, but starting a separate interactive
PowerShell worker for desktop_type may steal focus. The worker must restore
focus to the window beneath the last clicked cursor location before pasting.
"""
from pathlib import Path
import unittest


SOURCE = Path(__file__).resolve().parents[1] / "scripts" / "shopvivaliz-native-desktop-bridge.ps1"


class FredWinNativeBridgeFocusTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.script = SOURCE.read_text(encoding="utf-8")
        cls.typing = cls.script.split("        'type' {", 1)[1].split("        default {", 1)[0]
        cls.clicking = cls.script.split("        'click' {", 1)[1].split("        'type' {", 1)[0]

    def test_desktop_type_restores_focus_before_paste(self):
        self.assertIn("RootAtCursor()", self.typing)
        self.assertIn("SetForegroundWindow($target)", self.typing)
        self.assertIn("GetForegroundWindow() -ne $target", self.typing)
        self.assertIn("desktop_focus_restore_failed", self.typing)
        self.assertLess(
            self.typing.index("SetForegroundWindow($target)"),
            self.typing.index("[System.Windows.Forms.Clipboard]::SetText($text)"),
        )

    def test_click_uses_same_physical_cursor_as_restore(self):
        self.assertIn("SetPhysicalCursorPos", self.clicking)
        self.assertIn("IsPhysicalCursorAt", self.clicking)
        self.assertIn("GetPhysicalCursorPos(out p)", self.script)
        self.assertIn("RootAt(p.X,p.Y)", self.script)

    def test_failed_focus_does_not_report_success(self):
        self.assertIn("throw 'desktop_focus_target_unavailable'", self.typing)
        self.assertIn("throw 'desktop_focus_restore_failed'", self.typing)
        self.assertIn("$base.focus_restored = $true", self.typing)
        self.assertLess(
            self.typing.index("desktop_focus_restore_failed"),
            self.typing.index("$base.typed_characters = $text.Length"),
        )

    def test_sensitive_text_is_not_logged_or_left_in_clipboard(self):
        self.assertIn("[System.Windows.Forms.SendKeys]::SendWait('^v')", self.typing)
        self.assertIn("[System.Windows.Forms.Clipboard]::Clear()", self.typing)
        self.assertIn("$base.typed_characters = $text.Length", self.typing)
        self.assertNotIn("$base.text = $text", self.typing)
        self.assertIn("$raw = [Console]::In.ReadToEnd()", self.script)

    def test_worker_remains_in_authorized_interactive_session(self):
        self.assertIn("-LogonType Interactive", self.script)
        self.assertIn("Set-PrivateFileAcl", self.script)
        self.assertIn("'-WindowStyle', 'Hidden'", self.script)
        self.assertIn("S-1-5-18", self.script)
        self.assertIn("Unregister-ScheduledTask", self.script)


if __name__ == "__main__":
    unittest.main()
