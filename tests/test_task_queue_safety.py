#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import re
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
LIB_PATH = REPO_ROOT / "scripts" / "task_queue_lib.py"
CLI_PATH = REPO_ROOT / "scripts" / "manage-tasks-queue.py"


def load_library():
    spec = importlib.util.spec_from_file_location("task_queue_lib_under_test", LIB_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError("Unable to load task_queue_lib.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def canonical_queue() -> dict:
    return {
        "metadata": {
            "schema_version": 2,
            "allowed_states": [
                "pending",
                "running",
                "blocked",
                "failed",
                "completed_verified",
            ],
            "updated_at": "2026-08-06T00:00:00Z",
        },
        "tasks": [
            {
                "task_id": "AUDIT-001",
                "title": "Auditoria contínua",
                "description": "Preservar evidência",
                "priority": "high",
                "status": "blocked",
                "blocked_reason": "missing verified run",
                "schedule": "17 * * * *",
                "last_result": {
                    "success": False,
                    "reason": "no artifact",
                },
            }
        ],
    }


class TaskQueueLibraryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.lib = load_library()

    def test_load_preserves_all_evidence_fields(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "tasks-queue.json"
            payload = canonical_queue()
            path.write_text(json.dumps(payload), encoding="utf-8")

            loaded = self.lib.load_queue(path)

            task = loaded["tasks"][0]
            self.assertIs(loaded["tasks"], loaded["queue"])
            self.assertEqual(task["blocked_reason"], "missing verified run")
            self.assertEqual(task["schedule"], "17 * * * *")
            self.assertEqual(task["last_result"]["reason"], "no artifact")

    def test_legacy_queue_schema_is_rejected(self) -> None:
        with self.assertRaises(self.lib.QueueValidationError):
            self.lib.validate_queue({"version": "1.1", "queue": []})

    def test_runtime_legacy_queue_is_migrated_without_inferred_completion(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "tasks-queue.json"
            path.write_text(
                json.dumps({"queue": [{"task_id": "OLD-1", "status": "completed"}]}),
                encoding="utf-8",
            )
            with patch.dict("os.environ", {"SHOPVIVALIZ_RUNTIME_QUEUE_FILE": str(path)}):
                loaded = self.lib.load_queue(path)

            self.assertEqual(loaded["tasks"][0]["status"], "failed")
            self.assertFalse(loaded["tasks"][0]["last_result"]["success"])
            persisted = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual(persisted["metadata"]["schema_version"], 2)
            self.assertNotIn("queue", persisted)

    def test_unverified_completed_state_is_rejected(self) -> None:
        payload = canonical_queue()
        payload["tasks"][0]["status"] = "completed"
        with self.assertRaises(self.lib.QueueValidationError):
            self.lib.validate_queue(payload)

    def test_completed_verified_requires_full_evidence(self) -> None:
        payload = canonical_queue()
        task = payload["tasks"][0]
        task["status"] = "completed_verified"
        task["last_result"] = {"success": True}
        task["verification"] = {"run_id": "123"}
        with self.assertRaises(self.lib.QueueValidationError):
            self.lib.validate_queue(payload)

    def test_runtime_save_is_retired_and_does_not_change_file(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "tasks-queue.json"
            original = json.dumps(canonical_queue(), indent=2) + "\n"
            path.write_text(original, encoding="utf-8")
            loaded = self.lib.load_queue(path)
            loaded["tasks"][0]["priority"] = "low"

            with self.assertRaises(self.lib.QueueMutationRetiredError):
                self.lib.save_queue(loaded, path)

            self.assertEqual(path.read_text(encoding="utf-8"), original)

    def test_atomic_save_preserves_schema_and_does_not_write_legacy_mirror(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            path = root / "tasks-queue.json"
            path.write_text(json.dumps(canonical_queue()), encoding="utf-8")
            loaded = self.lib.load_queue(path)
            loaded["tasks"][0]["last_result"]["reason"] = "preserved after save"

            self.lib.save_queue(loaded, path, reviewed_change=True)

            saved = json.loads(path.read_text(encoding="utf-8"))
            self.assertNotIn("queue", saved)
            self.assertEqual(saved["metadata"]["schema_version"], 2)
            self.assertEqual(
                saved["tasks"][0]["last_result"]["reason"], "preserved after save"
            )
            self.assertFalse((root / "logs" / "tasks-queue.json").exists())
            self.assertEqual(list(root.glob(".tasks-queue.json.*.tmp")), [])

    def test_operational_scripts_cannot_opt_into_reviewed_runtime_write(self) -> None:
        pattern = re.compile(r"reviewed_change\s*=\s*True")
        offenders = []
        for path in (REPO_ROOT / "scripts").rglob("*.py"):
            if path == LIB_PATH:
                continue
            if pattern.search(path.read_text(encoding="utf-8", errors="replace")):
                offenders.append(path.relative_to(REPO_ROOT).as_posix())
        self.assertEqual(offenders, [])

    def test_repository_queue_matches_canonical_contract(self) -> None:
        self.lib.load_queue(REPO_ROOT / "tasks-queue.json")


class TaskQueueCliTests(unittest.TestCase):
    def make_fixture(self) -> Path:
        root = Path(tempfile.mkdtemp())
        scripts = root / "scripts"
        scripts.mkdir()
        shutil.copy2(LIB_PATH, scripts / "task_queue_lib.py")
        shutil.copy2(CLI_PATH, scripts / "manage-tasks-queue.py")
        (root / "tasks-queue.json").write_text(
            json.dumps(canonical_queue(), indent=2) + "\n", encoding="utf-8"
        )
        return root

    def run_cli(self, root: Path, *arguments: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["python3", "scripts/manage-tasks-queue.py", *arguments],
            cwd=root,
            text=True,
            capture_output=True,
            check=False,
        )

    def test_all_mutating_commands_fail_without_changing_queue(self) -> None:
        root = self.make_fixture()
        self.addCleanup(shutil.rmtree, root, True)
        queue_path = root / "tasks-queue.json"
        original = queue_path.read_bytes()
        commands = [
            ("add", "Nova", "Descrição"),
            ("remove", "AUDIT-001"),
            ("mark", "AUDIT-001", "--status", "completed"),
            ("priority", "AUDIT-001", "low"),
        ]

        for command in commands:
            with self.subTest(command=command):
                result = self.run_cli(root, *command)
                self.assertEqual(result.returncode, 2)
                self.assertIn("Mutation blocked", result.stderr)
                self.assertEqual(queue_path.read_bytes(), original)

    def test_list_and_stats_are_read_only(self) -> None:
        root = self.make_fixture()
        self.addCleanup(shutil.rmtree, root, True)
        queue_path = root / "tasks-queue.json"
        original = queue_path.read_bytes()

        listed = self.run_cli(root, "list", "--status", "blocked")
        stats = self.run_cli(root, "stats")

        self.assertEqual(listed.returncode, 0, listed.stderr)
        self.assertIn("AUDIT-001", listed.stdout)
        self.assertEqual(stats.returncode, 0, stats.stderr)
        self.assertIn("blocked: 1", stats.stdout)
        self.assertEqual(queue_path.read_bytes(), original)


class DocumentationContractTests(unittest.TestCase):
    def test_docs_do_not_advertise_retired_unsafe_flow(self) -> None:
        content = "\n".join(
            (REPO_ROOT / name).read_text(encoding="utf-8")
            for name in ("README.md", "AUTONOMOUS_TRIO_GUIDE.md")
        ).lower()
        forbidden = (
            "ai-autonomous-executor.yml",
            "nenhuma aprovação necessária",
            "commit + push + deploy automático",
            "mark task-002 --status completed",
            "sistema 100% operacional e autônomo",
        )
        for phrase in forbidden:
            with self.subTest(phrase=phrase):
                self.assertNotIn(phrase, content)


if __name__ == "__main__":
    unittest.main()
