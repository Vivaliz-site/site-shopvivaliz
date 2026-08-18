from __future__ import annotations

import importlib.util
import json
import os
import stat
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "git-auto-sync.py"
SPEC = importlib.util.spec_from_file_location("git_auto_sync", MODULE_PATH)
assert SPEC and SPEC.loader
SYNC = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = SYNC
SPEC.loader.exec_module(SYNC)


def git(cwd: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env.update(
        {
            "GIT_AUTHOR_NAME": "ShopVivaliz Test",
            "GIT_AUTHOR_EMAIL": "test@shopvivaliz.invalid",
            "GIT_COMMITTER_NAME": "ShopVivaliz Test",
            "GIT_COMMITTER_EMAIL": "test@shopvivaliz.invalid",
        }
    )
    return subprocess.run(
        ["git", *args],
        cwd=cwd,
        check=check,
        capture_output=True,
        text=True,
        env=env,
    )


def init_repo(path: Path) -> None:
    path.mkdir(parents=True)
    git(path, "init", "-b", "main")
    git(path, "config", "user.name", "ShopVivaliz Test")
    git(path, "config", "user.email", "test@shopvivaliz.invalid")
    (path / ".gitignore").write_text("logs/\n", encoding="utf-8")
    git(path, "add", ".gitignore")


def commit_file(repo: Path, relative: str, content: str, message: str) -> str:
    path = repo / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    git(repo, "add", relative)
    git(repo, "commit", "-m", message)
    return git(repo, "rev-parse", "HEAD").stdout.strip()


def remove_tree(path: Path) -> None:
    def clear_readonly(func, target, _exc_info):
        os.chmod(target, stat.S_IWRITE)
        func(target)

    shutil.rmtree(path, onerror=clear_readonly)


class SanitizedHistorySyncTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        base = Path(self.tmp.name)
        self.local = base / "local"
        self.remote_work = base / "remote-work"
        self.remote_bare = base / "remote.git"

        init_repo(self.local)
        self.old_sha = commit_file(
            self.local,
            "legacy.txt",
            "legacy reachable history\n",
            "legacy root",
        )

        init_repo(self.remote_work)
        self.clean_root = commit_file(
            self.remote_work,
            "application.txt",
            "sanitized current tree\n",
            "sanitized root",
        )
        marker = {
            "schema_version": 1,
            "root_sha": self.clean_root,
            "reason": "test sanitized history",
            "expected_tag": "history-cleanup-test",
        }
        self.remote_tip = commit_file(
            self.remote_work,
            ".security/sanitized-history.json",
            json.dumps(marker, indent=2) + "\n",
            "add sanitized history marker",
        )

        self.remote_bare.mkdir()
        git(self.remote_bare, "init", "--bare", "-b", "main")
        git(self.remote_work, "remote", "add", "origin", str(self.remote_bare))
        git(self.remote_work, "push", "-u", "origin", "main")
        git(self.local, "remote", "add", "origin", str(self.remote_bare))

        SYNC.REPO_DIR = self.local
        SYNC.STATUS_FILE = self.local / "logs" / "tri-environment-sync.json"
        SYNC.DEFAULT_BRANCH = "main"
        self.original_check_local_health = SYNC.check_local_health
        SYNC.check_local_health = lambda: {
            "ok": True,
            "status": 200,
            "data": {"health_score_percent": 100.0, "queue": None},
        }

    def tearDown(self) -> None:
        SYNC.check_local_health = self.original_check_local_health
        try:
            self.tmp.cleanup()
        except PermissionError:
            remove_tree(Path(self.tmp.name))

    def status(self) -> dict[str, object]:
        return json.loads(SYNC.STATUS_FILE.read_text(encoding="utf-8"))

    def clone_remote_into_local(self) -> None:
        remove_tree(self.local)
        git(Path(self.tmp.name), "clone", str(self.remote_bare), str(self.local))
        SYNC.REPO_DIR = self.local
        SYNC.STATUS_FILE = self.local / "logs" / "tri-environment-sync.json"

    def test_realigns_diverged_clean_clone_only_for_verified_sanitized_root(self) -> None:
        result = SYNC.main()

        self.assertEqual(result, 0)
        self.assertEqual(git(self.local, "branch", "--show-current").stdout.strip(), "main")
        self.assertEqual(git(self.local, "rev-parse", "HEAD").stdout.strip(), self.remote_tip)
        self.assertEqual(
            git(self.local, "rev-list", "--max-parents=0", "HEAD").stdout.strip(),
            self.clean_root,
        )
        report = self.status()
        self.assertTrue(report["ok"])
        self.assertEqual(
            report["action"],
            "realigned-to-verified-sanitized-history",
        )
        self.assertEqual(report["sanitized_root_sha"], self.clean_root)
        self.assertEqual(report["health"]["status"], "ok")

    def test_blocks_unrelated_diverged_history_without_marker(self) -> None:
        git(self.remote_work, "switch", "--orphan", "unsafe-history")
        for child in self.remote_work.iterdir():
            if child.name == ".git":
                continue
            if child.is_dir():
                shutil.rmtree(child)
            else:
                child.unlink()
        unsafe_tip = commit_file(
            self.remote_work,
            "unsafe.txt",
            "unverified replacement\n",
            "unverified root",
        )
        git(self.remote_work, "branch", "-M", "main")
        git(self.remote_work, "push", "--force", "origin", "main")

        result = SYNC.main()

        self.assertEqual(result, 4)
        self.assertEqual(git(self.local, "rev-parse", "HEAD").stdout.strip(), self.old_sha)
        self.assertNotEqual(unsafe_tip, self.old_sha)
        report = self.status()
        self.assertFalse(report["ok"])
        self.assertEqual(report["action"], "blocked-diverged-history")

    def test_keeps_normal_fast_forward_path(self) -> None:
        self.clone_remote_into_local()
        git(self.local, "switch", "--detach", self.clean_root)
        git(self.local, "branch", "--force", "main", self.clean_root)
        git(self.local, "switch", "main")

        result = SYNC.main()

        self.assertEqual(result, 0)
        self.assertEqual(git(self.local, "rev-parse", "HEAD").stdout.strip(), self.remote_tip)
        self.assertEqual(self.status()["action"], "fast-forward-to-canonical")

    def test_recovers_clean_legacy_agent_branch_if_head_is_already_in_main(self) -> None:
        self.clone_remote_into_local()
        legacy_branch = "patch/agente-shopvivaliz-"
        git(self.local, "switch", "-c", legacy_branch, self.clean_root)

        result = SYNC.main()

        self.assertEqual(result, 0)
        self.assertEqual(git(self.local, "branch", "--show-current").stdout.strip(), "main")
        self.assertEqual(git(self.local, "rev-parse", "HEAD").stdout.strip(), self.remote_tip)
        report = self.status()
        self.assertTrue(report["ok"])
        self.assertEqual(report["action"], "noop")
        self.assertEqual(report["legacy_branch_recovery"]["from_branch"], legacy_branch)
        self.assertEqual(report["legacy_branch_recovery"]["from_sha"], self.clean_root)
        self.assertEqual(report["legacy_branch_recovery"]["status"], "switched-to-canonical")

    def test_blocks_legacy_agent_branch_with_unique_commits(self) -> None:
        self.clone_remote_into_local()
        legacy_branch = "patch/agente-shopvivaliz-"
        git(self.local, "switch", "-c", legacy_branch, self.clean_root)
        unique_sha = commit_file(
            self.local,
            "agent-only.txt",
            "must not be discarded\n",
            "agent-only work",
        )

        result = SYNC.main()

        self.assertEqual(result, 6)
        self.assertEqual(git(self.local, "branch", "--show-current").stdout.strip(), legacy_branch)
        self.assertEqual(git(self.local, "rev-parse", "HEAD").stdout.strip(), unique_sha)
        report = self.status()
        self.assertFalse(report["ok"])
        self.assertEqual(report["action"], "blocked-legacy-branch-with-unique-commits")

    def test_blocks_unknown_wrong_branch(self) -> None:
        self.clone_remote_into_local()
        git(self.local, "switch", "-c", "feature/manual-review", self.clean_root)

        result = SYNC.main()

        self.assertEqual(result, 2)
        self.assertEqual(
            git(self.local, "branch", "--show-current").stdout.strip(),
            "feature/manual-review",
        )
        self.assertEqual(self.status()["action"], "blocked-wrong-branch")

    def test_degraded_health_is_recorded_without_blocking_recovery_sync(self) -> None:
        SYNC.check_local_health = lambda: {
            "ok": False,
            "error": "test runtime unavailable",
        }

        result = SYNC.main()

        self.assertEqual(result, 0)
        self.assertEqual(git(self.local, "rev-parse", "HEAD").stdout.strip(), self.remote_tip)
        report = self.status()
        self.assertTrue(report["ok"])
        self.assertEqual(report["health"]["status"], "degraded")

    def test_degraded_health_does_not_block_safe_legacy_recovery(self) -> None:
        self.clone_remote_into_local()
        legacy_branch = "patch/agente-shopvivaliz-"
        git(self.local, "switch", "-c", legacy_branch, self.clean_root)
        SYNC.check_local_health = lambda: {
            "ok": False,
            "error": "test runtime unavailable",
        }

        result = SYNC.main()

        self.assertEqual(result, 0)
        self.assertEqual(git(self.local, "branch", "--show-current").stdout.strip(), "main")
        self.assertEqual(self.status()["health"]["status"], "degraded")

    def test_fetch_retries_only_the_transient_remote_ref_lock(self) -> None:
        command = ["git", "fetch", "--prune", "--no-tags", "origin", "main"]
        transient = subprocess.CompletedProcess(
            command,
            1,
            "",
            "error: cannot lock ref 'refs/remotes/origin/main': is at new but expected old",
        )
        success = subprocess.CompletedProcess(command, 0, "", "")
        with patch.object(SYNC, "run", side_effect=[transient, success]) as run_mock, patch.object(
            SYNC.time, "sleep"
        ) as sleep_mock:
            SYNC.fetch_canonical_branch("main")

        self.assertEqual(run_mock.call_count, 2)
        sleep_mock.assert_called_once_with(1)

    def test_fetch_does_not_retry_an_unknown_failure(self) -> None:
        command = ["git", "fetch", "--prune", "--no-tags", "origin", "main"]
        failure = subprocess.CompletedProcess(command, 1, "", "fatal: authentication failed")
        with patch.object(SYNC, "run", return_value=failure) as run_mock, patch.object(
            SYNC.time, "sleep"
        ) as sleep_mock:
            with self.assertRaisesRegex(RuntimeError, "authentication failed"):
                SYNC.fetch_canonical_branch("main")

        self.assertEqual(run_mock.call_count, 1)
        sleep_mock.assert_not_called()

    def test_oracle_wrapper_does_not_stage_commit_or_push(self) -> None:
        text = (ROOT / "scripts" / "auto-sync-oracle.sh").read_text(encoding="utf-8")
        self.assertIn("safe-repo-sync.sh", text)
        self.assertNotIn("git add", text)
        self.assertNotIn("git commit", text)
        self.assertNotIn("git push", text)
        self.assertNotIn("gh pr create", text)

    def test_sync_runner_does_not_use_destructive_checkout_shortcuts(self) -> None:
        text = MODULE_PATH.read_text(encoding="utf-8")
        self.assertNotIn("reset --hard", text)
        self.assertNotIn("git clean", text)
        self.assertNotIn("git stash", text)
        self.assertNotIn("git push", text)


if __name__ == "__main__":
    unittest.main()
