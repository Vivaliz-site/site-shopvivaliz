#!/usr/bin/env python3
"""Aggregate GitHub Actions run performance and detect CI regressions."""

from __future__ import annotations

import argparse
import json
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

FAILURE_CONCLUSIONS = {"failure", "timed_out", "startup_failure", "action_required"}
EXCLUDED_CONCLUSIONS = {"cancelled", "skipped"}


def _parse_time(value: Any) -> datetime | None:
    text = str(value or "").strip()
    if not text:
        return None
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00"))
    except ValueError:
        return None


def _flatten_runs(payload: Any) -> list[dict[str, Any]]:
    if isinstance(payload, dict):
        rows = payload.get("workflow_runs")
        return [row for row in rows if isinstance(row, dict)] if isinstance(rows, list) else []
    if isinstance(payload, list):
        flattened: list[dict[str, Any]] = []
        for page in payload:
            if isinstance(page, dict):
                rows = page.get("workflow_runs")
                if isinstance(rows, list):
                    flattened.extend(row for row in rows if isinstance(row, dict))
            elif isinstance(page, list):
                flattened.extend(row for row in page if isinstance(row, dict))
        return flattened
    return []


def analyze_runs(
    payload: Any,
    *,
    window_hours: int = 24,
    min_samples: int = 3,
    avg_seconds_threshold: float = 150.0,
    failure_rate_threshold: float = 0.20,
) -> dict[str, Any]:
    grouped: dict[str, dict[str, Any]] = defaultdict(
        lambda: {
            "total_runs": 0,
            "terminal_runs": 0,
            "excluded_runs": 0,
            "failures": 0,
            "action_required": 0,
            "duration_samples": 0,
            "total_duration_seconds": 0.0,
        }
    )

    for run in _flatten_runs(payload):
        name = str(run.get("name") or run.get("workflow_name") or "Unnamed workflow").strip()
        row = grouped[name]
        row["total_runs"] += 1

        conclusion = str(run.get("conclusion") or "").strip().lower()
        if conclusion in EXCLUDED_CONCLUSIONS:
            row["excluded_runs"] += 1
        elif conclusion:
            row["terminal_runs"] += 1
            if conclusion in FAILURE_CONCLUSIONS:
                row["failures"] += 1
            if conclusion == "action_required":
                row["action_required"] += 1

        status = str(run.get("status") or "").strip().lower()
        started = _parse_time(run.get("run_started_at") or run.get("created_at"))
        ended = _parse_time(run.get("updated_at"))
        if status == "completed" and started is not None and ended is not None and ended >= started:
            duration = (ended - started).total_seconds()
            row["duration_samples"] += 1
            row["total_duration_seconds"] += duration

    workflows: list[dict[str, Any]] = []
    alerts: list[dict[str, Any]] = []

    for name, raw in grouped.items():
        duration_samples = int(raw["duration_samples"])
        terminal_runs = int(raw["terminal_runs"])
        average = (
            float(raw["total_duration_seconds"]) / duration_samples
            if duration_samples
            else 0.0
        )
        failure_rate = (
            float(raw["failures"]) / terminal_runs
            if terminal_runs
            else 0.0
        )
        reasons: list[str] = []
        if int(raw["action_required"]) > 0:
            reasons.append("action_required")
        if duration_samples >= min_samples and average > avg_seconds_threshold:
            reasons.append("average_duration")
        if terminal_runs >= min_samples and failure_rate > failure_rate_threshold:
            reasons.append("failure_rate")

        row = {
            "workflow": name,
            "total_runs": int(raw["total_runs"]),
            "terminal_runs": terminal_runs,
            "excluded_runs": int(raw["excluded_runs"]),
            "failures": int(raw["failures"]),
            "action_required": int(raw["action_required"]),
            "duration_samples": duration_samples,
            "average_duration_seconds": round(average, 2),
            "total_duration_seconds": round(float(raw["total_duration_seconds"]), 2),
            "failure_rate": round(failure_rate, 4),
            "alert_reasons": reasons,
        }
        workflows.append(row)
        if reasons:
            alerts.append(
                {
                    "workflow": name,
                    "reasons": reasons,
                    "average_duration_seconds": row["average_duration_seconds"],
                    "failure_rate": row["failure_rate"],
                    "action_required": row["action_required"],
                    "terminal_runs": row["terminal_runs"],
                }
            )

    workflows.sort(
        key=lambda item: (
            -float(item["total_duration_seconds"]),
            -int(item["total_runs"]),
            str(item["workflow"]).lower(),
        )
    )
    alert_order = {str(row["workflow"]): index for index, row in enumerate(workflows)}
    alerts.sort(key=lambda item: alert_order.get(str(item["workflow"]), 10**9))

    return {
        "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "window_hours": int(window_hours),
        "thresholds": {
            "min_samples": int(min_samples),
            "average_duration_seconds": float(avg_seconds_threshold),
            "failure_rate": float(failure_rate_threshold),
            "action_required_any": True,
        },
        "run_count": sum(int(row["total_runs"]) for row in workflows),
        "workflow_count": len(workflows),
        "regression": bool(alerts),
        "alerts": alerts,
        "workflows": workflows,
    }


def _pct(value: float) -> str:
    return f"{value * 100:g}%"


def _cell(value: Any) -> str:
    return str(value).replace("|", "\\|").replace("\n", " ")


def render_markdown(report: dict[str, Any]) -> str:
    status = "REGRESSION" if report.get("regression") else "HEALTHY"
    thresholds = report.get("thresholds") or {}
    avg_threshold = float(thresholds.get("average_duration_seconds", 150.0))
    failure_threshold = float(thresholds.get("failure_rate", 0.20))
    min_samples = int(thresholds.get("min_samples", 3))

    lines = [
        "# CI Performance Monitor",
        "",
        f"**Status:** {status}",
        "",
        (
            f"Window: {int(report.get('window_hours', 24))}h · "
            f"alert when avg > {avg_threshold:g}s or failure > {_pct(failure_threshold)} "
            f"with >= {min_samples} samples; any action_required alerts immediately."
        ),
        "",
        "| Workflow | Runs | Avg (s) | Total (s) | Failure | Excluded | action_required | Alerts |",
        "|---|---:|---:|---:|---:|---:|---:|---|",
    ]
    for row in report.get("workflows") or []:
        reasons = ", ".join(row.get("alert_reasons") or []) or "-"
        lines.append(
            "| "
            + " | ".join(
                [
                    _cell(row.get("workflow", "")),
                    str(row.get("terminal_runs", 0)),
                    f"{float(row.get('average_duration_seconds', 0.0)):.1f}",
                    f"{float(row.get('total_duration_seconds', 0.0)):.1f}",
                    _pct(float(row.get("failure_rate", 0.0))),
                    str(row.get("excluded_runs", 0)),
                    str(row.get("action_required", 0)),
                    _cell(reasons),
                ]
            )
            + " |"
        )

    if report.get("alerts"):
        lines.extend(["", "## Alerts", ""])
        for alert in report["alerts"]:
            lines.append(
                f"- **{_cell(alert['workflow'])}**: "
                + ", ".join(str(reason) for reason in alert.get("reasons") or [])
            )
    return "\n".join(lines) + "\n"


def _load(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def main() -> int:
    parser = argparse.ArgumentParser(description="Aggregate recent GitHub Actions workflow performance.")
    parser.add_argument("--input", required=True)
    parser.add_argument("--output-json", required=True)
    parser.add_argument("--output-md", required=True)
    parser.add_argument("--window-hours", type=int, default=24)
    parser.add_argument("--min-samples", type=int, default=3)
    parser.add_argument("--avg-seconds-threshold", type=float, default=150.0)
    parser.add_argument("--failure-rate-threshold", type=float, default=0.20)
    args = parser.parse_args()

    report = analyze_runs(
        _load(Path(args.input)),
        window_hours=args.window_hours,
        min_samples=args.min_samples,
        avg_seconds_threshold=args.avg_seconds_threshold,
        failure_rate_threshold=args.failure_rate_threshold,
    )
    json_path = Path(args.output_json)
    md_path = Path(args.output_md)
    json_path.parent.mkdir(parents=True, exist_ok=True)
    md_path.parent.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    md_path.write_text(render_markdown(report), encoding="utf-8")
    print(
        json.dumps(
            {
                "ok": True,
                "regression": report["regression"],
                "workflow_count": report["workflow_count"],
                "run_count": report["run_count"],
                "alert_count": len(report["alerts"]),
            },
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
