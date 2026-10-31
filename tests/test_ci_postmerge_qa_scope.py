"""Post-merge ShopVivaliz QA must be triggered only for related changes."""
from __future__ import annotations

from fnmatch import fnmatchcase
from pathlib import Path
import importlib.util
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github" / "workflows" / "shopvivaliz-qa.yml"
SCOPE = ROOT / "scripts" / "pr_gate_scope.py"
SPEC = importlib.util.spec_from_file_location("pr_gate_scope", SCOPE)
assert SPEC and SPEC.loader
scope = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(scope)


def event_paths(event: str) -> list[str]:
    source = WF.read_text(encoding="utf-8")
    start = source.index(f"  {event}:\n")
    nxt = re.search(r"(?m)^  [a-z_]+:", source[start + len(event) + 4:])
    end = start + len(event) + 4 + nxt.start() if nxt else len(source)
    block = source[start:end]
    assert re.search(r"(?m)^    paths:\s*$", block), f"missing {event}.paths"
    return re.findall(r"(?m)^      - '([^']+)'$", block)


class PostMergeQaScope(unittest.TestCase):
    def test_main_push_matches_relevant_pr_paths(self):
        self.assertEqual(event_paths("push"), event_paths("pull_request"))

    def test_relevant_frontend_and_release_paths_trigger_qa(self):
        paths = event_paths("push")
        required = {
            "api/**", "admin/**", "includes/**", "blog/**", "public/**",
            "assets/**", "css/**", "js/**", "*.php", "*.html",
            "package.json", "composer.lock", "scripts/deploy-production.sh",
            ".github/workflows/shopvivaliz-qa.yml",
        }
        self.assertFalse(required.difference(paths))
        for path in ("css/catalog.css", "js/catalog.js", "public/assets/icon.svg",
                     "includes/catalog.php", "api/olist/hook.php", "blog/index.php",
                     "index.php", "scripts/deploy-production.sh"):
            with self.subTest(path=path):
                self.assertTrue(any(fnmatchcase(path, pattern) for pattern in paths))
                self.assertIn("ShopVivaliz QA", scope.required_specialized_gates([path]))

    def test_scope_regressions_are_enforced_by_relevant_workflows(self):
        qa = WF.read_text(encoding="utf-8")
        self.assertIn("tests.test_ci_postmerge_qa_scope", qa)
        self.assertIn("tests.test_ci_relevant_specialized_gates", qa)
        self.assertIn("'tests/test_ci_postmerge_qa_scope.py'", qa)
        policy = (ROOT / ".github" / "workflows" / "pr-policy-enforcement.yml").read_text(encoding="utf-8")
        self.assertIn("tests.test_pr_gate_replay tests.test_pr_gate_scope", policy)
        self.assertIn("'scripts/pr_gate_scope.py'", policy)
        self.assertIn("'tests/test_pr_gate_scope.py'", policy)

    def test_generic_pr_mapping_tests_do_not_trigger_continuity_worker(self):
        continuity = (ROOT / ".github" / "workflows" / "task-continuity-fast-gate.yml").read_text(encoding="utf-8")
        continuity_pr = continuity.split("  push:", 1)[0]
        policy = (ROOT / ".github" / "workflows" / "pr-policy-enforcement.yml").read_text(encoding="utf-8")
        policy_pr = policy.split("  push:", 1)[0]
        for name in ("tests/test_pr_gate_scope.py", "tests/test_ci_feedback_optimization.py"):
            with self.subTest(name=name):
                self.assertNotIn(f"'{name}'", continuity_pr)
        self.assertIn("'tests/test_pr_gate_scope.py'", policy_pr)
        self.assertIn("'scripts/pr_gate_scope.py'", policy_pr)

    def test_unrelated_agent_ci_changes_never_trigger_storefront_qa(self):
        paths = event_paths("push")
        for file in ("AGENTS.md", "docs/knowledge/agent-rules.md",
                     ".github/workflows/mandatory-validation-gate.yml",
                     ".github/workflows/absolute-audit-governance.yml",
                     "scripts/ci_continuity_impact.py",
                     "tests/test_ci_continuity_impact.py"):
            with self.subTest(file=file):
                self.assertFalse(any(fnmatchcase(file, p) for p in paths))
                self.assertNotIn("ShopVivaliz QA", scope.required_specialized_gates([file]))


if __name__ == "__main__":
    unittest.main()
