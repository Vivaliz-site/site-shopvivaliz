from __future__ import annotations
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
WATCH=ROOT/"scripts/windows/checkpoint-free-watch.ps1"

class WindowsFreeCheckpointWatchTests(unittest.TestCase):
    def test_no_paid_provider_calls(self):
        script=WATCH.read_text(encoding="utf-8")
        self.assertIn("[Parameter(Mandatory=$true)][string]$JobRoot",script)
        for terminal in ("waiting_external","human_action_required","blocked_external","completed","cancelled"):
            self.assertIn("'"+terminal+"'",script)
        for actionable in ("ready","running","pending"):
            self.assertIn("'"+actionable+"'",script)
        for exit_code in ("exit 0","exit 75","exit 78"):
            self.assertIn(exit_code,script)
        for forbidden in ("codex.exe","codex exec","claude.exe","gemini.exe","Invoke-RestMethod","Invoke-WebRequest","Start-Process","& $"):
            self.assertNotIn(forbidden,script)
    def test_state_read_only_with_safe_status(self):
        s=WATCH.read_text(encoding="utf-8")
        self.assertIn("Get-Content -LiteralPath $state -Raw",s)
        self.assertIn("ConvertFrom-Json",s)
        self.assertIn("inference=false",s)
        for forbidden in ("Set-Content","Add-Content","ConvertTo-Json","Invoke-Expression"):
            self.assertNotIn(forbidden,s)
    def test_unknown_status_is_fail_closed(self):
        s=WATCH.read_text(encoding="utf-8")
        self.assertIn("reason=unrecognized_status",s)
        self.assertIn("reason=state_unavailable",s)
        self.assertIn("reason=missing_status",s)
        self.assertIn("exit 78",s)
