from __future__ import annotations

import importlib.util
import os
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = ROOT / "scripts" / "run_background_gemini.py"


def load_runner():
    if not RUNNER.is_file():
        raise AssertionError("scripts/run_background_gemini.py is missing")
    spec = importlib.util.spec_from_file_location("run_background_gemini_test", RUNNER)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load background Gemini runner")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class BackgroundGeminiRunnerTests(unittest.TestCase):
    def setUp(self) -> None:
        self.mod = load_runner()

    def test_selects_first_supported_nonempty_credential_without_exposing_value(self) -> None:
        source, value = self.mod.select_gemini_credential(
            """
            # comment
            GEMINI_API_KEY=
            GOOGLE_API_KEY="google-value"
            GOOGLE_GEMINI_API_KEY=third-value
            """
        )
        self.assertEqual(source, "GOOGLE_API_KEY")
        self.assertEqual(value, "google-value")

    def test_child_environment_is_trusted_and_contains_only_gemini_provider_secret(self) -> None:
        base = {
            "PATH": "/usr/bin",
            "OPENAI_API_KEY": "openai-secret",
            "ANTHROPIC_API_KEY": "anthropic-secret",
            "GOOGLE_API_KEY": "old-google",
            "GOOGLE_GEMINI_API_KEY": "old-google-gemini",
            "UNRELATED": "keep-me",
        }
        env = self.mod.build_child_env(base, "gemini-secret")
        self.assertEqual(env["GEMINI_API_KEY"], "gemini-secret")
        self.assertEqual(env["GEMINI_CLI_TRUST_WORKSPACE"], "true")
        self.assertEqual(env["UNRELATED"], "keep-me")
        self.assertNotIn("OPENAI_API_KEY", env)
        self.assertNotIn("ANTHROPIC_API_KEY", env)
        self.assertNotIn("GOOGLE_API_KEY", env)
        self.assertNotIn("GOOGLE_GEMINI_API_KEY", env)

    def test_default_binary_does_not_depend_on_systemd_path(self) -> None:
        self.assertEqual(
            str(self.mod.DEFAULT_GEMINI_BIN),
            "/home/ubuntu/.local/bin/gemini",
        )

    def test_headless_tools_core_allows_only_bounded_shell_prefixes(self) -> None:
        # Regression: gemini-cli's --admin-policy TOML rules for
        # run_shell_command are silently ignored in non-interactive
        # --prompt + --approval-mode auto_edit mode (upstream issue
        # google-gemini/gemini-cli#20469: the tool is not even registered,
        # "Tool 'run_shell_command' not found"). tools.core in
        # .gemini/settings.json gates tool *registration* itself, so it
        # is not subject to that policy-engine bypass.
        tools_core = self.mod.build_headless_tools_core()
        for prefix in (
            "python3 scripts/agent_task_state.py",
            "./scripts/agent_task_state.py",
            "python3 -m unittest",
            "git status",
            "git diff",
            "git add",
            "git commit",
            "git push",
            "gh pr",
            "bash tests/",
            "bash scripts/repository-governance-validate.sh",
        ):
            self.assertIn(f"run_shell_command({prefix})", tools_core)
        for forbidden in (
            "sudo ",
            "systemctl ",
            "ssh ",
            "rm -rf",
            "python3 -c",
            "bash -c",
        ):
            self.assertFalse(any(forbidden in entry for entry in tools_core))

    def test_settings_json_declares_only_the_bounded_tools_core(self) -> None:
        tools_core = self.mod.build_headless_tools_core()
        settings_text = self.mod.build_gemini_settings_json(tools_core)
        import json

        settings = json.loads(settings_text)
        self.assertEqual(settings["tools"]["core"], tools_core)

    def test_gemini_command_has_no_admin_policy_flag_and_no_yolo(self) -> None:
        command = self.mod.build_gemini_command(
            executable="/home/ubuntu/.local/bin/gemini",
            model="gemini-2.5-flash",
            prompt="continue task",
        )
        self.assertIn("--approval-mode", command)
        self.assertEqual(command[command.index("--approval-mode") + 1], "auto_edit")
        self.assertNotIn("--admin-policy", command)
        self.assertNotIn("yolo", command)

    def test_run_writes_workspace_settings_json_with_bounded_tools_core(self) -> None:
        import json
        import tempfile

        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            env_file = workspace / ".env"
            env_file.write_text("GEMINI_API_KEY=fixture-secret\n", encoding="utf-8")
            prompt_file = workspace / "prompt.txt"
            prompt_file.write_text("continue task", encoding="utf-8")
            observed_path = workspace / "observed-settings.json"
            gemini_bin = workspace / "gemini"
            gemini_bin.write_text(
                "#!/bin/sh\n"
                f'cp ".gemini/settings.json" "{observed_path}"\n'
                "exit 0\n",
                encoding="utf-8",
            )
            gemini_bin.chmod(0o755)

            previous_cwd = Path.cwd()
            os.chdir(workspace)
            try:
                rc = self.mod.run(
                    model="gemini-2.5-flash",
                    prompt_file=prompt_file,
                    env_file=env_file,
                    gemini_bin=str(gemini_bin),
                )
            finally:
                os.chdir(previous_cwd)

            self.assertEqual(rc, 0)
            observed = json.loads(observed_path.read_text(encoding="utf-8"))
            self.assertIn(
                "run_shell_command(python3 scripts/agent_task_state.py)",
                observed["tools"]["core"],
            )
            settings_path = workspace / ".gemini" / "settings.json"
            self.assertFalse(settings_path.exists(), "workspace settings must be cleaned up")

    def test_classify_gemini_failure_recognizes_only_allowlisted_reasons(self) -> None:
        # Regression: the second production E2E (run 36296738560) showed
        # background_gemini_exit_code=1 with no further signal — not enough
        # to know whether it is an approval-dialog block (the auto_edit +
        # non-interactive hypothesis), an auth/quota/model problem, or
        # something else. Classify from Gemini's own (already
        # credential-redacted) output into a bounded, allowlisted reason so
        # the next production run tells us which, without ever persisting
        # the raw message.
        cases = [
            ("Waiting for user confirmation to run this command", "approval_required"),
            ("This action requires approval before it can proceed", "approval_required"),
            ("Error executing tool run_shell_command: Tool 'run_shell_command' not found.", "tool_not_registered"),
            ("run_shell_command(python3 scripts/agent_task_state.py) is not in the list of allowed tools", "tool_not_allowed"),
            ("401 Unauthorized: invalid API key", "authentication_failed"),
            ("PERMISSION_DENIED: authentication failed", "authentication_failed"),
            ("429 RESOURCE_EXHAUSTED: quota exceeded for this project", "quota_exhausted"),
            ("rate limit exceeded, please retry later", "quota_exhausted"),
            ("404 NOT_FOUND: model gemini-2.5-flash is not available", "model_unavailable"),
            ("this workspace folder is not trusted", "workspace_untrusted"),
            ("some completely novel failure text never seen before", "unknown_safe_error"),
            ("", "unknown_safe_error"),
        ]
        for output, expected in cases:
            with self.subTest(output=output):
                self.assertEqual(self.mod.classify_gemini_failure(output, 1), expected)

    def test_classify_gemini_failure_is_not_computed_on_success(self) -> None:
        self.assertIsNone(self.mod.classify_gemini_failure("anything at all", 0))

    def test_run_prints_sanitized_reason_line_on_nonzero_exit(self) -> None:
        import tempfile

        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            env_file = workspace / ".env"
            env_file.write_text("GEMINI_API_KEY=fixture-secret\n", encoding="utf-8")
            prompt_file = workspace / "prompt.txt"
            prompt_file.write_text("continue task", encoding="utf-8")
            gemini_bin = workspace / "gemini"
            gemini_bin.write_text(
                "#!/bin/sh\n"
                "echo 'Waiting for user confirmation to run this command'\n"
                "exit 1\n",
                encoding="utf-8",
            )
            gemini_bin.chmod(0o755)

            previous_cwd = Path.cwd()
            os.chdir(workspace)
            try:
                import io
                import contextlib

                buf = io.StringIO()
                with contextlib.redirect_stdout(buf):
                    rc = self.mod.run(
                        model="gemini-2.5-flash",
                        prompt_file=prompt_file,
                        env_file=env_file,
                        gemini_bin=str(gemini_bin),
                    )
            finally:
                os.chdir(previous_cwd)

            self.assertEqual(rc, 1)
            self.assertIn("background_gemini_reason=approval_required", buf.getvalue())

    def test_failover_uses_protected_background_runner_only_in_background_mode(self) -> None:
        failover = (ROOT / "scripts" / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("run_background_gemini.py", failover)
        self.assertIn('if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]', failover)
        self.assertIn('--prompt-file "$PROMPT_FILE"', failover)
        self.assertIn('--model "$GEMINI_MODEL"', failover)
        self.assertIn("BACKGROUND_ORDER=(gemini)", failover)
        self.assertIn("background_paid_fallback_forbidden=true", failover)


if __name__ == "__main__":
    unittest.main()
