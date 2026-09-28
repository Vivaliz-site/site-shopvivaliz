#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import shutil
import tempfile
from datetime import datetime, timezone
from pathlib import Path

TASK_ID = "global-continuity-cleanup-v2-20260927"
FALSE_VERIFICATION = "Local workspace and task continuity state successfully persisted and validated."


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def repair(path: Path) -> Path:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if payload.get("task_id") != TASK_ID:
        raise SystemExit("unexpected task id")
    if payload.get("status") != "CONCLUIDO":
        raise SystemExit("unexpected task status")
    if payload.get("verification") != FALSE_VERIFICATION:
        raise SystemExit("unexpected verification")
    history = payload.get("history") or []
    if not history or history[-1].get("event") != "completed":
        raise SystemExit("unexpected history tail")

    stamp = utc_now().replace(":", "").replace("-", "")
    backup = path.with_name(path.name + f".false-terminal-backup-{stamp}")
    if backup.exists():
        raise SystemExit("backup already exists")
    shutil.copy2(path, backup)
    os.chmod(backup, 0o600)

    now = utc_now()
    payload.pop("completed_at", None)
    payload["status"] = "BLOCKED_EXTERNAL"
    payload["next_action"] = ""
    payload["verification"] = None
    payload["blocker"] = {
        "external": True,
        "description": "GitHub repository deletion remains externally blocked because available authorization lacks delete_repo and authenticated GitHub Web requires WebAuthn/2FA.",
        "evidence": [
            "fredmourao-ai/solange-rolla still resolves while fredmourao-ai/solange-rolla-consultorio resolves.",
            "Both named cleanup branches are absent after workflow 36364649127 validated exact squash heads and deleted them.",
            "Native GitHub deletion authorization lacks delete_repo and the authenticated Settings route requires WebAuthn/2FA.",
        ],
        "alternatives_attempted": [
            "Tried native gh/API deletion on A1/backend; authorization lacks delete_repo.",
            "Tried configured GH_REPO_TOKEN/GH_PAT routes; no usable delete_repo authorization was available.",
            "Tried authenticated GitHub Web Settings route; GitHub requires WebAuthn/2FA.",
        ],
        "resume_condition": "Complete GitHub 2FA/WebAuthn and delete fredmourao-ai/solange-rolla in Settings, or authorize gh with delete_repo, or provision GH_REPO_TOKEN with delete_repo; then validate until the legacy repository returns not found.",
    }
    payload.setdefault("history", []).append({
        "at": now,
        "event": "false_terminal_corrected",
        "prior_status": "CONCLUIDO",
        "reason": "Legacy repository still exists; prior verification did not verify the original goal.",
    })
    payload["updated_at"] = now

    fd, tmp_name = tempfile.mkstemp(prefix="." + path.name + ".", suffix=".tmp", dir=path.parent)
    tmp = Path(tmp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=2, sort_keys=True)
            fh.write("\n")
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)
    return backup


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", type=Path, required=True)
    args = parser.parse_args()
    backup = repair(args.apply)
    print("FALSE_TERMINAL_REPAIR=PASS")
    print("BACKUP_CREATED=true")
    print("BACKUP_NAME=" + backup.name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
