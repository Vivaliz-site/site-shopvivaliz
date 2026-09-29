from __future__ import annotations

from datetime import datetime, timezone
import unittest

from scripts.task_continuity_actions_queue import classify_queued_run


class ActionsQueueClassificationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.now = datetime(2026, 9, 29, 1, 30, tzinfo=timezone.utc)
        self.repo = "Vivaliz-site/site-shopvivaliz"

    def classify(self, run, open_heads=None):
        return classify_queued_run(
            run,
            repository=self.repo,
            open_heads=set(open_heads or []),
            now=self.now,
            min_age_seconds=6 * 3600,
        )

    def test_old_closed_pr_run_is_stale(self):
        run = {
            "status": "queued",
            "event": "pull_request",
            "created_at": "2026-09-18T02:21:05Z",
            "head_branch": "fix/old",
            "head_repository": {"full_name": self.repo},
        }
        self.assertEqual("stale_closed_pr", self.classify(run))

    def test_old_open_pr_run_is_preserved(self):
        run = {
            "status": "queued",
            "event": "pull_request",
            "created_at": "2026-09-18T02:21:05Z",
            "head_branch": "fix/open",
            "head_repository": {"full_name": self.repo},
        }
        self.assertEqual("open_pr", self.classify(run, {(self.repo, "fix/open")}))

    def test_recent_pr_run_is_preserved(self):
        run = {
            "status": "queued",
            "event": "pull_request",
            "created_at": "2026-09-29T01:10:00Z",
            "head_branch": "fix/recent",
            "head_repository": {"full_name": self.repo},
        }
        self.assertEqual("recent", self.classify(run))

    def test_non_pr_queue_is_never_auto_cancelled(self):
        run = {
            "status": "queued",
            "event": "issue_comment",
            "created_at": "2026-09-01T00:00:00Z",
            "head_branch": "main",
            "head_repository": {"full_name": self.repo},
        }
        self.assertEqual("non_pr", self.classify(run))


if __name__ == "__main__":
    unittest.main()
