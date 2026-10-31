"""CI relevance contract: specialized gate runs only for affected components.

General agent-policy changes remain covered by mandatory/repository/absolute
governance gates; they are not a reason to launch unrelated functional suites.
"""
from __future__ import annotations

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"


def event_paths(filename: str, event: str = "pull_request") -> list[str] | None:
    text = (WORKFLOWS / filename).read_text(encoding="utf-8")
    event_match = re.search(rf"(?m)^  {re.escape(event)}:\s*(?:#.*)?$", text)
    if event_match is None:
        return None
    end_match = re.search(r"(?m)^  [a-z_]+:", text[event_match.end():])
    body = text[event_match.end():event_match.end() + end_match.start() if end_match else len(text)]
    if not re.search(r"(?m)^    paths:\s*$", body):
        return None
    return re.findall(r"(?m)^      - ['\"]([^'\"]+)['\"]\s*$", body)


class OnlyRelevantGatesTest(unittest.TestCase):
    def test_cart_contract_only_for_mcp_cart_paths(self):
        paths = event_paths("marina-cart-maintenance-ci.yml")
        self.assertIsNotNone(paths, "cart contract may not run on every PR")
        self.assertIn("remote-control-mcp/**", paths)
        self.assertIn("tests/test_marina_cart_signed_maintenance.py", paths)
        self.assertIn(".github/workflows/marina-cart-maintenance-ci.yml", paths)
        self.assertNotIn("AGENTS.md", paths)
        self.assertNotIn("scripts/chatgpt_continuity_nudge_dispatcher.py", paths)
        self.assertIn("workflow_dispatch:", (WORKFLOWS / "marina-cart-maintenance-ci.yml").read_text())

    def test_agent_clone_does_not_replay_on_generic_agent_policy(self):
        for event in ("pull_request", "push"):
            paths = event_paths("agent-clone-lifecycle-ci.yml", event)
            self.assertIsNotNone(paths)
            self.assertNotIn("AGENTS.md", paths)
            self.assertIn("scripts/agent_clone_lifecycle.py", paths)
            self.assertIn("tests/test_agent_clone_lifecycle.py", paths)

    def test_continuity_suite_not_triggered_by_generic_agent_instructions(self):
        broad_docs = {"AGENTS.md", "CLAUDE.md", "GEMINI.md",
                      "AI-TO-CLI-PROTOCOL.md", ".github/copilot-instructions.md"}
        for event in ("pull_request", "push"):
            paths = event_paths("task-continuity-fast-gate.yml", event)
            self.assertIsNotNone(paths)
            self.assertFalse(broad_docs.intersection(paths),
                             f"unrelated general instructions trigger the continuity suite: {event}")
            self.assertIn("scripts/chatgpt_continuity_nudge_dispatcher.py", paths)
            self.assertIn("docs/knowledge/task-continuity.md", paths)

    def test_all_specialized_pr_workflows_are_diff_scoped(self):
        # Global security gates are intentionally invoked for every PR.
        always_required = {"mandatory-validation-gate.yml", "repository-governance.yml"}
        unfiltered = []
        for path in sorted(WORKFLOWS.glob("*.yml")) + sorted(WORKFLOWS.glob("*.yaml")):
            source = path.read_text(encoding="utf-8")
            event = re.search(r"(?m)^  pull_request:\\s*(?:#.*)?$", source)
            if event is None:
                continue
            end = re.search(r"(?m)^  [a-z_]+:", source[event.end():])
            body = source[event.end():event.end() + end.start() if end else len(source)]
            if not re.search(r"(?m)^    paths(?:-ignore)?:\\s*(?:\\[|$)", body):
                unfiltered.append(path.name)
        self.assertEqual(sorted(unfiltered), sorted(always_required))

    def test_general_agent_policy_still_has_fail_closed_required_gates(self):
        mandatory = (WORKFLOWS / "mandatory-validation-gate.yml").read_text()
        repository = (WORKFLOWS / "repository-governance.yml").read_text()
        absolute = (WORKFLOWS / "absolute-audit-governance.yml").read_text()
        self.assertIn("  pull_request:", mandatory)
        self.assertIn("  pull_request:", repository)
        self.assertIn("'AGENTS.md'", absolute)
        self.assertIn("scripts/validate-audit-governance.py",
                      (ROOT / "scripts" / "ci_scoped_validate.sh").read_text())

    def test_admin_grant_pr_does_not_select_cart_or_clone(self):
        # PR #3446 changes a continuity dispatcher, auth policy and audit tests.
        changed = {
            ".github/workflows/absolute-audit-governance.yml",
            "AGENTS.md",
            "docs/knowledge/security-approval-via-pr.md",
            "scripts/chatgpt_continuity_nudge_dispatcher.py",
            "scripts/validate-owner-pr-grant.py",
            "tests/test_validate_owner_pr_grant.py",
        }
        cart = event_paths("marina-cart-maintenance-ci.yml")
        clone = event_paths("agent-clone-lifecycle-ci.yml")
        continuity = event_paths("task-continuity-fast-gate.yml")
        self.assertFalse(changed.intersection(set(cart or [])))
        self.assertFalse(changed.intersection(set(clone or [])))
        self.assertIn("scripts/chatgpt_continuity_nudge_dispatcher.py", continuity)


if __name__ == "__main__":
    unittest.main()
