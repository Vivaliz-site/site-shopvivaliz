"""Safe regression tests for the 24h Claude Code/Codex CLI session reaper."""
import importlib.util
import os
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PYTHON_FILE = ROOT / "scripts/maintenance/agent-session-ttl.py"
spec = importlib.util.spec_from_file_location("shopvivaliz_cli_session_ttl", PYTHON_FILE)
ttl = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = ttl
spec.loader.exec_module(ttl)


class SessionTtlTests(unittest.TestCase):
    def p(self, pid, ppid=1, age=90000, name="claude", uid=None, cgroup="0::/user.slice/session-1.scope", args=None, exe=None):
        uid = getattr(os, "geteuid", lambda: 1000)() if uid is None else uid
        args = tuple(args or ["/home/ubuntu/.local/bin/" + name])
        exe = exe or "/home/ubuntu/.local/bin/" + name
        return ttl.Process(pid, ppid, uid, age, args, exe, cgroup, pid * 10)

    def test_close_stale_cli_but_not_recent(self):
        items = [self.p(11), self.p(12, age=400, name="codex"), self.p(13, age=100000, name="codex")]
        self.assertEqual([p.pid for p, _ in ttl.targets(items, 86400)], [11, 13])

    def test_reject_root_other_user_and_background_service(self):
        user = getattr(os, "geteuid", lambda: 1000)()
        if user == 0:
            self.skipTest("Run tests as interactive non-root user")
        items = [self.p(1, uid=0), self.p(2, uid=user + 99),
                 self.p(3, cgroup="0::/system.slice/claude.service")]
        self.assertEqual(ttl.targets(items, 86400), [])

    def test_excludes_remote_control_gui_and_service(self):
        items = [
            self.p(10, args=["/home/ubuntu/.local/bin/claude", "--remote-control"]),
            self.p(11, exe="/windowsapps/Claude/claude.exe"),
            self.p(12, cgroup="0::/user.slice/app-claude.service"),
        ]
        self.assertEqual(ttl.targets(items, 86400), [])

    def test_preserve_parent_of_younger_agent(self):
        items = [self.p(20, age=172800), self.p(21, ppid=20, age=1800),
                 self.p(22, ppid=20, age=96000)]
        self.assertEqual([p.pid for p, _ in ttl.targets(items, 86400)], [22])

    def test_package_node_agent(self):
        item = self.p(40, name="node", args=["/usr/bin/node", "/home/ubuntu/node_modules/@anthropic-ai/claude-code/cli.js"])
        self.assertEqual(ttl.kind(item), "claude")

    def test_windows_contract_and_timer(self):
        windows = (ROOT / "scripts/maintenance/agent-session-ttl.ps1").read_text()
        installer = (ROOT / "scripts/maintenance/install-agent-session-ttl.ps1").read_text()
        service = (ROOT / "deploy/systemd/shopvivaliz-agent-session-ttl.service").read_text()
        timer = (ROOT / "deploy/systemd/shopvivaliz-agent-session-ttl.timer").read_text()
        self.assertIn("windowsapps", windows)
        self.assertIn("chatgpt.exe", windows)
        self.assertIn("[int]$MaxAgeHours=24", windows)
        self.assertIn("S4U", installer)
        self.assertIn("TTL 24h", installer)
        self.assertIn("User=ubuntu", service)
        self.assertIn("--apply", service)
        self.assertIn("OnCalendar=hourly", timer)


if __name__ == "__main__":
    unittest.main()
