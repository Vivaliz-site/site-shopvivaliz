#!/usr/bin/env python3
"""Static no-deploy regression checks for operator-only Marina units."""
from __future__ import annotations

import configparser
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIR = ROOT / "marina-direct-mcp" / "systemd"
UNITS = [
    "shopvivaliz-marina-mcp.service",
    "shopvivaliz-marina-mcp-tunnel.service",
]
ACCOUNT = "shopvivaliz-marina-mcp"


def unit(name: str):
    if name not in UNITS:
        raise ValueError("unit_not_allowlisted")
    text = (DIR / name).read_text(encoding="utf-8")
    cfg = configparser.RawConfigParser(interpolation=None, strict=False)
    cfg.optionxform = str
    cfg.read_string(text)
    return text, cfg


class OperatorGatedUnitsTests(unittest.TestCase):
    def test_same_dedicated_unprivileged_identity(self):
        for name in UNITS:
            _, conf = unit(name)
            section = conf["Service"]
            self.assertEqual(section["User"], ACCOUNT)
            self.assertEqual(section["Group"], ACCOUNT)
            self.assertEqual(section["UMask"], "0077")
            # Owner decision: no yes-valued sandbox switches on the Marina units.
            self.assertEqual(section["PrivateTmp"], "no")
            self.assertEqual(section["Restart"], "on-failure")

    def test_no_yes_valued_sandbox_switches_on_marina_units(self):
        # Owner decision: the tunnel and the root broker carry no "=yes" hardening switches.
        for name in ("shopvivaliz-marina-mcp.service", "shopvivaliz-marina-mcp-tunnel.service",
                     "shopvivaliz-marina-root-broker.service"):
            text = (DIR / name).read_text(encoding="utf-8")
            yes = [line for line in text.splitlines() if line.endswith("=yes") and not line.startswith("#")]
            self.assertEqual(yes, [], name)
        _, conf = unit("shopvivaliz-marina-mcp-tunnel.service")
        section = conf["Service"]
        for key in ("ProtectHome", "PrivateDevices", "RestrictSUIDSGID"):
            self.assertEqual(section[key], "no", key)
        # NoNewPrivileges is not set at all on any Marina unit.
        for name in ("shopvivaliz-marina-mcp.service", "shopvivaliz-marina-mcp-tunnel.service",
                     "shopvivaliz-marina-root-broker.service"):
            self.assertNotIn("NoNewPrivileges", (DIR / name).read_text(encoding="utf-8"), name)

    def test_mcp_unit_allows_sudo_for_the_admin_shell_only_under_owner_grant(self):
        # Owner grant: the HTTP process stays unprivileged, but sudo must be able to
        # gain privileges for marina_admin_shell(as_root=true).
        _, conf = unit("shopvivaliz-marina-mcp.service")
        self.assertNotIn("NoNewPrivileges", conf["Service"])
        self.assertNotEqual(conf["Service"].get("User"), "root")
        sudoers = (ROOT / "marina-direct-mcp" / "sudoers" / "shopvivaliz-marina-mcp").read_text(encoding="utf-8")
        rules = [line for line in sudoers.splitlines() if line.strip() and not line.startswith("#")]
        self.assertEqual(rules, [ACCOUNT + " ALL=(root) NOPASSWD: ALL",
                                 ACCOUNT + " ALL=(ubuntu) NOPASSWD: ALL"])

    def test_no_privileged_shell_or_controller_tunnel(self):
        for name in UNITS:
            text, conf = unit(name)
            execution = conf["Service"]["ExecStart"]
            for forbidden in ("/bin/sh ", "/bin/bash ", "sudo ", "docker ",
                              "ssh ", "always-free-arm-", "137.131.", "0.0.0.0",
                              "CONTROL_PLANE_API_KEY=", "sk-proj-"):
                self.assertNotIn(forbidden, execution)
            self.assertIn("STAGING TEMPLATE ONLY", text)
            self.assertNotIn("/home/ubuntu/shopvivaliz-deploy", execution)

    def test_server_is_loopback_only_and_distinct(self):
        _, cfg = unit("shopvivaliz-marina-mcp.service")
        command = cfg["Service"]["ExecStart"]
        self.assertIn("/opt/shopvivaliz-marina-mcp/current/server.py", command)
        self.assertIn("--port 5580", command)
        self.assertIn("--token-file /var/lib/shopvivaliz-marina-mcp/", command)
        self.assertNotIn("tunnel-client", command)

    def test_tunnel_waits_for_local_mcp(self):
        _, cfg = unit("shopvivaliz-marina-mcp-tunnel.service")
        self.assertIn("shopvivaliz-marina-mcp.service", cfg["Unit"]["Requires"])
        self.assertIn("shopvivaliz-marina-mcp.service", cfg["Unit"]["After"])
        self.assertNotIn("ConditionPathIsExecutable", (DIR / "shopvivaliz-marina-mcp-tunnel.service").read_text(encoding="utf-8"))
        self.assertIn("ConditionPathExists=/opt/shopvivaliz-marina-mcp/bin/tunnel-client",
                      (DIR / "shopvivaliz-marina-mcp-tunnel.service").read_text(encoding="utf-8"))
        self.assertEqual(cfg["Service"]["ReadWritePaths"],
                         "/var/lib/shopvivaliz-marina-mcp")
        start = cfg["Service"]["ExecStart"]
        self.assertIn("/opt/shopvivaliz-marina-mcp/bin/tunnel-client run --config", start)
        self.assertIn("/var/lib/shopvivaliz-marina-mcp/.config/", start)

    def test_staged_paths_do_not_trigger_legacy_backend_bootstrap(self):
        # Those exact source paths are intentionally NOT in deploy/systemd.
        for name in UNITS:
            self.assertTrue((DIR / name).is_file())
            self.assertFalse((ROOT / "deploy" / "systemd" / name).exists())


if __name__ == "__main__":
    unittest.main()
