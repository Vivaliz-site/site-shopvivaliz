#!/usr/bin/env python3
from __future__ import annotations
import json
import tempfile
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from resume_queue_maintenance import classify, compact


def write(path: Path, value) -> None:
    if isinstance(value, list):
        path.write_text("".join(json.dumps(row) + "\n" for row in value), encoding="utf-8")
    else:
        path.write_text(json.dumps(value) + "\n", encoding="utf-8")


with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    running = {"task_id":"g3","repository":"Vivaliz-site/site-shopvivaliz","status":"RUNNING","updated_at":"2026-09-29T01:00:00Z","next_action":"continue"}
    terminal = {"task_id":"g2","repository":"Vivaliz-site/site-shopvivaliz","status":"CONCLUIDO","updated_at":"2026-09-28T23:10:54Z","next_action":""}
    write(root/"g3.json", running)
    write(root/"g2.json", terminal)
    requests = [
        {"id":"r1","status":"queued","task_id":"g3","repository":"Vivaliz-site/site-shopvivaliz","checkpoint_updated_at":"2026-09-29T01:00:00Z","next_action":"continue","fingerprint":"fp-current"},
        {"id":"r2","status":"queued","task_id":"g3","repository":"Vivaliz-site/site-shopvivaliz","checkpoint_updated_at":"2026-09-29T00:59:00Z","next_action":"old","fingerprint":"fp-old"},
        {"id":"r3","status":"queued","task_id":"g2","repository":"Vivaliz-site/site-shopvivaliz","checkpoint_updated_at":"2026-09-28T23:10:54Z","next_action":"old","fingerprint":"fp-terminal"},
        {"id":"r4","status":"queued","task_id":"missing","repository":"Vivaliz-site/site-shopvivaliz","checkpoint_updated_at":"x","next_action":"x","fingerprint":"fp-missing"},
        {"id":"r5","status":"queued","task_id":"g3","repository":"Vivaliz-site/site-shopvivaliz","checkpoint_updated_at":"2026-09-29T01:00:00Z","next_action":"continue","fingerprint":"fp-current"},
    ]
    write(root/"_resume-requests.jsonl", requests)
    write(root/"_resume-executions.jsonl", [])
    rows, counts = classify(root)
    assert counts == {"actionable":1,"duplicate":1,"orphaned":1,"superseded":1,"terminal":1}, counts
    result = compact(root)
    assert result["retained_actionable"] == 1, result
    assert result["archived"] == 4, result
    remaining = [json.loads(x) for x in (root/"_resume-requests.jsonl").read_text().splitlines()]
    assert [x["id"] for x in remaining] == ["r1"], remaining
    archived = [json.loads(x) for x in (root/"_resume-requests-archive.jsonl").read_text().splitlines()]
    assert len(archived) == 4
    assert {x["_queue_archive_reason"] for x in archived} == {"duplicate","orphaned","superseded","terminal"}

print("RESUME_QUEUE_MAINTENANCE_TEST=PASS")
