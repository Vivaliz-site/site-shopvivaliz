#!/usr/bin/env python3
"""Register final manual migrations without replacing the generated manifest."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MANIFEST = ROOT / "config" / "repository-structure-manifest.json"
BLOCKED = ROOT / "docs" / "audits" / "repository-restructure-blocked.json"

FINAL_SCRIPT_MIGRATIONS = {
    "scripts/shopvivaliz_auth.py": "scripts/maintenance/auth/shopvivaliz_auth.py",
    "scripts/shopvivaliz_auth_fixed.py": "scripts/maintenance/auth/shopvivaliz_auth.py",
    "scripts/tiny_sales_report.py": "scripts/marketplace/olist/legacy/tiny_sales_report.py",
    "scripts/deploy-paralelo.sh": "scripts/production/legacy/deploy-paralelo.sh",
    "scripts/auto-sync-oracle.sh": "scripts/production/legacy/auto-sync-oracle.sh",
    "scripts/fix-zero-price-products.php": "scripts/production/legacy/fix-zero-price-products.php",
    "scripts/migrate-old-domain-configs.ps1": "scripts/production/legacy/migrate-old-domain-configs.ps1",
    "scripts/sincronizar_secrets_github.sh": "scripts/maintenance/legacy/sincronizar_secrets_github.sh",
    "scripts/marketplace/olist/repair-olist-images.py": "scripts/marketplace/olist/legacy/repair-olist-images.py",
}

FINAL_ARCHIVES = {
    "release-notes/patches/cleanup-historico-credenciais-only.patch":
        "archive/2026/release-patches/cleanup-historico-credenciais-only.patch",
}


def main() -> int:
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    data.setdefault("script_migrations", {}).update(FINAL_SCRIPT_MIGRATIONS)
    data.setdefault("removed_malformed_artifacts", {}).update(FINAL_ARCHIVES)
    data["version"] = max(int(data.get("version", 1)), 5)
    MANIFEST.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    blocked = {"blocked": []}
    if BLOCKED.exists():
        blocked = json.loads(BLOCKED.read_text(encoding="utf-8"))
        resolved = set(FINAL_SCRIPT_MIGRATIONS)
        blocked["blocked"] = [item for item in blocked.get("blocked", []) if item.get("source") not in resolved]
    BLOCKED.write_text(json.dumps(blocked, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    for legacy, canonical in FINAL_SCRIPT_MIGRATIONS.items():
        if not (ROOT / legacy).is_file() or not (ROOT / canonical).is_file():
            raise SystemExit(f"missing final migration path: {legacy} -> {canonical}")
    for legacy, archived in FINAL_ARCHIVES.items():
        if (ROOT / legacy).exists() or not (ROOT / archived).is_file():
            raise SystemExit(f"archive reconciliation failed: {legacy} -> {archived}")

    print("Final structure migrations reconciled")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
