"""Fail-closed aux Dev guardian probes distinguish HTTP transport from session proof."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
GUARDIAN = ROOT / "scripts/chatgpt-continuity/chatgpt-browser-guardian.sh"


class DevAuxGuardianClassificationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="guardian-v5-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.tasks = self.root / "tasks"
        self.tasks.mkdir()
        self.health = self.root / "health.json"
        self.system_log = self.root / "system.log"
        self.env = dict(os.environ)
        self.env["PATH"] = str(self.bin) + os.pathsep + self.env.get("PATH", "")
        self.env["SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP"] = "9557"
        self.env["CHATGPT_BROWSER_HEALTH_FILE"] = str(self.health)
        self.env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = str(self.tasks)
        for key in ("CHATGPT_BROWSER_CDP_URL", "CHATGPT_BROWSER_CDP_BASE",
                    "CHATGPT_BROWSER_PROBE_CACHE_HELPER"):
            self.env.pop(key, None)
        self.fake("curl", """printf '%s' '{"webSocketDebuggerUrl":"ws://127.0.0.1/test"}'""")
        self.fake("node", "exit 1")
        self.fake("systemctl", 'printf forbidden >> "' + str(self.system_log) + '"')

    def fake(self, name: str, script: str):
        p = self.bin / name
        p.write_text("#!/usr/bin/env bash\nset -eu\n" + script + "\n", encoding="utf-8")
        p.chmod(0o755)

    def run_guardian(self):
        return subprocess.run(["bash", str(GUARDIAN)],
                              env=self.env, capture_output=True,
                              text=True, timeout=18, check=False)

    def test_cdp_http200_but_renderer_unverified_is_not_transport_unreachable(self):
        # 9557 HTTP transport can be healthy while JS/identity is unobservable.
        # The guardian must never record UNREACHABLE (nor AUTHENTICATED).
        response = self.run_guardian()
        self.assertNotEqual(response.returncode, 0)
        self.assertIn("DEV_AUX_CDP_RESPONSIVE_UNVERIFIED", response.stdout)
        self.assertIn("CHATGPT_BROWSER_SESSION=UNKNOWN", response.stdout)
        self.assertNotIn("CHATGPT_BROWSER_SESSION=AUTHENTICATED", response.stdout)
        health = json.loads(self.health.read_text(encoding="utf-8"))
        self.assertEqual(health["session_state"], "UNKNOWN")
        self.assertFalse(health["authenticated"])
        self.assertFalse(self.system_log.exists(), "readonly aux must not restart anything")

    def test_transport_down_keeps_unreachable_and_fails_closed(self):
        self.fake("curl", "exit 7")
        response = self.run_guardian()
        self.assertNotEqual(response.returncode, 0)
        self.assertIn("DEV_AUX_UNAVAILABLE", response.stdout)
        self.assertIn("CHATGPT_BROWSER_SESSION=UNREACHABLE", response.stdout)
        self.assertFalse(self.system_log.exists())


if __name__ == "__main__":
    unittest.main()
