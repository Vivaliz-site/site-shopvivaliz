#!/usr/bin/env python3
"""Fetch a complete bounded window of GitHub Actions runs without silent 1000-result truncation."""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

REPOSITORY_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")
SEARCH_CAP = 1000


def _parse_utc(value: str) -> datetime:
    text = str(value or "").strip()
    if not text:
        return datetime.now(timezone.utc).replace(microsecond=0)
    parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc).replace(microsecond=0)


def _fmt(value: datetime) -> str:
    return value.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _validate_pages(value: Any) -> list[dict[str, Any]]:
    if not isinstance(value, list):
        raise ValueError("gh api --slurp response must be a JSON array")
    pages: list[dict[str, Any]] = []
    for item in value:
        if not isinstance(item, dict):
            raise ValueError("workflow-run page must be a JSON object")
        runs = item.get("workflow_runs")
        if not isinstance(runs, list):
            raise ValueError("workflow-run page missing workflow_runs array")
        pages.append(item)
    if not pages:
        raise ValueError("workflow-run search returned no page object")
    return pages


def fetch_runs(
    *,
    repository: str,
    output: Path,
    window_hours: int,
    slice_minutes: int,
    end_time: datetime,
) -> dict[str, Any]:
    if not REPOSITORY_RE.fullmatch(repository):
        raise ValueError("repository must be in owner/name form")
    if window_hours <= 0:
        raise ValueError("window_hours must be positive")
    if slice_minutes <= 0:
        raise ValueError("slice_minutes must be positive")

    window_end = end_time.astimezone(timezone.utc).replace(microsecond=0)
    cursor = window_end - timedelta(hours=window_hours)
    slice_delta = timedelta(minutes=slice_minutes)
    pages_out: list[dict[str, Any]] = []
    search_count = 0
    reported_runs = 0

    while cursor < window_end:
        next_cursor = min(cursor + slice_delta, window_end)
        inclusive_end = next_cursor - timedelta(seconds=1)
        if inclusive_end < cursor:
            raise ValueError("slice window is too small to represent in whole seconds")
        created_range = f"{_fmt(cursor)}..{_fmt(inclusive_end)}"
        endpoint = (
            f"repos/{repository}/actions/runs"
            f"?created={created_range}&per_page=100"
        )
        completed = subprocess.run(
            ["gh", "api", "--paginate", "--slurp", endpoint],
            text=True,
            capture_output=True,
            check=False,
        )
        search_count += 1
        if completed.returncode != 0:
            message = completed.stderr.strip() or completed.stdout.strip() or "gh api failed"
            print(f"ci_performance_fetch_error={message}", file=sys.stderr)
            raise subprocess.CalledProcessError(
                completed.returncode,
                completed.args,
                output=completed.stdout,
                stderr=completed.stderr,
            )

        try:
            parsed = json.loads(completed.stdout)
            pages = _validate_pages(parsed)
        except (json.JSONDecodeError, ValueError) as exc:
            raise ValueError(f"invalid workflow-run search response: {exc}") from exc

        try:
            total_count = int(pages[0].get("total_count", 0))
        except (TypeError, ValueError) as exc:
            raise ValueError("workflow-run search total_count is invalid") from exc

        if total_count >= SEARCH_CAP:
            raise RuntimeError(
                "workflow-run search slice saturated at GitHub's 1000-result cap: "
                f"{created_range}; reduce --slice-minutes"
            )

        reported_runs += total_count
        pages_out.extend(pages)
        cursor = next_cursor

    output.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=f".{output.name}.", dir=str(output.parent))
    temp = Path(temp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(pages_out, handle, ensure_ascii=False, separators=(",", ":"))
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp, output)
    finally:
        temp.unlink(missing_ok=True)

    return {
        "ok": True,
        "repository": repository,
        "window_hours": window_hours,
        "slice_minutes": slice_minutes,
        "search_count": search_count,
        "reported_runs": reported_runs,
        "page_count": len(pages_out),
        "window_end": _fmt(window_end),
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Fetch GitHub Actions runs in bounded time slices to avoid the 1000-result search cap."
    )
    parser.add_argument("--repository", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--window-hours", type=int, default=24)
    parser.add_argument("--slice-minutes", type=int, default=60)
    parser.add_argument("--end-time", default="")
    args = parser.parse_args()

    try:
        result = fetch_runs(
            repository=args.repository,
            output=Path(args.output),
            window_hours=args.window_hours,
            slice_minutes=args.slice_minutes,
            end_time=_parse_utc(args.end_time),
        )
    except subprocess.CalledProcessError as exc:
        return int(exc.returncode or 1)
    except (OSError, ValueError, RuntimeError) as exc:
        print(f"ci_performance_fetch_error={exc}", file=sys.stderr)
        return 2

    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    import sys

    raise SystemExit(main())
