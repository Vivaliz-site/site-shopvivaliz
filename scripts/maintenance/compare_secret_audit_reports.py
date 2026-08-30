#!/usr/bin/env python3
"""Block only new critical/high secret-audit findings relative to a PR base."""
from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path
from typing import Any

BLOCKING_SEVERITIES = frozenset({"critical", "high"})


def load_report(path: Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict) or not isinstance(payload.get("findings"), list):
        raise ValueError(f"invalid secret audit report: {path}")
    return payload


def finding_fingerprint(item: dict[str, Any]) -> tuple[str, str, str, str, str, bool]:
    return (
        str(item.get("severity") or ""),
        str(item.get("rule") or ""),
        str(item.get("path") or ""),
        str(item.get("message") or ""),
        str(item.get("excerpt") or ""),
        bool(item.get("active")),
    )


def blocking_counter(payload: dict[str, Any]) -> Counter[tuple[str, str, str, str, str, bool]]:
    return Counter(
        finding_fingerprint(item)
        for item in payload.get("findings", [])
        if isinstance(item, dict) and str(item.get("severity") or "") in BLOCKING_SEVERITIES
    )


def compare_reports(base_path: Path, head_path: Path) -> list[dict[str, Any]]:
    base = blocking_counter(load_report(base_path))
    head = blocking_counter(load_report(head_path))
    regressions = head - base
    result: list[dict[str, Any]] = []
    for fingerprint, count in sorted(regressions.items()):
        severity, rule, path, message, _excerpt, active = fingerprint
        for _ in range(count):
            result.append({
                "severity": severity,
                "rule": rule,
                "path": path,
                "message": message,
                "active": active,
            })
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-report", type=Path, required=True)
    parser.add_argument("--head-report", type=Path, required=True)
    args = parser.parse_args()

    regressions = compare_reports(args.base_report, args.head_report)
    print(json.dumps({
        "new_blocking_finding_count": len(regressions),
        "new_blocking_findings": regressions,
    }, ensure_ascii=False, indent=2))
    return 1 if regressions else 0


if __name__ == "__main__":
    raise SystemExit(main())
