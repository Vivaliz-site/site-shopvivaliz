#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TARGETS = {
    "catalog_text": ROOT / "admin/catalog-optimization/admin_catalog.php",
    "catalog_images": ROOT / "admin/ai-image-studio/admin_validate.php",
}

SIMULATION_MARKERS = (
    "não foi enviado a lugar nenhum",
    "não tem propagação automática",
    "promoção automática para este canal não está implementada",
    "promoção para a loja não é automática",
    "gancho comentado",
    "mock",
    "simulação",
    "simulacao",
)

report: dict[str, object] = {"ok": True, "checks": {}}
for name, path in TARGETS.items():
    issues: list[str] = []
    if not path.is_file():
        issues.append("file_missing")
        text = ""
    else:
        text = path.read_text(encoding="utf-8", errors="replace")
        lower = text.lower()
        for marker in SIMULATION_MARKERS:
            if marker in lower:
                issues.append("simulation_marker:" + marker)

    if name == "catalog_text":
        if "status = ?, updated_at" in text and "'approved'" in text and "promoteToProduction" in text:
            issues.append("approval_status_written_before_confirmed_publication")
        if "return ['promoted' => false" in text or "'promoted' => false" in text:
            issues.append("publisher_can_return_false_after_approval")
    else:
        if "AiStudioImagePublisher" not in text:
            issues.append("real_image_publisher_not_wired")
        if "publication_failed" not in text or "'published'" not in text:
            issues.append("image_publication_statuses_missing")

    report["checks"][name] = {"ok": not issues, "issues": issues}
    if issues:
        report["ok"] = False

print(json.dumps(report, ensure_ascii=False, indent=2))
sys.exit(0 if report["ok"] else 2)
