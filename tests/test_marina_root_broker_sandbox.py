"""Exercise the broker verifier subprocess under the real systemd sandbox."""
import http.server
import os
import shutil
import subprocess
import sys
import threading
import unittest
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNIT = ROOT / "marina-direct-mcp/systemd/shopvivaliz-marina-root-broker.service"


@unittest.skipUnless(os.geteuid() == 0 and Path("/run/systemd/system").is_dir(),
                     "requires root and a running systemd manager; enforced in broker CI")
class BrokerSandboxTests(unittest.TestCase):
    def test_verifier_child_can_reach_http_fixture_under_service_sandbox(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200)
                self.end_headers()
                self.wfile.write(b"fixture-ok")
            def log_message(self, *_):
                pass
        fixture = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=fixture.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(lambda: (fixture.shutdown(), fixture.server_close(), thread.join(3)))
        properties = {}
        for line in UNIT.read_text().splitlines():
            if "=" in line and not line.startswith("#"):
                key, value = line.split("=", 1)
                properties[key] = value
        sandbox_keys = (
            "NoNewPrivileges", "PrivateDevices", "PrivateTmp", "ProtectSystem",
            "ProtectHome", "ProtectKernelModules", "ProtectKernelTunables",
            "ProtectControlGroups", "LockPersonality", "RestrictAddressFamilies",
            "SystemCallArchitectures", "MemoryMax", "TasksMax",
        )
        command = [shutil.which("systemd-run") or "/usr/bin/systemd-run",
                   "--quiet", "--wait", "--collect", "--pipe",
                   "--unit=marina-broker-sandbox-test-" + uuid.uuid4().hex]
        for key in sandbox_keys:
            self.assertIn(key, properties)
            command += ["--property", key + "=" + properties[key]]
        url = "http://127.0.0.1:" + str(fixture.server_port) + "/"
        child = ("from urllib.request import urlopen; "
                 "assert urlopen(" + repr(url) + ", timeout=3).read() == b'fixture-ok'")
        parent = "import subprocess,sys; subprocess.run([sys.executable,'-B','-c'," + repr(child) + "],check=True)"
        command += ["/usr/bin/python3", "-B", "-c", parent]
        result = subprocess.run(command, text=True, capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
