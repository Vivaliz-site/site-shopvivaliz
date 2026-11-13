"""Long MCP commands must not stall the relay's event loop and /health."""
import ast
import asyncio
import subprocess
import time
import typing
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/mcp-server.py"

def isolated_method():
    tree = ast.parse(SOURCE.read_text(encoding="utf-8"))
    cls = next(n for n in tree.body if isinstance(n, ast.ClassDef) and n.name == "MCPTools")
    fn = next(n for n in cls.body if isinstance(n, ast.AsyncFunctionDef) and n.name == "_execute_command")
    wrapper = ast.parse("class IsolatedMCPTools:\n    pass\n")
    wrapper.body[0].body = [fn]
    ast.fix_missing_locations(wrapper)
    scope = dict(asyncio=asyncio, subprocess=subprocess, REPO_ROOT=ROOT, Dict=typing.Dict, Any=typing.Any)
    exec(compile(wrapper, str(SOURCE), "exec"), scope)
    return scope["IsolatedMCPTools"]._execute_command

class MCPRelayConcurrencyTests(unittest.TestCase):
    def test_long_command_allows_health_while_running(self):
        def fake(*args, **kwargs):
            time.sleep(0.25)
            return subprocess.CompletedProcess(["noop"], 0, "OK", "")
        async def probe():
            job = asyncio.create_task(isolated_method()({"command": "noop", "timeout": 2}))
            await asyncio.sleep(0.05)
            responsive = not job.done()
            result = await job
            return responsive, result
        with patch.object(subprocess, "run", fake):
            responsive, result = asyncio.run(probe())
        self.assertTrue(responsive, "Running tool starved /health and triggered watchdog restart")
        self.assertTrue(result["success"])
        self.assertEqual(result["output"], "OK")

    def test_timeout_retains_error(self):
        def fail(*args, **kwargs):
            raise subprocess.TimeoutExpired("noop", 2)
        with patch.object(subprocess, "run", fail):
            result = asyncio.run(isolated_method()({"command": "noop", "timeout": 2}))
        self.assertIn("timeout", result["error"].lower())

    def test_nonzero_retains_failure(self):
        def fail(*args, **kwargs):
            return subprocess.CompletedProcess(["noop"], 9, "", "fixture error")
        with patch.object(subprocess, "run", fail):
            result = asyncio.run(isolated_method()({"command": "noop", "timeout": 2}))
        self.assertFalse(result["success"])
        self.assertEqual(result["error"], "fixture error")

if __name__ == "__main__":
    unittest.main()
