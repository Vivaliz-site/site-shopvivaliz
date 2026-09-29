#!/usr/bin/env python3
"""Ensure the newest ChatGPT freeze investigation generation remains durable."""

from __future__ import annotations

import argparse
import json
import os
import re
from pathlib import Path


def parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(description="Ensure a generational successor task exists")
    p.add_argument("--state-dir", required=True)
    p.add_argument("--predecessor", required=True)
    p.add_argument("--base-id", required=True)
    p.add_argument("--goal", required=True)
    p.add_argument("--next-action", required=True)
    p.add_argument("--evidence", required=True)
    p.add_argument("--agent", default="")
    p.add_argument("--repository", default="")
    return p


def summary(payload: dict) -> dict:
    return {
        "task_id": str(payload.get("task_id", "")),
        "predecessor_task_id": str(payload.get("predecessor_task_id", "")),
        "status": str(payload.get("status", "")),
    }


def main() -> int:
    args = parser().parse_args()
    state_dir = Path(args.state_dir).expanduser()
    os.environ["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = str(state_dir)

    import agent_task_state as state  # import only after runtime dir is fixed

    base = re.sub(r"[^A-Za-z0-9._-]+", "-", args.base_id.strip()).strip("-.")
    if not base:
        raise SystemExit("base-id is required")

    generations: list[tuple[int, Path]] = []
    if state_dir.is_dir():
        pattern = re.compile(re.escape(base) + r"-g([2-9][0-9]*)\.json$")
        for path in state_dir.glob(base + "-g*.json"):
            match = pattern.fullmatch(path.name)
            if match:
                generations.append((int(match.group(1)), path))
    generations.sort(key=lambda item: item[0])

    if generations:
        number, path = generations[-1]
        current = json.loads(path.read_text(encoding="utf-8"))
        current_status = str(current.get("status", "")).strip()
        if current_status != "CONCLUIDO":
            print(json.dumps(summary(current), sort_keys=True))
            return 0
        predecessor_id = str(current.get("task_id", "")).strip()
        next_number = number + 1
    else:
        predecessor_id = args.predecessor.strip()
        predecessor = state.load_task(predecessor_id)
        if str(predecessor.get("status", "")).strip() != "CONCLUIDO":
            raise SystemExit("initial predecessor must be CONCLUIDO")
        next_number = 2

    next_id = f"{base}-g{next_number}"
    try:
        created = state.start_successor_task(
            next_id,
            predecessor_task_id=predecessor_id,
            goal=args.goal,
            agent_id=args.agent,
            repository=args.repository,
        )
        created = state.record_progress(
            next_id,
            next_action=args.next_action,
            evidence=args.evidence,
        )
    except state.TaskStateError:
        path = state_dir / f"{next_id}.json"
        if not path.is_file():
            raise
        created = json.loads(path.read_text(encoding="utf-8"))

    print(json.dumps(summary(created), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
