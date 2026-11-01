"""Trusted PR review notification: security and behavior regression tests."""
from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "pr-conflict-auto-healer.yml"
HEAD = "a" * 40


def owner_script():
    source = WORKFLOW.read_text(encoding="utf-8")
    start = source.index("      - name: Request human owner review on every ready PR")
    block_start = source.index("        run: |", start)
    relevant = source[block_start:].splitlines()[1:]
    lines = []
    for line in relevant:
        if line.startswith("      - name: "):
            break
        if line.strip():
            assert line.startswith("          "), "unexpected indentation in trusted command"
            lines.append(line[10:])
        else:
            lines.append("")
    return "\n".join(lines) + "\n"


class ReviewVisibilityTests(unittest.TestCase):
    def test_trusted_event_and_limited_write_token(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("types: [opened, reopened, synchronize, ready_for_review]", text)
        preflight = text.split("  preflight:", 1)[1].split("  heal:", 1)[0]
        self.assertIn("pull-requests: write", preflight)
        self.assertIn("if: github.event_name == 'pull_request_target'", preflight)
        self.assertIn("pull-requests: read", text.split("  heal:", 1)[0])
        cmd = owner_script()
        self.assertIn('reviewers[]=shopvivaliz-human', cmd)
        self.assertIn('"APPROVED"', cmd)
        self.assertIn('"shopvivaliz-human"', cmd)
        self.assertNotIn("shopvivaliz-human_token", cmd)

    def run_case(self, *, draft=False, author="fredmourao-ai", current_requested=False,
                 approved=False, stale=False):
        with tempfile.TemporaryDirectory() as temp:
            tmp = Path(temp)
            (tmp / "gh").write_text('''#!/usr/bin/env python3
import json, os, sys
args = sys.argv[1:]
endpoint = next((x for x in args if x.startswith("repos/")), "")
if ("--method" in args or "-X" in args) and "requested_reviewers" in endpoint:
    with open(os.environ["CALLS"], "a", encoding="utf-8") as out:
        out.write("review_requested\\n")
    print("{}")
elif "requested_reviewers" in endpoint:
    print(os.environ["MOCK_REQUESTED"])
elif "/reviews?" in endpoint:
    print(os.environ["MOCK_REVIEWS"])
else:
    print(os.environ["MOCK_PR"])
''', encoding="utf-8")
            (tmp / "gh").chmod(0o755)
            target = tmp / "command.sh"
            target.write_text(owner_script(), encoding="utf-8")
            pr = {"state": "open", "draft": draft, "user": {"login": author},
                  "head": {"sha": "b"*40 if stale else HEAD}}
            env = dict(os.environ, PATH=f"{tmp}:{os.environ.get('PATH', '')}",
                       GITHUB_REPOSITORY="Vivaliz-site/site-shopvivaliz", PR_NUMBER="123",
                       EVENT_HEAD=HEAD, GH_TOKEN="fixture-do-not-use", CALLS=str(tmp/"calls"),
                       MOCK_PR=json.dumps(pr),
                       MOCK_REQUESTED=json.dumps({"users": [{"login": "shopvivaliz-human"}] if current_requested else []}),
                       MOCK_REVIEWS=json.dumps([{"user": {"login": "shopvivaliz-human"},
                                                 "state": "APPROVED", "commit_id": HEAD}] if approved else []))
            result = subprocess.run(["bash", str(target)], text=True, capture_output=True,
                                    env=env, timeout=10)
            recorded = (tmp / "calls").read_text() if (tmp / "calls").exists() else ""
            return result.returncode, result.stdout, recorded

    def test_new_ready_pr_requests_human_review_once(self):
        code, output, recorded = self.run_case()
        self.assertEqual(code, 0, output)
        self.assertEqual(recorded, "review_requested\n")

    def test_drafts_and_human_self_authored_not_requested(self):
        for kwargs in ({"draft": True}, {"author": "shopvivaliz-human"}, {"stale": True}):
            code, output, recorded = self.run_case(**kwargs)
            self.assertEqual(code, 0, output)
            self.assertEqual(recorded, "")

    def test_pending_request_or_current_approval_not_spammed(self):
        for kwargs in ({"current_requested": True}, {"approved": True}):
            code, output, recorded = self.run_case(**kwargs)
            self.assertEqual(code, 0, output)
            self.assertEqual(recorded, "")


if __name__ == "__main__":
    unittest.main()
