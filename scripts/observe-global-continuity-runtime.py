#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
CUTOFF = "2026-09-27T17:00:00Z"


def safe_state_rows():
    rows = []
    for path in sorted(ROOT.glob("*.json")):
        if path.name.startswith("_"):
            continue
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue
        task_id = str(data.get("task_id", ""))
        if not task_id.startswith("continuity-e2e-"):
            continue
        updated = str(data.get("updated_at", ""))
        if updated and updated < CUTOFF:
            continue
        rows.append({
            "task_id": task_id,
            "repository": data.get("repository"),
            "status": data.get("status"),
            "verification": data.get("verification"),
            "updated_at": updated,
        })
    return rows


def safe_execution_rows():
    ledger = ROOT / "_resume-executions.jsonl"
    if not ledger.is_file():
        return []
    rows = []
    for line in ledger.read_text(encoding="utf-8").splitlines():
        try:
            item = json.loads(line)
        except Exception:
            continue
        task_id = str(item.get("task_id", ""))
        if not task_id.startswith("continuity-e2e-"):
            continue
        created = str(item.get("created_at", item.get("updated_at", "")))
        if created and created < CUTOFF:
            continue
        diag = item.get("diagnostic") if isinstance(item.get("diagnostic"), dict) else {}
        rows.append({
            "task_id": task_id,
            "repository": item.get("repository"),
            "result": item.get("result"),
            "provider": diag.get("provider"),
            "provider_status": diag.get("provider_status"),
            "background_gemini_exit_code": diag.get("background_gemini_exit_code"),
            "background_paid_fallback_forbidden": diag.get("background_paid_fallback_forbidden"),
            "created_at": created,
        })
    return rows


for row in sorted(safe_state_rows(), key=lambda x: (str(x.get("updated_at")), str(x.get("repository")))):
    print("STATE " + json.dumps(row, sort_keys=True))
for row in safe_execution_rows():
    print("EXEC " + json.dumps(row, sort_keys=True))
