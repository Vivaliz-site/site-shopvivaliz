"""Offline regression: a hung Gemini CLI must never stall persistent jobs."""
from __future__ import annotations

import contextlib
import importlib.util
import io
import os
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("gemini_bounded_runner", ROOT / "scripts/run_background_gemini.py")
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


@unittest.skipUnless(os.name == "posix", "process group isolation is POSIX-specific")
class BoundedGeminiTests(unittest.TestCase):
    def test_slow_gemini_is_killed_bounded_and_routes_back_to_ollama(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            env_file = root / "runtime.env"
            env_file.write_text("GEMINI_API_KEY=fixture-secret\n")
            prompt = root / "prompt.txt"
            prompt.write_text("offline fixture only")
            observed = root / "started.txt"
            cli = root / "gemini"
            cli.write_text(
                "#!/bin/sh\n"
                f"echo invoked >> {str(observed)!r}\n"
                "echo fixture-secret\n"
                "sleep 40 &\n"
                "wait\n"
            )
            cli.chmod(0o755)
            before = Path.cwd()
            out = io.StringIO()
            started = time.monotonic()
            os.chdir(root)
            try:
                with patch.dict(os.environ, {"SHOPVIVALIZ_RESUME_BACKGROUND": "0"}):
                    with contextlib.redirect_stdout(out):
                        code = mod.run(
                            model="gemini-flash-latest",
                            prompt_file=prompt,
                            env_file=env_file,
                            gemini_bin=str(cli),
                            fallback_models=("gemini-flash-lite-latest",),
                            attempt_timeout_seconds=1,
                        )
            finally:
                os.chdir(before)
            elapsed = time.monotonic() - started
            self.assertEqual(code, 124)
            self.assertLess(elapsed, 8, "bounded process group timeout must not hang")
            self.assertEqual(observed.read_text().strip().splitlines(), ["invoked"])
            self.assertIn("reason:request_timeout", out.getvalue())
            self.assertIn("background_gemini_reason=request_timeout", out.getvalue())
            self.assertNotIn("fixture-secret", out.getvalue())
            self.assertFalse((root / ".gemini/settings.json").exists())

    def test_bounded_direct_process_returns_stdout_when_success(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            script = Path(tmp) / "cli"
            script.write_text("#!/bin/sh\nprintf '%s' 'OK'\nexit 0\n")
            script.chmod(0o755)
            result = mod.run_bounded_gemini([str(script)], os.environ.copy(), 2)
            self.assertEqual(result.returncode, 0)
            self.assertEqual(result.stdout, "OK")

    def test_runtime_timeout_is_finite(self) -> None:
        source = (ROOT / "scripts/run_background_gemini.py").read_text()
        self.assertIn("DEFAULT_GEMINI_ATTEMPT_TIMEOUT_SECONDS = 60", source)
        self.assertIn("timeout_seconds = max(15, min(90, timeout_seconds))", source)
        self.assertIn("start_new_session=True", source)
        self.assertIn("stdin=subprocess.DEVNULL", source)
        self.assertIn("os.killpg(process.pid, sig)", source)


if __name__ == "__main__":
    unittest.main()
