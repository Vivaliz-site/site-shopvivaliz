"""Prevent the temporary scheduled-task console from stealing GUI input focus.

Static contract only; real Windows UI validation is required before a rollout
can be marked successful. No secret values are read by this suite.
"""
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "shopvivaliz-native-desktop-bridge.ps1"


class NativeDesktopBridgeFocusTests(unittest.TestCase):
    def test_interactive_worker_does_not_steal_foreground(self):
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("'-WindowStyle', 'Hidden'", source)
        self.assertIn("'System.Windows.Forms'", source)
        self.assertIn("New-ScheduledTaskPrincipal -UserId $interactiveUser -LogonType Interactive", source)
        self.assertIn("[System.Windows.Forms.SendKeys]::SendWait('^v')", source)

    def test_secret_input_remains_clipboard_only_and_cleared(self):
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("[System.Windows.Forms.Clipboard]::SetText($text)", source)
        self.assertIn("[System.Windows.Forms.Clipboard]::Clear()", source)
        self.assertIn("Set-PrivateFileAcl -Path $requestFile", source)


if __name__ == "__main__":
    unittest.main()
