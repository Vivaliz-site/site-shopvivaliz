from __future__ import annotations

import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = (
    Path(__file__).resolve().parents[2]
    / "scripts"
    / "governance"
    / "check-issue-comment-listeners.py"
)
SPEC = importlib.util.spec_from_file_location(
    "check_issue_comment_listeners", MODULE_PATH
)
assert SPEC and SPEC.loader
GUARD = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = GUARD
SPEC.loader.exec_module(GUARD)

DISPATCHER_WORKFLOW = """name: Issue Comment Dispatcher
on:
  issue_comment:
    types: [created]
jobs:
  dispatch:
    runs-on: ubuntu-latest
    steps:
      - run: echo dispatch
"""

DUPLICATE_LISTENER_WORKFLOW = """name: Rogue Listener
on:
  issue_comment:
    types: [created]
jobs:
  rogue:
    runs-on: ubuntu-latest
    steps:
      - run: echo rogue
"""

LIST_FORM_LISTENER_WORKFLOW = """name: List Form Listener
on: [push, issue_comment]
jobs:
  rogue:
    runs-on: ubuntu-latest
    steps:
      - run: echo rogue
"""

UNRELATED_WORKFLOW = """name: Unrelated
on:
  push:
    branches: [main]
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - run: echo build
"""

NESTED_MENTION_WORKFLOW = """name: Mentions issue_comment only in a job step, not as a trigger
on:
  push:
    branches: [main]
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - run: echo "not a trigger, just mentions issue_comment"
"""


class IssueCommentListenerGuardTests(unittest.TestCase):
    def write_workflows(self, directory: Path, files: dict[str, str]) -> Path:
        workflows_dir = directory / ".github" / "workflows"
        workflows_dir.mkdir(parents=True)
        for name, content in files.items():
            path = workflows_dir / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding="utf-8")
        return workflows_dir

    def test_exactly_one_listener_passes(self):
        with tempfile.TemporaryDirectory() as directory:
            workflows_dir = self.write_workflows(
                Path(directory),
                {
                    "issue-comment-dispatcher.yml": DISPATCHER_WORKFLOW,
                    "unrelated.yml": UNRELATED_WORKFLOW,
                    "nested-mention.yml": NESTED_MENTION_WORKFLOW,
                },
            )
            listeners = GUARD.find_issue_comment_listeners(workflows_dir)
            self.assertEqual([p.name for p in listeners], ["issue-comment-dispatcher.yml"])
            self.assertEqual(GUARD.main([str(workflows_dir)]), 0)

    def test_two_listeners_fail_with_count_two(self):
        with tempfile.TemporaryDirectory() as directory:
            workflows_dir = self.write_workflows(
                Path(directory),
                {
                    "issue-comment-dispatcher.yml": DISPATCHER_WORKFLOW,
                    "rogue-listener.yml": DUPLICATE_LISTENER_WORKFLOW,
                },
            )
            listeners = GUARD.find_issue_comment_listeners(workflows_dir)
            self.assertEqual(len(listeners), 2)
            self.assertEqual(GUARD.main([str(workflows_dir)]), 1)

    def test_list_form_trigger_is_detected(self):
        with tempfile.TemporaryDirectory() as directory:
            workflows_dir = self.write_workflows(
                Path(directory),
                {
                    "issue-comment-dispatcher.yml": DISPATCHER_WORKFLOW,
                    "list-form-listener.yml": LIST_FORM_LISTENER_WORKFLOW,
                },
            )
            listeners = GUARD.find_issue_comment_listeners(workflows_dir)
            self.assertEqual(len(listeners), 2)
            self.assertEqual(GUARD.main([str(workflows_dir)]), 1)

    def test_disabled_and_archive_files_are_ignored(self):
        with tempfile.TemporaryDirectory() as directory:
            workflows_dir = self.write_workflows(
                Path(directory),
                {
                    "issue-comment-dispatcher.yml": DISPATCHER_WORKFLOW,
                    "old-listener.yml.disabled": DUPLICATE_LISTENER_WORKFLOW,
                },
            )
            archive_dir = workflows_dir / "archive"
            archive_dir.mkdir()
            (archive_dir / "archived-listener.yml").write_text(
                DUPLICATE_LISTENER_WORKFLOW, encoding="utf-8"
            )

            listeners = GUARD.find_issue_comment_listeners(workflows_dir)
            self.assertEqual([p.name for p in listeners], ["issue-comment-dispatcher.yml"])
            self.assertEqual(GUARD.main([str(workflows_dir)]), 0)

    def test_real_repository_workflows_have_exactly_one_listener(self):
        real_workflows_dir = Path(__file__).resolve().parents[2] / ".github" / "workflows"
        listeners = GUARD.find_issue_comment_listeners(real_workflows_dir)
        self.assertEqual(
            [p.name for p in listeners],
            ["issue-comment-dispatcher.yml"],
        )


if __name__ == "__main__":
    unittest.main()
