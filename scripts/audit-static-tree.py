#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import shutil
import sys
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SKIP_DIRS = {
    ".git",
    ".venv",
    ".trash_claude_cleanup",
    "node_modules",
    "vendor",
    "storage",
    "logs",
    "public/dist",
}
SCAN_EXTENSIONS = {
    ".php",
    ".js",
    ".css",
    ".html",
    ".json",
    ".sh",
    ".py",
    ".md",
    ".txt",
    ".yml",
    ".yaml",
    ".ps1",
}
ORPHAN_CANDIDATE_ROOTS = {"admin", "api", "css", "js", "public", "scripts"}
PRESERVE_NAMES = {
    ".env",
    ".env.example",
    "AGENTS.md",
    "Dockerfile",
    "docker-compose.yml",
    "git-auto-sync.py",
    "package.json",
    "package-lock.json",
    "composer.json",
    "composer.lock",
    "admin-sections.php",
    "deploy-trigger.txt",
    "tasks-queue.json",
    "queue.json",
    "queue.sqlite",
    "products-cache-ativos.json",
}
LOW_RISK_PATTERNS = (
    ".bundle",
    ".bak",
    ".old",
    ".tmp",
    ".orig",
)


@dataclass(frozen=True)
class Finding:
    path: str
    reason: str
    risk: str
    action: str


def is_skipped(path: Path) -> bool:
    try:
        rel = path.relative_to(ROOT)
    except ValueError:
        return True
    return any(part in SKIP_DIRS for part in rel.parts)


def scan_files() -> list[Path]:
    return sorted(p for p in ROOT.rglob("*") if p.is_file() and not is_skipped(p))


def read_text(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return ""


def collect_reference_blob(files: list[Path]) -> str:
    chunks: list[str] = []
    for path in files:
        if path.suffix.lower() not in SCAN_EXTENSIONS:
            continue
        chunks.append(read_text(path))
    return "\n".join(chunks)


def is_referenced(path: Path, reference_blob: str) -> bool:
    rel = path.relative_to(ROOT).as_posix()
    candidates = {
        rel,
        "/" + rel,
        path.name,
        rel.replace("/", "\\"),
    }
    return any(candidate in reference_blob for candidate in candidates)


def classify_file(path: Path, reference_blob: str) -> Finding | None:
    rel = path.relative_to(ROOT)
    rel_text = rel.as_posix()
    if path.name in PRESERVE_NAMES:
        return None

    suffix = path.suffix.lower()
    if len(rel.parts) == 1 and path.name.startswith("deploy-") and suffix in {".txt", ".log", ".md"}:
        return Finding(rel_text, "historico_deploy_na_raiz", "low", "quarantine")

    if suffix in LOW_RISK_PATTERNS or path.name.endswith(".bundle"):
        return Finding(rel_text, "artefato_legado_ou_bundle", "low", "quarantine")

    if rel.parts and rel.parts[0] in ORPHAN_CANDIDATE_ROOTS and suffix in {".php", ".js", ".css", ".html", ".sh", ".py"}:
        if not is_referenced(path, reference_blob):
            return Finding(rel_text, "sem_referencia_estatica_por_caminho", "medium", "review")

    return None


def quarantine(findings: list[Finding]) -> list[str]:
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    quarantine_root = ROOT / "storage" / "orphan-quarantine" / stamp
    moved: list[str] = []
    for finding in findings:
        if finding.action != "quarantine" or finding.risk != "low":
            continue
        src = ROOT / finding.path
        if not src.is_file() or is_skipped(src):
            continue
        dst = quarantine_root / finding.path
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.move(str(src), str(dst))
        moved.append(finding.path)
    return moved


def main() -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass

    parser = argparse.ArgumentParser(description="Static orphan and legacy file audit for ShopVivaliz.")
    parser.add_argument("--dry-run", action="store_true", help="Only print findings.")
    parser.add_argument("--delete", action="store_true", help="Move low-risk findings to storage/orphan-quarantine.")
    parser.add_argument("--json", action="store_true", help="Emit JSON only.")
    args = parser.parse_args()

    files = scan_files()
    reference_blob = collect_reference_blob(files)
    findings = [finding for path in files if (finding := classify_file(path, reference_blob)) is not None]
    findings = sorted(findings, key=lambda item: (item.risk, item.path))

    moved: list[str] = []
    if args.delete:
        moved = quarantine(findings)

    payload = {
        "ok": True,
        "dry_run": args.dry_run or not args.delete,
        "delete_requested": args.delete,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "summary": {
            "files_scanned": len(files),
            "findings": len(findings),
            "low_risk": sum(1 for finding in findings if finding.risk == "low"),
            "medium_risk": sum(1 for finding in findings if finding.risk == "medium"),
            "quarantined": len(moved),
        },
        "findings": [asdict(finding) for finding in findings],
        "quarantined": moved,
    }

    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
