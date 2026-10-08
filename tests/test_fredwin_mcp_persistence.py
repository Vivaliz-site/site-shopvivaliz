#!/usr/bin/env python3
"""Regression contracts for the Fred-Win MCP reverse SSH transport."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class FredWinMcpPersistenceContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tunnel = (ROOT / "scripts/ssh-tunnel-service-managed.ps1").read_text(encoding="utf-8-sig")
        cls.bootstrap = (ROOT / "scripts/fredwin-remote-bootstrap.ps1").read_text(encoding="utf-8-sig")

    def test_managed_tunnel_detects_dead_peers_promptly(self):
        for expected in (
            "-R 2222:127.0.0.1:22",
            "-R 5557:127.0.0.1:5557",
            "-o 'BatchMode=yes'",
            "-o 'ExitOnForwardFailure=yes'",
            "-o 'StrictHostKeyChecking=yes'",
            "-o 'ConnectTimeout=8'",
            "-o 'ServerAliveInterval=15'",
            "-o 'ServerAliveCountMax=2'",
            "Start-Sleep -Seconds 5",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, self.tunnel)

    def test_disconnection_reason_is_recorded_without_raw_ssh_output(self):
        self.assertIn("SSH exit_code=", self.tunnel)
        self.assertIn("remote_forward_bind_failed", self.tunnel)
        self.assertIn("transport_timeout", self.tunnel)
        self.assertNotIn("Write-TunnelLog $_", self.tunnel)
        self.assertNotIn("Write-TunnelLog $line", self.tunnel)

    def test_watchdog_probes_real_remote_ssh_before_accepting_process(self):
        for expected in (
            "function Test-RemoteTunnelProtocol",
            "ssh-keyscan",
            "-p 2222 127.0.0.1",
            "Test-RemoteTunnelProtocol",
            "Stop-ManagedTunnel",
            "Get-ManagedSsh",
            "if ($ssh.Count -eq 1)",
            "Start-Sleep -Seconds 2",
            "Get-Service -Name sshd",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, self.bootstrap)

    def test_watchdog_runs_every_two_minutes_and_keeps_noninteractive_startup(self):
        self.assertIn("New-TimeSpan -Minutes 2", self.bootstrap)
        self.assertIn("New-ScheduledTaskTrigger -AtStartup", self.bootstrap)
        self.assertIn("-MultipleInstances IgnoreNew", self.bootstrap)
        self.assertIn("-LogonType S4U", self.bootstrap)


if __name__ == "__main__":
    unittest.main()
