import importlib.util
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / "scripts" / "validate-recurring-ai-policy.py"
GOVERNANCE = ROOT / "scripts" / "repository-governance-validate.sh"


def load_guard():
    spec = importlib.util.spec_from_file_location("recurring_ai_guard", GUARD)
    if spec is None or spec.loader is None:
        raise RuntimeError("guard module unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class RecurringAiPolicyGuardTests(unittest.TestCase):
    def test_guard_is_part_of_repository_governance(self):
        self.assertTrue(GUARD.is_file(), "recurring AI policy guard must exist")
        text = GOVERNANCE.read_text(encoding="utf-8")
        self.assertIn("python3 scripts/validate-recurring-ai-policy.py", text)

    def test_scheduled_paid_ai_is_rejected(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "bad.yml").write_text(
                textwrap.dedent(
                    """
                    name: bad
                    on:
                      schedule:
                        - cron: '*/5 * * * *'
                    jobs:
                      paid:
                        runs-on: ubuntu-latest
                        steps:
                          - uses: anthropics/claude-code-action@v1
                    """
                ),
                encoding="utf-8",
            )
            violations = guard.scan_repository(root)
            self.assertTrue(any(v.code == "scheduled_paid_ai" for v in violations))


    def test_direct_claude_print_from_schedule_is_rejected(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "bad-claude.yml").write_text(textwrap.dedent("""
                name: unpaid background guard
                on:
                  schedule:
                    - cron: '*/10 * * * *'
                jobs:
                  run:
                    runs-on: ubuntu-latest
                    steps:
                      - run: claude -p "continue this task"
                """))
            self.assertIn(
                "scheduled_paid_ai",
                {v.code for v in guard.scan_repository(root)},
            )

    def test_direct_shell_script_wrapper_with_claude_print_is_rejected(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            workflows = root / ".github" / "workflows"
            scripts.mkdir(parents=True)
            workflows.mkdir(parents=True)
            (scripts / "paid-worker.sh").write_text(
                "#!/bin/sh\nclaude --print 'repeat again'\n"
            )
            (workflows / "paid.yml").write_text(textwrap.dedent("""
                name: invisible paid shell
                on:
                  schedule:
                    - cron: '0 * * * *'
                jobs:
                  run:
                    runs-on: ubuntu-latest
                    steps:
                      - run: ./scripts/paid-worker.sh
                """))
            violations = guard.scan_repository(root)
            self.assertIn("scheduled_paid_ai", {v.code for v in violations})
            self.assertTrue(any("paid-worker.sh" in v.message for v in violations))

    def test_scheduled_python_list_claude_cli_is_rejected(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            workflows = root / ".github" / "workflows"
            scripts.mkdir(parents=True)
            workflows.mkdir(parents=True)
            (scripts / "paid-worker.py").write_text(
                "import subprocess\nsubprocess.run(['claude', '-p', 'again'])\n"
            )
            (workflows / "paid.yml").write_text(textwrap.dedent("""
                name: hidden paid subprocess
                on:
                  schedule:
                    - cron: '0 * * * *'
                jobs:
                  run:
                    runs-on: ubuntu-latest
                    steps:
                      - run: python3 scripts/paid-worker.py
                """))
            self.assertIn(
                "scheduled_paid_ai", {v.code for v in guard.scan_repository(root)}
            )

    def test_oci_bootstrap_claude_diagnostic_is_non_generative(self):
        import re
        workflow = (ROOT / ".github" / "workflows" / "oci-bastion-private-access-bootstrap.yml").read_text()
        marker = "- name: Diagnose AI Squad Claude bridge through Remote Control MCP"
        self.assertIn(marker, workflow)
        block = workflow.split(marker, 1)[1].split("\n      - name:", 1)[0]
        self.assertNotRegex(block, r"/claude\s+-p\b")
        self.assertIn("PAID_MODEL_DIAGNOSTIC_NO_GENERATION_V1", block)
        self.assertIn("AI_SQUAD_CLAUDE_PROBE_SKIPPED=", block)
        self.assertIn("AI_SQUAD_CLAUDE_PROBE_FAILURE_CLASS=", block)

    def test_human_only_workflow_dispatch_with_claude_cli_is_allowed(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "manual.yml").write_text(textwrap.dedent("""
                name: explicit manual action
                on:
                  workflow_dispatch:
                jobs:
                  approved:
                    runs-on: ubuntu-latest
                    steps:
                      - run: claude -p "explicit manual job"
                """))
            self.assertEqual([], guard.scan_repository(root))

    def test_scheduled_codex_remote_exec_is_also_rejected(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "codex.yml").write_text(textwrap.dedent("""
                name: codex remote automatic
                on:
                  schedule:
                    - cron: '0 * * * *'
                jobs:
                  paid:
                    runs-on: ubuntu-latest
                    steps:
                      - run: codex exec --remote --environment-id fixture
                """))
            self.assertIn(
                "scheduled_paid_ai", {v.code for v in guard.scan_repository(root)}
            )

    def test_scheduled_wrapper_calling_paid_script_is_rejected(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            scripts = root / "scripts"
            workflows.mkdir(parents=True)
            scripts.mkdir(parents=True)
            (scripts / "worker.py").write_text(
                "import openai\nclient = openai.OpenAI(api_key='x')\n",
                encoding="utf-8",
            )
            (workflows / "bad-wrapper.yml").write_text(
                textwrap.dedent(
                    """
                    name: bad-wrapper
                    on:
                      schedule:
                        - cron: '0 * * * *'
                    jobs:
                      paid:
                        runs-on: ubuntu-latest
                        steps:
                          - run: python3 scripts/worker.py
                    """
                ),
                encoding="utf-8",
            )
            violations = guard.scan_repository(root)
            self.assertTrue(any(v.code == "scheduled_paid_ai" for v in violations))
            self.assertTrue(any("scripts" in v.message and "worker.py" in v.message for v in violations))

    def test_explicit_human_claude_trigger_is_allowed(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "claude.yml").write_text(
                textwrap.dedent(
                    """
                    name: Claude
                    on:
                      issue_comment:
                        types: [created]
                    jobs:
                      claude:
                        if: |-
                          !endsWith(github.actor, '[bot]') &&
                          github.event_name == 'issue_comment' &&
                          contains(github.event.comment.body, '@claude')
                        runs-on: ubuntu-latest
                        steps:
                          - uses: anthropics/claude-code-action@v1
                    """
                ),
                encoding="utf-8",
            )
            self.assertEqual([], guard.scan_repository(root))

    def test_broad_event_paid_ai_is_rejected(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "claude.yml").write_text(
                textwrap.dedent(
                    """
                    name: Claude
                    on:
                      issue_comment:
                        types: [created]
                    jobs:
                      claude:
                        runs-on: ubuntu-latest
                        steps:
                          - uses: anthropics/claude-code-action@v1
                    """
                ),
                encoding="utf-8",
            )
            violations = guard.scan_repository(root)
            self.assertTrue(any(v.code == "broad_paid_ai_event" for v in violations))

    def test_plugin_name_does_not_count_as_human_opt_in(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "review.yml").write_text(
                textwrap.dedent(
                    """
                    name: review
                    on:
                      pull_request:
                        types: [opened, synchronize]
                    jobs:
                      review:
                        runs-on: ubuntu-latest
                        steps:
                          - uses: anthropics/claude-code-action@v1
                            with:
                              plugins: code-review@claude-code-plugins
                    """
                ),
                encoding="utf-8",
            )
            violations = guard.scan_repository(root)
            self.assertTrue(any(v.code == "broad_paid_ai_event" for v in violations))

    def test_background_codex_flag_in_workflow_is_rejected_even_without_cli_call(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "bad-recovery.yml").write_text(
                textwrap.dedent(
                    """
                    name: recovery
                    on:
                      workflow_call:
                    jobs:
                      recover:
                        runs-on: self-hosted
                        steps:
                          - run: grep -Fxq 'SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1' /etc/controller.env
                    """
                ),
                encoding="utf-8",
            )
            violations = guard.scan_repository(root)
            self.assertIn("paid_background_fallback_enabled", {v.code for v in violations})

    def test_backend_direct_recovery_requires_free_only_background(self):
        workflow = (ROOT / ".github" / "workflows" / "backend-continuity-direct-recovery.yml").read_text()
        self.assertIn("SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=0", workflow)
        self.assertIn("SHOPVIVALIZ_BACKGROUND_CLAUDE_FALLBACK=0", workflow)
        self.assertNotIn("SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1", workflow)
        self.assertNotIn("CODEX_AUTO_APPROVE_FOR_ME_CAPABILITY=PASS", workflow)

    def test_free_local_recurring_ai_is_allowed(self):
        guard = load_guard()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            workflows = root / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "free.yml").write_text(
                textwrap.dedent(
                    """
                    name: free
                    on:
                      schedule:
                        - cron: '*/10 * * * *'
                    jobs:
                      repair:
                        runs-on: self-hosted
                        steps:
                          - run: python3 scripts/pr_conflict_gemini_healer.py
                    """
                ),
                encoding="utf-8",
            )
            self.assertEqual([], guard.scan_repository(root))


if __name__ == "__main__":
    unittest.main()
