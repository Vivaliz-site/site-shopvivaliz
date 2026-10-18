"""Fail-closed regression checks for the Marina cutover preflight."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "windows" / "marina-precutover-preflight.ps1"
GUIDE = ROOT / "MIGRATION-EXECUTE-NOW.md"


class MarinaPrecutoverSafetyTests(unittest.TestCase):
    def test_gate_requires_external_evidence(self):
        data = SCRIPT.read_text(encoding="utf-8-sig")
        self.assertIn("CHECKPOINT_MODE=READ_ONLY", data)
        self.assertIn("MIGRATION_GATE=NO_CUT", data)
        self.assertRegex(data, r"(?m)^exit 2$")
        self.assertNotIn("APTO FOR CUTOVER", data)

    def test_host_ids_are_not_reversed(self):
        data = SCRIPT.read_text(encoding="utf-8-sig")
        self.assertIn("ORIGIN_PRIVATE_IP=10.0.1.112", data)
        self.assertIn("MARINA_PRIVATE_IP=10.80.1.116", data)
        self.assertIn("BACKEND_PRIVATE_IP=10.0.1.38", data)

    def test_no_remote_mutation_or_insecure_ssh(self):
        data = SCRIPT.read_text(encoding="utf-8-sig").lower()
        for forbidden in (
            "stricthostkeychecking=no", "ssh -i", "sudo ",
            "apt-get ", "docker start", "docker run",
            "systemctl ", "set-content", "invoke-expression",
            "start-process", "cloudflared tunnel", "invoke-restmethod",
            "remove-item", "set-dns",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, data)

    def test_guide_prevents_false_success(self):
        text = GUIDE.read_text(encoding="utf-8-sig")
        self.assertIn("NO_CUT / NAO_APTO", text)
        self.assertIn("marina-precutover-preflight.ps1", text)
        self.assertNotIn("**100% Ready**", text)


if __name__ == "__main__":
    unittest.main()
