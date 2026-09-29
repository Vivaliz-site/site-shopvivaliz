#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"invalid task state: {path.name}") from exc
    if not isinstance(value, dict):
        raise SystemExit(f"invalid task state: {path.name}")
    return value


def run_state(state_script: Path, state_dir: Path, *args: str) -> dict[str, Any]:
    env = os.environ.copy()
    env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = str(state_dir)
    proc = subprocess.run(
        [sys.executable, str(state_script), *args],
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if proc.returncode != 0:
        raise SystemExit(f"agent_task_state failed rc={proc.returncode}")
    try:
        value = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise SystemExit("agent_task_state returned invalid json") from exc
    if not isinstance(value, dict):
        raise SystemExit("agent_task_state returned invalid payload")
    return value


def generation(path: Path, base_id: str) -> int | None:
    match = re.fullmatch(re.escape(base_id) + r"-g([2-9][0-9]*)\.json", path.name)
    return int(match.group(1)) if match else None


def summarize(payload: dict[str, Any]) -> dict[str, str]:
    return {
        "task_id": str(payload.get("task_id", "")).strip(),
        "status": str(payload.get("status", "")).strip(),
        "predecessor_task_id": str(payload.get("predecessor_task_id", "")).strip(),
    }


def ensure_successor(
    *,
    state_dir: Path,
    state_script: Path,
    predecessor_id: str,
    base_id: str,
    goal: str,
    next_action: str,
    evidence: str,
    agent: str,
) -> dict[str, str]:
    if not state_dir.is_dir():
        raise SystemExit("task state directory is missing")
    if not state_script.is_file():
        raise SystemExit("agent_task_state script is missing")

    predecessor = load_json(state_dir / f"{predecessor_id}.json")

    generations: list[tuple[int, Path, dict[str, Any]]] = []
    for path in state_dir.glob(f"{base_id}-g*.json"):
        number = generation(path, base_id)
        if number is None:
            continue
        generations.append((number, path, load_json(path)))
    generations.sort(key=lambda row: row[0])

    if generations:
        number, _, latest = generations[-1]
        status = str(latest.get("status", "")).strip()
        if status in {"RUNNING", "READY_TO_COMPLETE"}:
            return summarize(latest)
        if status == "BLOCKED_EXTERNAL":
            raise SystemExit("latest generation is BLOCKED_EXTERNAL and cannot be resumed implicitly")
        if status != "CONCLUIDO":
            raise SystemExit(f"unsupported successor state: {status}")
        predecessor_id = str(latest.get("task_id", "")).strip()
        predecessor = latest
        next_generation = number + 1
    else:
        next_generation = 2

    if str(predecessor.get("status", "")).strip() != "CONCLUIDO":
        raise SystemExit("latest predecessor is not CONCLUIDO")

    task_id = f"{base_id}-g{next_generation}"
    created = run_state(
        state_script,
        state_dir,
        "successor",
        "--task",
        task_id,
        "--predecessor",
        predecessor_id,
        "--goal",
        goal,
        "--agent",
        agent,
    )
    progressed = run_state(
        state_script,
        state_dir,
        "progress",
        "--task",
        task_id,
        "--next-action",
        next_action,
        "--evidence",
        evidence,
    )
    if str(created.get("task_id", "")).strip() != str(progressed.get("task_id", "")).strip():
        raise SystemExit("successor progress target mismatch")
    return summarize(progressed)


def main() -> int:
    parser = argparse.ArgumentParser(description="Ensure the next durable ChatGPT freeze generation.")
    parser.add_argument("--state-dir", required=True)
    parser.add_argument("--state-script", required=True)
    parser.add_argument("--predecessor", required=True)
    parser.add_argument("--base-id", required=True)
    parser.add_argument("--goal", required=True)
    parser.add_argument("--next-action", required=True)
    parser.add_argument("--evidence", required=True)
    parser.add_argument("--agent", default="gpt")
    args = parser.parse_args()

    result = ensure_successor(
        state_dir=Path(args.state_dir),
        state_script=Path(args.state_script),
        predecessor_id=args.predecessor,
        base_id=args.base_id,
        goal=args.goal,
        next_action=args.next_action,
        evidence=args.evidence,
        agent=args.agent,
    )
    if result["status"] not in {"RUNNING", "READY_TO_COMPLETE"}:
        raise SystemExit("successor is not resumable")
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
