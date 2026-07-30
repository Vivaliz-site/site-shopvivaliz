#!/usr/bin/env python3
"""Repository-wide structure scanner and migration planner for ShopVivaliz.

This tool scans 100% of the checked-out repository and classifies files according

to the structure policy. It is intentionally safe by default: it writes a report

and does not move or delete files unless a future PR explicitly implements an

apply mode for one category at a time.
"""
from __future__ import annotations

import argparse
import json
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT_MD = ROOT / "docs" / "audits" / "repository-wide-structure-report.md"
REPORT_JSON = ROOT / "docs" / "audits" / "repository-wide-structure-report.json"

IGNORE_DIRS = {
    ".git",
    "node_modules",
    "vendor",
    ".next",
    "dist",
    "build",
    "coverage",
    "storage/private",
    "logs",
    ".cache",
    "__pycache__",
}

ROOT_DOC_ALLOWLIST = {
    "README.md",
    "START_HERE.md",
    "START-HERE.md",
    "LICENSE",
    "CHANGELOG.md",
    "CONTRIBUTING.md",
    "SECURITY.md",
}

TARGETS = {
    "root_operational_doc": "docs/operations/legacy-root-docs/",
    "root_audit_or_report": "docs/audits/legacy-reports/",
    "root_status_txt": "docs/audits/legacy-reports/",
    "agent_script": "scripts/ai/",
    "maintenance_script": "scripts/maintenance/",
    "marketplace_script": "scripts/marketplace/<channel>/",
    "production_script": "scripts/production/",
    "dev_script": "scripts/dev/",
    "temporary_artifact": "archive/2026/tmp-artifacts/",
    "legacy_patch": "archive/2026/release-patches/",
}

KEYWORDS_AGENT = ("agent", "autonomous", "trio", "gemini", "claude", "chatgpt", "tasks-queue")
KEYWORDS_MARKETPLACE = ("shopee", "olist", "tiny", "mercado", "meli", "amazon", "tiktok")
KEYWORDS_MAINT = ("diagnose", "diagnostic", "health", "audit", "validator", "guard", "check")
KEYWORDS_PRODUCTION = ("deploy", "production", "rollback", "backup", "sync")
KEYWORDS_REPORT = ("status", "report", "diagnostico", "validacao", "auditoria", "conclusao", "resumo")


def rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def ignored(path: Path) -> bool:
    r = rel(path)
    return any(r == d or r.startswith(d + "/") for d in IGNORE_DIRS)


def iter_files() -> list[Path]:
    return sorted(p for p in ROOT.rglob("*") if p.is_file() and not ignored(p))


def classify(path: Path) -> tuple[str, str, str]:
    r = rel(path)
    name = path.name
    lower = r.lower()

    if r.startswith("tmp-") or "/tmp-" in r:
        return "temporary_artifact", TARGETS["temporary_artifact"], "temporary artifact should not live in repo root"
    if r.startswith("release-notes/patches/") or name.endswith(".patch"):
        return "legacy_patch", TARGETS["legacy_patch"], "patch artifact should be archived or linked from release notes"
    if path.parent == ROOT and path.suffix.lower() in {".md", ".txt"}:
        if name in ROOT_DOC_ALLOWLIST:
            return "root_allowed", "root", "allowed root document"
        if path.suffix.lower() == ".txt" or any(k in lower for k in KEYWORDS_REPORT):
            return "root_audit_or_report", TARGETS["root_audit_or_report"], "root report/audit document"
        return "root_operational_doc", TARGETS["root_operational_doc"], "operational document in root"
    if r.startswith("scripts/") and "/" not in r[len("scripts/"):]:
        if any(k in lower for k in KEYWORDS_AGENT):
            return "agent_script", TARGETS["agent_script"], "agent script at scripts root"
        if any(k in lower for k in KEYWORDS_MARKETPLACE):
            channel = next((k for k in KEYWORDS_MARKETPLACE if k in lower), "marketplace")
            return "marketplace_script", f"scripts/marketplace/{channel}/", "marketplace script at scripts root"
        if any(k in lower for k in KEYWORDS_PRODUCTION):
            return "production_script", TARGETS["production_script"], "production/deploy script at scripts root"
        if any(k in lower for k in KEYWORDS_MAINT):
            return "maintenance_script", TARGETS["maintenance_script"], "maintenance script at scripts root"
        return "dev_script", TARGETS["dev_script"], "unclassified root script"
    return "ok_or_unclassified", "", "no immediate safe move rule"


def build_report(files: list[Path]) -> dict:
    entries = []
    counts: dict[str, int] = defaultdict(int)
    for path in files:
        category, target, reason = classify(path)
        counts[category] += 1
        if category not in {"ok_or_unclassified", "root_allowed"}:
            entries.append({
                "path": rel(path),
                "category": category,
                "target": target,
                "reason": reason,
            })
    return {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "total_files_scanned": len(files),
        "counts": dict(sorted(counts.items())),
        "migration_candidates": entries,
    }


def write_reports(report: dict) -> None:
    REPORT_MD.parent.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    lines = [
        "# Relatório de Estrutura do Repositório Inteiro",
        "",
        f"Gerado em: `{report['generated_at']}`",
        f"Arquivos varridos: `{report['total_files_scanned']}`",
        "",
        "## Contagem por categoria",
        "",
        "| Categoria | Quantidade |",
        "|---|---:|",
    ]
    for category, total in report["counts"].items():
        lines.append(f"| `{category}` | {total} |")
    lines += [
        "",
        "## Candidatos de migração",
        "",
        "| Arquivo | Categoria | Destino sugerido | Motivo |",
        "|---|---|---|---|",
    ]
    for item in report["migration_candidates"]:
        lines.append(
            f"| `{item['path']}` | `{item['category']}` | `{item['target']}` | {item['reason']} |"
        )
    lines += [
        "",
        "## Regra",
        "",
        "Este relatório cobre a árvore completa do checkout. Movimentações físicas devem ser feitas por categoria, com wrapper/stub quando houver risco de quebra.",
    ]
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Scan and plan repository-wide restructuring")
    parser.add_argument("--write-report", action="store_true", help="write docs/audits repository-wide reports")
    args = parser.parse_args()

    report = build_report(iter_files())
    print(json.dumps(report, ensure_ascii=False, indent=2))
    if args.write_report:
        write_reports(report)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
