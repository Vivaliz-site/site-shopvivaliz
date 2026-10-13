"""Behavioral regression for the live RDC guardian cgroup/socket false restart.

Run with: python3 -m unittest tests.test_vm_dc_guardian_cgroup_socket_health -v
Only the isolated provider_socket_connected Bash function is executed: no
systemctl, signals, real sockets, browsers, credentials or production changes.
"""
import shlex
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GUARDIAN = ROOT / "scripts/vm-desktop-commander-guardian.sh"


def socket_probe_function():
    source = GUARDIAN.read_text(encoding="utf-8")
    start = source.index("provider_socket_connected() {")
    end = source.index("\n}\n\nservice_state()", start) + 2
    return source[start:end]


class GuardianCgroupSocketHealthTests(unittest.TestCase):
    def run_fixture(self, *, members="2001\n2002", sockets="", grace=0, ss_fails=False):
        shell = f"""set -Eeuo pipefail
SERVICE_CGROUP='/system.slice/shopvivaliz-desktop-commander.service'
PROVIDER_SOCKET_GRACE_SECONDS={int(grace)}
pgrep() {{ printf '1\\n'; }}
stat() {{ printf '0\\n'; }}
cat() {{ printf '%s\\n' {shlex.quote(members)}; }}
ss() {{ {'return 1' if ss_fails else "printf '%s\\n' " + shlex.quote(sockets)}; }}
{socket_probe_function()}
provider_socket_connected
"""
        return subprocess.run(
            ["bash", "-c", shell], capture_output=True, text=True, timeout=5
        )

    def test_established_sibling_in_service_cgroup_prevents_false_restart(self):
        # Live evidence: guardian sampled one Node PID with zero sockets, while
        # another PID in the same systemd unit held the healthy provider socket.
        result = self.run_fixture(sockets='ESTAB 0 0 127.0.0.1:5555 127.0.0.1:4444 users:(("node",pid=2002,fd=19))')
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_socket_on_foreign_process_never_passes(self):
        result = self.run_fixture(sockets='ESTAB 0 0 127.0.0.1:5555 127.0.0.1:4444 users:(("node",pid=9999,fd=19))')
        self.assertNotEqual(result.returncode, 0)

    def test_close_wait_is_not_an_established_socket(self):
        result = self.run_fixture(sockets='CLOSE-WAIT 0 0 127.0.0.1:5555 127.0.0.1:4444 users:(("node",pid=2002,fd=19))')
        self.assertNotEqual(result.returncode, 0)

    def test_failed_socket_inspection_is_fail_closed(self):
        result = self.run_fixture(ss_fails=True)
        self.assertNotEqual(result.returncode, 0)

    def test_pid_substring_is_not_accepted_as_member(self):
        result = self.run_fixture(members="200", sockets='ESTAB 0 0 127.0.0.1:5555 127.0.0.1:4444 users:(("node",pid=2002,fd=19))')
        self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
