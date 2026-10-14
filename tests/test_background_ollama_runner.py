from __future__ import annotations

import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "background_ollama_runner", ROOT / "scripts/run_background_ollama.py"
)
assert SPEC is not None and SPEC.loader is not None
runner = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runner)


class FakeResponse:
    status = 200

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self, _size):
        return json.dumps({"done": True, "response": "private advisory", "eval_count": 6}).encode()


class FakeOpener:
    def open(self, request, timeout):
        assert request.full_url == "http://127.0.0.1:11434/api/generate"
        assert 1 <= timeout <= 90
        return FakeResponse()


class BackgroundOllamaTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.prompt = Path(self.temp.name) / "request.txt"
        self.prompt.write_text("do not expose task content", encoding="utf-8")

    def test_only_loopback_allowed_no_network(self):
        output = io.StringIO()
        with patch.object(runner, "build_opener", side_effect=AssertionError("network reached")):
            with contextlib.redirect_stdout(output):
                rc = runner.run(self.prompt, endpoint="https://example.com")
        self.assertEqual(rc, 78)
        self.assertIn("endpoint_not_local", output.getvalue())

    def test_safe_local_generation_redacts_response(self):
        output = io.StringIO()
        with patch.object(runner, "build_opener", return_value=FakeOpener()):
            with contextlib.redirect_stdout(output):
                rc = runner.run(self.prompt)
        self.assertEqual(rc, 0)
        self.assertIn("advisory_complete", output.getvalue())
        self.assertIn("generated_tokens=6", output.getvalue())
        self.assertNotIn("private advisory", output.getvalue())
        self.assertNotIn("do not expose", output.getvalue())

    def test_invalid_model_rejected(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            rc = runner.run(self.prompt, model="qwen;curl foo")
        self.assertEqual(rc, 64)
        self.assertIn("invalid_model", output.getvalue())


if __name__ == "__main__":
    unittest.main()
