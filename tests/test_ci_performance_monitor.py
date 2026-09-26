from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "ci_performance_monitor.py"


def load_module():
    if not MODULE_PATH.is_file():
        raise AssertionError("scripts/ci_performance_monitor.py is missing")
    spec = importlib.util.spec_from_file_location("ci_performance_monitor_test", MODULE_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load CI performance monitor")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def run(
    run_id: int,
    workflow: str,
    conclusion: str,
    *,
    start: str = "2026-09-26T10:00:00Z",
    end: str = "2026-09-26T10:01:00Z",
) -> dict:
    return {
        "id": run_id,
        "name": workflow,
        "status": "completed",
        "conclusion": conclusion,
        "run_started_at": start,
        "updated_at": end,
        "html_url": f"https://example.invalid/runs/{run_id}",
    }


class CiPerformanceMonitorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.mod = load_module()

    def analyze(self, payload):
        return self.mod.analyze_runs(
            payload,
            window_hours=24,
            min_samples=3,
            avg_seconds_threshold=150.0,
            failure_rate_threshold=0.20,
        )

    def test_healthy_workflow_does_not_alert(self) -> None:
        payload = {"workflow_runs": [
            run(1, "Fast Gate", "success"),
            run(2, "Fast Gate", "success"),
            run(3, "Fast Gate", "success"),
        ]}
        report = self.analyze(payload)
        self.assertFalse(report["regression"])
        self.assertEqual(report["alerts"], [])
        row = report["workflows"][0]
        self.assertEqual(row["workflow"], "Fast Gate")
        self.assertEqual(row["terminal_runs"], 3)
        self.assertEqual(row["failure_rate"], 0.0)
        self.assertEqual(row["average_duration_seconds"], 60.0)

    def test_slow_average_above_150_seconds_alerts_after_three_samples(self) -> None:
        payload = {"workflow_runs": [
            run(1, "Slow Gate", "success", end="2026-09-26T10:03:00Z"),
            run(2, "Slow Gate", "success", end="2026-09-26T10:03:00Z"),
            run(3, "Slow Gate", "success", end="2026-09-26T10:03:00Z"),
        ]}
        report = self.analyze(payload)
        self.assertTrue(report["regression"])
        self.assertEqual(report["alerts"][0]["workflow"], "Slow Gate")
        self.assertIn("average_duration", report["alerts"][0]["reasons"])

    def test_failure_rate_above_twenty_percent_alerts(self) -> None:
        payload = {"workflow_runs": [
            run(1, "Flaky Gate", "success"),
            run(2, "Flaky Gate", "success"),
            run(3, "Flaky Gate", "success"),
            run(4, "Flaky Gate", "failure"),
        ]}
        report = self.analyze(payload)
        row = report["workflows"][0]
        self.assertEqual(row["failure_rate"], 0.25)
        self.assertIn("failure_rate", report["alerts"][0]["reasons"])

    def test_cancelled_and_skipped_are_excluded_from_failure_denominator(self) -> None:
        payload = {"workflow_runs": [
            run(1, "Mixed Gate", "success"),
            run(2, "Mixed Gate", "success"),
            run(3, "Mixed Gate", "success"),
            run(4, "Mixed Gate", "cancelled"),
            run(5, "Mixed Gate", "skipped"),
        ]}
        report = self.analyze(payload)
        row = report["workflows"][0]
        self.assertEqual(row["terminal_runs"], 3)
        self.assertEqual(row["excluded_runs"], 2)
        self.assertEqual(row["failure_rate"], 0.0)
        self.assertFalse(report["regression"])

    def test_action_required_alerts_even_with_single_sample(self) -> None:
        report = self.analyze({"workflow_runs": [run(1, "Policy Gate", "action_required")]})
        self.assertTrue(report["regression"])
        self.assertEqual(report["alerts"][0]["reasons"], ["action_required"])

    def test_below_minimum_sample_reports_but_does_not_alert_on_speed_or_failures(self) -> None:
        payload = {"workflow_runs": [
            run(1, "Sparse Gate", "failure", end="2026-09-26T10:05:00Z"),
            run(2, "Sparse Gate", "success", end="2026-09-26T10:05:00Z"),
        ]}
        report = self.analyze(payload)
        self.assertFalse(report["regression"])
        self.assertEqual(report["workflows"][0]["terminal_runs"], 2)
        self.assertEqual(report["workflows"][0]["average_duration_seconds"], 300.0)
        self.assertEqual(report["workflows"][0]["failure_rate"], 0.5)

    def test_in_progress_run_does_not_contribute_partial_duration(self) -> None:
        active = run(4, "Fast Gate", "", end="2026-09-26T10:10:00Z")
        active["status"] = "in_progress"
        active["conclusion"] = None
        payload = {"workflow_runs": [
            run(1, "Fast Gate", "success"),
            run(2, "Fast Gate", "success"),
            run(3, "Fast Gate", "success"),
            active,
        ]}
        report = self.analyze(payload)
        row = report["workflows"][0]
        self.assertEqual(row["total_runs"], 4)
        self.assertEqual(row["terminal_runs"], 3)
        self.assertEqual(row["duration_samples"], 3)
        self.assertEqual(row["average_duration_seconds"], 60.0)
        self.assertFalse(report["regression"])

    def test_missing_timestamps_do_not_corrupt_duration_metrics(self) -> None:
        first = run(1, "Partial Time", "success")
        second = run(2, "Partial Time", "success")
        third = run(3, "Partial Time", "success")
        second["run_started_at"] = None
        third["updated_at"] = None
        report = self.analyze({"workflow_runs": [first, second, third]})
        row = report["workflows"][0]
        self.assertEqual(row["terminal_runs"], 3)
        self.assertEqual(row["duration_samples"], 1)
        self.assertEqual(row["average_duration_seconds"], 60.0)
        self.assertFalse(report["regression"])

    def test_duplicate_run_ids_are_counted_once(self) -> None:
        duplicate = run(1, "Dedup Gate", "success")
        payload = [
            {"workflow_runs": [
                duplicate,
                run(2, "Dedup Gate", "success"),
            ]},
            {"workflow_runs": [
                dict(duplicate),
                run(3, "Dedup Gate", "success"),
            ]},
        ]
        report = self.analyze(payload)
        row = report["workflows"][0]
        self.assertEqual(row["total_runs"], 3)
        self.assertEqual(row["terminal_runs"], 3)
        self.assertEqual(row["duration_samples"], 3)
        self.assertEqual(row["total_duration_seconds"], 180.0)

    def test_slurped_pages_are_flattened_and_ranked_by_total_duration(self) -> None:
        payload = [
            {"workflow_runs": [
                run(1, "Short Total", "success", end="2026-09-26T10:01:00Z"),
                run(2, "Long Total", "success", end="2026-09-26T10:02:00Z"),
            ]},
            {"workflow_runs": [
                run(3, "Long Total", "success", end="2026-09-26T10:02:00Z"),
            ]},
        ]
        report = self.analyze(payload)
        self.assertEqual([row["workflow"] for row in report["workflows"]], ["Long Total", "Short Total"])
        self.assertEqual(report["workflows"][0]["total_duration_seconds"], 240.0)

    def test_markdown_contains_thresholds_and_alert_state(self) -> None:
        report = self.analyze({"workflow_runs": [
            run(1, "Fast Gate", "success"),
            run(2, "Fast Gate", "success"),
            run(3, "Fast Gate", "success"),
        ]})
        text = self.mod.render_markdown(report)
        self.assertIn("CI Performance Monitor", text)
        self.assertIn("150", text)
        self.assertIn("20%", text)
        self.assertIn("Fast Gate", text)
        self.assertIn("HEALTHY", text)


if __name__ == "__main__":
    unittest.main()
