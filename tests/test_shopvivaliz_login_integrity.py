#!/usr/bin/env python3
"""Static regression tests for the read-only corporate session guard."""
import ast
import re
import shlex
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MONITOR = ROOT / "scripts/chatgpt-continuity/shopvivaliz-login-integrity.py"
INSTALLER = ROOT / "scripts/chatgpt-continuity/install-login-integrity.sh"
SERVICE = ROOT / "ops/systemd/shopvivaliz-login-integrity.service"
TIMER = ROOT / "ops/systemd/shopvivaliz-login-integrity.timer"

class LoginIntegrityPolicyTests(unittest.TestCase):
    def test_monitor_syntax_and_all_three_profiles(self):
        body = MONITOR.read_text(encoding="utf8")
        ast.parse(body)
        for item in ("dev_9559", "atendimento_9556", "dev_aux_9557"):
            self.assertIn(item, body)
        self.assertTrue((ROOT / "remote-control-browser-mcp/server.py").is_file())

    def test_readonly_no_browser_mutation(self):
        body = MONITOR.read_text(encoding="utf8")
        self.assertIn('browser_auth_status({"session":session})', body)
        self.assertIn('"http://127.0.0.1:5583/health"', body)
        for forbidden in ("browser_auth_action(", "browser_profile_home_open(", "systemctl restart", "systemctl stop", "rm -rf", "Cookie:", "Authorization:"):
            self.assertNotIn(forbidden, body)

    def test_watch_state_is_metadata_only_and_private(self):
        body = MONITOR.read_text(encoding="utf8")
        self.assertIn('os.fchmod(fd,0o600)', body)
        self.assertIn('streak==1 else "DEGRADED"', body)
        self.assertIn('"readonly":True', body)
        self.assertIn('profile_intact', body)
        for sensitive in ("session_token", "access_token", "password_value", "cookie_value", "totp_seed"):
            self.assertNotIn(sensitive, body)

    def test_timer_cadence_and_service_hardening(self):
        timer = TIMER.read_text(encoding="utf8")
        service = SERVICE.read_text(encoding="utf8")
        self.assertIn("OnBootSec=2min", timer)
        self.assertIn("OnCalendar=*:0/5", timer)
        self.assertIn("Persistent=true", timer)
        self.assertIn("NoNewPrivileges=true", service)
        self.assertIn("ProtectHome=read-only", service)
        self.assertIn("ProtectSystem=strict", service)
        self.assertIn("ReadWritePaths=/var/lib/shopvivaliz-login-integrity", service)

    def test_profile_preservation_guard_quotes_local_state_as_one_argument(self):
        # The filename contains a space. Without quotes, systemd invokes
        # /usr/bin/test with too many arguments (exit 2), causing a restart
        # loop in all corporate Chromium units even if the profiles are intact.
        lines = [
            line.strip() for line in INSTALLER.read_text(encoding="utf8").splitlines()
            if line.strip().startswith("printf '[Service]")
            and "ExecStartPre=/usr/bin/test -s" in line
        ]
        self.assertEqual(len(lines), 1)
        match = re.fullmatch(r"""printf '([^']+)' "\$profile" > "\$dropin" """.strip(), lines[0])
        self.assertIsNotNone(match)
        template = match.group(1)
        for profile in (
            "shopvivaliz-dev-chromium",
            "shopvivaliz-atendimento-chromium",
            "shopvivaliz-atendimento-mcp-chromium",
        ):
            rendered = template.replace("%s", profile).replace("\\n", "\n")
            command = shlex.split(rendered.splitlines()[1])
            self.assertEqual(
                command,
                [
                    "ExecStartPre=/usr/bin/test",
                    "-s",
                    f"/home/fredrdp/.config/{profile}/Local State",
                ],
            )

    def test_installer_never_restarts_or_rebinds_browser(self):
        body = INSTALLER.read_text(encoding="utf8")
        self.assertIn("99-profile-preservation.conf", body)
        self.assertIn("ExecStartPre=/usr/bin/test -s", body)
        self.assertIn("dev_before=", body)
        self.assertIn("aux_before=", body)
        for forbidden in ("systemctl restart", "systemctl stop", "kill -", "--user-data-dir=", "cp -a /home/fredrdp"):
            self.assertNotIn(forbidden, body)

if __name__ == "__main__":
    unittest.main()
