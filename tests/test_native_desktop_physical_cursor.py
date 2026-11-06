"""Windows native desktop click must use physical screenshot pixel coordinates."""
import unittest
from pathlib import Path

SCRIPT = (Path(__file__).resolve().parents[1] / "scripts" / "shopvivaliz-native-desktop-bridge.ps1").read_text(encoding="utf-8")


class NativeDesktopPhysicalCursorTests(unittest.TestCase):
    def test_physical_cursor_api_is_used_for_clicks(self):
        self.assertIn("SetPhysicalCursorPos(int X, int Y)", SCRIPT)
        self.assertIn("GetPhysicalCursorPos(out PhysicalPoint point)", SCRIPT)
        self.assertIn("::SetPhysicalCursorPos($absoluteX, $absoluteY)", SCRIPT)
        self.assertNotIn("::SetCursorPos(", SCRIPT)

    def test_click_checks_cursor_matches_requested_physical_pixel(self):
        self.assertIn("::IsPhysicalCursorAt($absoluteX, $absoluteY)", SCRIPT)
        self.assertIn("desktop_cursor_position_mismatch", SCRIPT)
        self.assertLess(SCRIPT.index("::IsPhysicalCursorAt("), SCRIPT.index("mouse_event([uint32]$flags[0]"))

    def test_dpi_fallback_handles_false_without_an_exception(self):
        self.assertIn("$dpiAware = [ShopVivalizDesktopInput]::SetProcessDpiAwarenessContext", SCRIPT)
        self.assertIn("if (-not $dpiAware)", SCRIPT)
        self.assertIn("SetProcessDPIAware()", SCRIPT)


if __name__ == "__main__":
    unittest.main()
