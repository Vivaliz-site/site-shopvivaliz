#!/usr/bin/env python3
import importlib.util
import json
import os
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts" / "pr_conflict_gemini_healer.py"
spec = importlib.util.spec_from_file_location("pr_conflict_gemini_healer", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)


class CredentialPoolTests(unittest.TestCase):
    def test_collects_aliases_lists_and_deduplicates_without_placeholders(self):
        env = {
            "GEMINI_API_KEY": "key-a",
            "GOOGLE_GEMINI_API_KEY": "key-b",
            "GOOGLE_IMAGEN_API_KEY": "key-a",
            "GOOGLE_API_KEY": "",
            "GEMINI_API_KEYS": "key-c,key-d\nkey-e;key-c",
            "GOOGLE_IMAGEN_API_KEYS": "replace-with-google-imagen-api-key",
        }
        self.assertEqual(
            module.collect_api_keys(env),
            ["key-a", "key-b", "key-c", "key-d", "key-e"],
        )

    def test_default_models_prefer_current_stable_flash(self):
        self.assertEqual(module.configured_models({})[0], "gemini-3.6-flash")


class ResolutionTests(unittest.TestCase):
    def test_parse_resolution_accepts_json_and_rejects_conflict_markers(self):
        self.assertEqual(
            module.parse_resolution(json.dumps({"resolved": "<?php echo 'ok';\n"})),
            "<?php echo 'ok';\n",
        )
        with self.assertRaises(ValueError):
            module.parse_resolution(json.dumps({"resolved": "<<<<<<< ours\n"}))

    def test_rotates_to_next_key_after_quota_exhaustion(self):
        calls = []

        def fake_call(key, model, prompt):
            calls.append((key, model))
            if key == "first-key":
                return module.GeminiResult(False, 429, "")
            return module.GeminiResult(
                True,
                200,
                json.dumps({"resolved": "resolved\n"}),
            )

        with patch.object(module, "conflict_modes", return_value={"100644"}), patch.object(
            module, "git_stage", return_value=b"content\n"
        ), patch.object(module, "call_gemini", side_effect=fake_call):
            resolved, attempts = module.resolve_file(
                "example.php",
                ["first-key", "second-key"],
                ["gemini-3.6-flash", "gemini-2.5-flash"],
            )

        self.assertEqual(resolved, "resolved\n")
        self.assertEqual(attempts, 2)
        self.assertEqual(
            calls,
            [
                ("first-key", "gemini-3.6-flash"),
                ("second-key", "gemini-3.6-flash"),
            ],
        )

    def test_model_fallback_does_not_discard_working_key_on_404(self):
        calls = []

        def fake_call(key, model, prompt):
            calls.append((key, model))
            if model == "gemini-3.6-flash":
                return module.GeminiResult(False, 404, "")
            return module.GeminiResult(True, 200, json.dumps({"resolved": "fallback\n"}))

        with patch.object(module, "conflict_modes", return_value={"100644"}), patch.object(
            module, "git_stage", return_value=b"content\n"
        ), patch.object(module, "call_gemini", side_effect=fake_call):
            resolved, attempts = module.resolve_file(
                "example.php",
                ["one-key"],
                ["gemini-3.6-flash", "gemini-2.5-flash"],
            )

        self.assertEqual(resolved, "fallback\n")
        self.assertEqual(attempts, 2)
        self.assertEqual(len(calls), 2)


if __name__ == "__main__":
    unittest.main()
