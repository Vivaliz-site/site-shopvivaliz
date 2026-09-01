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
