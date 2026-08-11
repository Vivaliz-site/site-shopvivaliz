#!/usr/bin/env python3
import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts" / "pr_conflict_gemini_healer.py"
spec = importlib.util.spec_from_file_location("pr_conflict_gemini_healer", MODULE_PATH)
assert spec and spec.loader
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
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

    def test_private_env_parser_reads_only_gemini_names(self):
        with tempfile.TemporaryDirectory() as directory:
            env_file = Path(directory) / ".env"
            env_file.write_text(
                "DB_PASS=must-never-be-loaded\n"
                "GEMINI_API_KEY=key-a\n"
                "GOOGLE_GEMINI_API_KEY='key-b'\n"
                "GOOGLE_IMAGEN_API_KEYS=key-c,key-a\n"
                "OPENAI_API_KEY=must-never-be-loaded-either\n",
                encoding="utf-8",
            )
            parsed = module.parse_env_file(env_file)

        self.assertEqual(
            parsed,
            {
                "GEMINI_API_KEY": "key-a",
                "GOOGLE_GEMINI_API_KEY": "key-b",
                "GOOGLE_IMAGEN_API_KEYS": "key-c,key-a",
            },
        )
        self.assertNotIn("DB_PASS", parsed)
        self.assertNotIn("OPENAI_API_KEY", parsed)

    def test_credential_environment_uses_private_file_and_deduplicates(self):
        with tempfile.TemporaryDirectory() as directory:
            env_file = Path(directory) / ".env"
            env_file.write_text(
                "GEMINI_API_KEY=key-from-file\n"
                "GOOGLE_GEMINI_API_KEY=second-key\n"
                "GOOGLE_API_KEY=key-from-file\n",
                encoding="utf-8",
            )
            with patch.dict(
                os.environ,
                {
                    "GEMINI_ENV_FILE": str(env_file),
                    "GEMINI_API_KEY": "runner-key-that-file-overrides",
                },
                clear=True,
            ):
                env = module.credential_environment()
                keys = module.collect_api_keys(env)

        self.assertEqual(keys, ["key-from-file", "second-key"])

    def test_credential_preflight_reports_count_not_values(self):
        fake_env = {
            "GEMINI_API_KEY": "secret-one",
            "GOOGLE_GEMINI_API_KEY": "secret-two",
        }
        with patch.object(module, "credential_environment", return_value=fake_env), patch(
            "builtins.print"
        ) as printer:
            code = module.credential_preflight()

        self.assertEqual(code, 0)
        rendered = "\n".join(" ".join(map(str, call.args)) for call in printer.call_args_list)
        self.assertIn("gemini_credentials_configured=2", rendered)
        self.assertIn("secret_values_printed=false", rendered)
        self.assertNotIn("secret-one", rendered)
        self.assertNotIn("secret-two", rendered)

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
