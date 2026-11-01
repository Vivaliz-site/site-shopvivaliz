"""Regression coverage for task-specific documentation preflight collection."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import importlib
worker = importlib.import_module("agent-operations-worker")


class TaskScopeDocsTests(unittest.TestCase):
    def setUp(self) -> None:
        self.task = {
            "id": "scope-docs-unit",
            "required_docs": ["docs/a.md", "docs/shared.md"],
            "scope_docs": ["docs/shared.md", "docs/b.md"],
        }

    def test_both_document_fields_are_combined_without_duplicates(self) -> None:
        self.assertEqual(
            worker.task_scope_docs(self.task),
            ["docs/a.md", "docs/shared.md", "docs/b.md"],
        )

    def test_preflight_verifies_combined_document_manifest(self) -> None:
        with patch.object(worker, "verify_receipt", return_value=(True, "verified", {})) as check:
            self.assertEqual(worker.docs_preflight("gpt", self.task), (True, "verified"))
        check.assert_called_once_with(
            "gpt", "scope-docs-unit", ["docs/a.md", "docs/shared.md", "docs/b.md"]
        )

    def test_read_command_includes_all_scope_documents(self) -> None:
        command = worker.docs_read_command("gpt", self.task)
        self.assertEqual(command.count(" --doc "), 3)
        for path in ("docs/a.md", "docs/shared.md", "docs/b.md"):
            self.assertIn(path, command)

    def test_string_fields_are_supported(self) -> None:
        task = {"required_docs": "docs/a.md", "scope_docs": "docs/b.md"}
        self.assertEqual(worker.task_scope_docs(task), ["docs/a.md", "docs/b.md"])

    def test_invalid_metadata_fails_closed_without_aborting_worker(self) -> None:
        task = {"id": "invalid-docs", "required_docs": {"invalid": True}}
        valid, reason = worker.docs_preflight("gpt", task)
        self.assertFalse(valid)
        self.assertIn("invalid task-specific documentation", reason)
        self.assertIn("TASK_SCOPE_DOCS_INVALID", worker.docs_read_command("gpt", task))

    def test_invalid_list_entry_is_not_silently_ignored(self) -> None:
        task = {"id": "invalid-entry", "scope_docs": ["docs/a.md", 7]}
        valid, reason = worker.docs_preflight("gpt", task)
        self.assertFalse(valid)
        self.assertIn("entries must be strings", reason)


if __name__ == "__main__":
    unittest.main()
