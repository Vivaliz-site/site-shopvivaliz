#!/usr/bin/env python3
"""Fair bounded selection for the existing PR completion enforcer.

Read-only selection prevents a stuck PR from starving every other PR.
"""
import argparse
import json
import sys

HELD_LABELS = {"blocked-by-runtime-lease", "blocked-external", "no-auto-merge"}


def select_prs(rows, repo, slot, batch):
    if not isinstance(rows, list) or not repo or slot < 0 or batch < 1 or batch > 5:
        raise ValueError("invalid PR selection inputs")
    eligible = []
    for row in rows:
        if not isinstance(row, dict) or row.get("draft") is not False:
            continue
        if row.get("state") not in (None, "open"):
            continue
        if (row.get("head") or {}).get("repo", {}).get("full_name") != repo:
            continue
        if (row.get("base") or {}).get("ref") != "main":
            continue
        labels = {x.get("name") for x in (row.get("labels") or []) if isinstance(x, dict)}
        if labels & HELD_LABELS:
            continue
        number = row.get("number")
        if type(number) is not int or number <= 0:
            continue
        eligible.append(number)
    eligible = sorted(set(eligible))
    if not eligible:
        return []
    offset = (slot * batch) % len(eligible)
    return [eligible[(offset + i) % len(eligible)] for i in range(min(batch, len(eligible)))]


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--repo", required=True)
    p.add_argument("--slot", required=True, type=int)
    p.add_argument("--batch", type=int, default=4)
    a = p.parse_args()
    try:
        result = select_prs(json.load(sys.stdin), a.repo, a.slot, a.batch)
    except (ValueError, TypeError, json.JSONDecodeError) as exc:
        print(f"PR_SCHEDULER_FAIL_CLOSED: {exc}", file=sys.stderr)
        return 2
    print(",".join(map(str, result)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
