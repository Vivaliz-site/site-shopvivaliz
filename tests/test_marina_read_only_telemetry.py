"""Fail-closed unit contracts for independent Marina nonprivileged diagnostics."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "read_only_telemetry.py"
spec = importlib.util.spec_from_file_location("marina_read_only_telemetry", SOURCE)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class PublicTelemetryTests(unittest.TestCase):
    def test_fixed_kernel_sources_only(self):
        for source in ("", "../etc/shadow", "/etc/passwd", None, True, 3, "env"):
            with self.subTest(source=source), self.assertRaises(ValueError):
                m.public_telemetry_read(source)
        for sources in ([], ["uptime"] * 2, ["uptime", "loadavg", "meminfo", "extra"],
                        "uptime", ["uptime", "/etc/shadow"], [True]):
            with self.subTest(sources=sources), self.assertRaises(ValueError):
                m.public_telemetry_batch(sources)

    def test_no_follow_symlink_and_no_unbounded_file_read(self):
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "source"
            source.write_text("safe\n")
            source.chmod(0o600)
            link = Path(tmp) / "link"
            link.symlink_to(source)
            with patch.dict(m.PUBLIC_KERNEL_FILES, {"uptime": str(link)}):
                with self.assertRaises(RuntimeError):
                    m.public_telemetry_read("uptime")
            with patch.dict(m.PUBLIC_KERNEL_FILES, {"uptime": str(source)}):
                self.assertEqual(m.public_telemetry_read("uptime")["data"], "safe\n")
                source.write_bytes(b"x" * (m.MAX_TELEMETRY_BYTES + 1))
                with self.assertRaises(RuntimeError):
                    m.public_telemetry_read("uptime")

    def test_process_listing_never_returns_shell_args_or_owner(self):
        sample = subprocess.CompletedProcess(
            args=["ps"], returncode=0,
            stdout=" 1 systemd\n 2 python3\n 3 unsafe arg --token=secret\n 4 [kworker/0:1]\n",
            stderr="",
        )
        with patch.object(m.subprocess, "run", return_value=sample) as run:
            result = m.public_processes_list()
            self.assertEqual(result["processes"], [
                {"pid": 1, "process": "systemd"},
                {"pid": 2, "process": "python3"},
            ])
            args, kwargs = run.call_args
            self.assertEqual(args[0], ["/usr/bin/ps", "-eo", "pid=,comm=", "--sort=pid"])
            self.assertLessEqual(kwargs["timeout"], 3)
            self.assertNotIn("--token=secret", str(result))
            self.assertNotIn("users", str(result))

    def test_process_listing_handles_timeout_and_oversize(self):
        with patch.object(m.subprocess, "run", side_effect=subprocess.TimeoutExpired("ps", 3)):
            with self.assertRaises(RuntimeError):
                m.public_processes_list()
        with patch.object(m.subprocess, "run", return_value=subprocess.CompletedProcess(
                args=["ps"], returncode=0, stdout="x" * (m.MAX_PROCESS_BYTES * 8 + 1), stderr="")):
            with self.assertRaises(RuntimeError):
                m.public_processes_list()

    def test_system_usage_aggregate_values_only(self):
        result = m.public_system_usage()
        self.assertEqual(result["host"], "marina-a1-4c24g")
        self.assertGreater(result["root_total_bytes"], 0)
        self.assertGreater(result["memory_total_bytes"], 0)
        self.assertGreater(result["cpu_count"], 0)
        self.assertNotIn("user", result)
        self.assertNotIn("argv", result)
        self.assertNotIn("password", result)


if __name__ == "__main__":
    unittest.main()
