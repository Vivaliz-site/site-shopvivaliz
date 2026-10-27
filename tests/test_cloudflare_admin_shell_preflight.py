"""Tests for the read-only Cloudflare admin tunnel host preflight."""
import os
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "ops" / "cloudflare-admin-preflight.sh"


class TestCloudflareAdminPreflight(unittest.TestCase):
    def run_for(self, hostname):
        """Isolate host-role fixture without weakening the production hostname lookup."""
        self.assertRegex(hostname, r"^[A-Za-z0-9-]+$")
        source = SCRIPT.read_text(encoding="utf-8")
        host_probe = 'host="$(/usr/bin/hostname -s)"'
        self.assertIn(host_probe, source)
        source = source.replace(host_probe, 'host="' + hostname + '"', 1)
        with tempfile.TemporaryDirectory() as tmp:
            fixture = Path(tmp) / "preflight-fixture.sh"
            fixture.write_text(source, encoding="utf-8")
            return subprocess.run(
                ["/bin/bash", str(fixture)],
                env={**os.environ, "PATH": tmp + ":" + os.environ.get("PATH", "")},
                text=True, capture_output=True, timeout=5
            )

    def test_unknown_host_denied_without_credentials(self):
        p = self.run_for("unrelated-host")
        self.assertEqual(p.returncode, 3)
        self.assertIn("PREFLIGHT_STATUS=UNKNOWN_HOST", p.stdout)
        self.assertNotIn("TUNNEL_TOKEN_METADATA=", p.stdout)

    def test_backend_is_not_claimed_authenticated(self):
        p = self.run_for("always-free-arm-1787907847-26")
        self.assertEqual(p.returncode, 0)
        self.assertIn("ROLE=backend", p.stdout)
        self.assertIn("CLOUDFLARE_ACCOUNT_AUTH=NOT_TESTED", p.stdout)
        self.assertIn("ACCESS_POLICY=NOT_TESTED", p.stdout)
        self.assertIn("TUNNEL_CONNECTIVITY=NOT_TESTED", p.stdout)

    def test_marina_cannot_claim_sudo_approval(self):
        p = self.run_for("marina-a1-4c24g")
        self.assertEqual(p.returncode, 0)
        self.assertIn("ROLE=marina", p.stdout)
        self.assertIn("SUDO_PERMISSION=NOT_TESTED", p.stdout)

    def test_inherited_path_cannot_spoof_host_identity(self):
        """The production script may not run a hostile hostname/systemctl/stat."""
        with tempfile.TemporaryDirectory() as tmp:
            marker = Path(tmp) / "hostile-command-executed"
            for executable in ("hostname", "systemctl", "stat"):
                fake = Path(tmp) / executable
                fake.write_text(
                    '#!/bin/sh\n: > "' + str(marker) +
                    '"\nprintf "marina-a1-4c24g\\n"\n',
                    encoding="utf-8"
                )
                fake.chmod(0o755)
            env = {**os.environ, "PATH": tmp + ":" + os.environ.get("PATH", "")}
            result = subprocess.run(["/bin/bash", str(SCRIPT)], env=env, text=True,
                                    capture_output=True, timeout=5)
            self.assertFalse(marker.exists(), "untrusted caller command executed")
            self.assertIn(result.returncode, (0, 3), result.stderr)
            if result.returncode == 0:
                self.assertIn("SUDO_PERMISSION=NOT_TESTED", result.stdout)
            else:
                self.assertIn("PREFLIGHT_STATUS=UNKNOWN_HOST", result.stdout)

    def test_pinned_binary_and_root_token_metadata(self):
        """Readiness cannot rely on an arbitrary PATH executable or non-root token."""
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared", source)
        self.assertNotIn("command -v cloudflared", source)
        self.assertIn("present_root_owned_pinned", source)
        self.assertIn("600:0|400:0", source)
        self.assertIn("private_root_owned_regular_file", source)

    def test_pinned_binary_and_token_parent_directories_fail_closed(self):
        """Exercise the actual shell guard, not just string assertions."""
        source = SCRIPT.read_text(encoding="utf-8")
        match = re.search(r"(?ms)^safe_root_directory\(\) \{\n.*?^\}", source)
        self.assertIsNotNone(match)
        self.assertIn('[[ "$binary_parents_safe" == true', source)
        self.assertIn('if [[ "$token_parents_safe" != true ]]; then', source)
        guard = match.group(0)
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp) / "safe-leaf"
            directory.mkdir()
            shell = 'stat() { printf "%s\\n" "$FAKE_METADATA"; }\n' + guard + '\nsafe_root_directory "$1"\n'
            for metadata, allowed in (("0:755", True), ("0:700", True),
                                      ("0:777", False), ("0:775", False),
                                      ("1001:755", False), ("invalid", False)):
                with self.subTest(metadata=metadata):
                    env = {**os.environ, "FAKE_METADATA": metadata}
                    proc = subprocess.run(["bash", "-c", shell, "bash", str(directory)],
                                          text=True, capture_output=True, env=env,
                                          timeout=5)
                    self.assertEqual(proc.returncode, 0 if allowed else 1, proc.stderr)
            linked = Path(tmp) / "linked-leaf"
            linked.symlink_to(directory, target_is_directory=True)
            proc = subprocess.run(["bash", "-c", shell, "bash", str(linked)],
                                  text=True, capture_output=True,
                                  env={**os.environ, "FAKE_METADATA": "0:755"},
                                  timeout=5)
            self.assertNotEqual(proc.returncode, 0)

    def test_token_ancestors_include_etc(self):
        """Shell preflight and privileged staging must require the same chain."""
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn('if safe_root_directory /etc &&', source)
        self.assertIn('safe_root_directory /etc/shopvivaliz &&', source)
        self.assertIn('safe_root_directory /etc/shopvivaliz/cloudflare-admin; then',
                      source)

    def test_origin_does_not_boot_or_claim_apt(self):
        p = self.run_for("shopvivaliz-free-a1")
        self.assertEqual(p.returncode, 0)
        self.assertIn("ROLE=origin", p.stdout)
        self.assertNotIn("APTO", p.stdout)


if __name__ == "__main__":
    unittest.main()
