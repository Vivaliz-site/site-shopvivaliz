#!/usr/bin/env python3
"""Repository-wide scanner and safe batch migrator for ShopVivaliz.

Default mode only scans. Apply mode moves one controlled category at a time,
creates compatibility stubs/wrappers, updates the structure manifest, and never
prints secret values.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REPORT_MD = ROOT / "docs" / "audits" / "repository-wide-structure-report.md"
REPORT_JSON = ROOT / "docs" / "audits" / "repository-wide-structure-report.json"
MANIFEST_PATH = ROOT / "config" / "repository-structure-manifest.json"
BLOCKED_REPORT = ROOT / "docs" / "audits" / "repository-restructure-blocked.json"

IGNORE_DIRS = {
    ".git", "node_modules", "vendor", ".next", "dist", "build", "coverage",
    "storage/private", "logs", ".cache", "__pycache__",
}

CANONICAL_PREFIXES = (
    "scripts/ai/", "scripts/maintenance/", "scripts/marketplace/", "scripts/dev/",
    "scripts/production/", "docs/knowledge/", "docs/operations/", "docs/audits/",
    "archive/", ".github/workflows-archive/", "tests/unit/", "tests/integration/",
    "tests/smoke/",
)

ROOT_DOC_ALLOWLIST = {
    "README.md", "START_HERE.md", "START-HERE.md", "LICENSE", "CHANGELOG.md",
    "CONTRIBUTING.md", "SECURITY.md",
}

STUB_MARKERS = (
    "documento migrado", "relatório migrado", "relatorio migrado", "auditoria migrada",
    "registro migrado", "plano migrado", "diagnóstico migrado", "diagnostico migrado",
    "guia de agentes migrado", "conteúdo histórico preservado", "conteudo historico preservado",
    "permanece somente como ponte", "apenas como ponte", "documento migrado e sanitizado",
)

WRAPPER_MARKERS = ("runpy.run_path", "exec(compile(", "wrapper legado")

KEYWORDS_AGENT = ("agent", "agente", "autonomous", "autonom", "trio", "gemini", "claude", "chatgpt", "task")
KEYWORDS_MARKETPLACE = ("shopee", "olist", "tiny", "mercado", "meli", "amazon", "tiktok")
KEYWORDS_MAINT = ("diagn", "health", "audit", "auditoria", "validator", "validar", "guard", "check", "fix", "corrigir", "repair")
KEYWORDS_PRODUCTION = ("deploy", "production", "producao", "rollback", "backup", "sync", "sincron")
KEYWORDS_REPORT = ("status", "report", "relatorio", "diagnostico", "validacao", "auditoria", "conclusao", "resumo", "resultado")

SECRET_PATTERNS = [
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"github_pat_[A-Za-z0-9_]{40,}"),
    re.compile(r"sk-(?:proj-)?[A-Za-z0-9_-]{24,}"),
    re.compile(r"xox[baprs]-[A-Za-z0-9-]{20,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
    re.compile(r"(?i)\b(?:token|api[_ -]?key|secret|client[_ -]?secret|password|senha)\s*[:=]\s*[A-Za-z0-9_./+-]{24,}\b"),
    re.compile(r"eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}"),
]

TEXT_SUFFIXES = {".md", ".txt", ".py", ".sh", ".ps1", ".js", ".json", ".yml", ".yaml", ".php"}


def rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def ignored(path: Path) -> bool:
    value = rel(path)
    return any(value == item or value.startswith(item + "/") for item in IGNORE_DIRS)


def iter_files() -> list[Path]:
    return sorted(path for path in ROOT.rglob("*") if path.is_file() and not ignored(path))


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def is_stub(path: Path) -> bool:
    if path.suffix.lower() not in {".md", ".txt"}:
        return False
    lowered = read_text(path).lower()
    return any(marker in lowered for marker in STUB_MARKERS)


def is_wrapper(path: Path) -> bool:
    if path.suffix.lower() != ".py":
        return False
    text = read_text(path)
    return any(marker in text for marker in WRAPPER_MARKERS)


def contains_secret(path: Path) -> bool:
    if path.suffix.lower() not in TEXT_SUFFIXES:
        return False
    text = read_text(path)
    return any(pattern.search(text) for pattern in SECRET_PATTERNS)


def marketplace_channel(lower: str) -> str:
    if "shopee" in lower:
        return "shopee"
    if "olist" in lower or "tiny" in lower:
        return "olist"
    if "mercado" in lower or "meli" in lower:
        return "mercado-livre"
    if "amazon" in lower:
        return "amazon"
    if "tiktok" in lower:
        return "tiktok"
    return "other"


def classify(path: Path) -> tuple[str, str, str]:
    value = rel(path)
    name = path.name
    lower = value.lower()

    if value.startswith(CANONICAL_PREFIXES):
        return "canonical", "", "already in canonical area"
    if path.parent == ROOT and name in ROOT_DOC_ALLOWLIST:
        return "root_allowed", "root", "allowed root file"
    if path.parent == ROOT and path.suffix.lower() in {".md", ".txt"}:
        if is_stub(path):
            return "root_stub", "root", "valid compatibility stub"
        if path.name.startswith(".sync-test") or re.fullmatch(r"[A-Fa-f0-9]{24,}\.txt", path.name):
            return "temporary_artifact", "archive/2026/tmp-artifacts/", "temporary root artifact"
        if path.suffix.lower() == ".txt" or any(keyword in lower for keyword in KEYWORDS_REPORT):
            return "root_audit_or_report", "docs/audits/legacy-reports/", "root report or audit"
        return "root_operational_doc", "docs/operations/legacy-root-docs/", "root operational document"
    if path.parent == ROOT and path.suffix.lower() == ".py":
        if is_wrapper(path):
            return "root_wrapper", "root", "valid compatibility wrapper"
        return "root_python", "scripts/dev/legacy-root-tools/", "Python utility in repository root"
    if path.parent == ROOT / "scripts" and path.suffix.lower() == ".py":
        if is_wrapper(path):
            return "scripts_wrapper", "scripts/", "valid compatibility wrapper"
        if any(keyword in lower for keyword in KEYWORDS_AGENT):
            return "agent_script", "scripts/ai/legacy/", "agent script at scripts root"
        if any(keyword in lower for keyword in KEYWORDS_MARKETPLACE):
            return "marketplace_script", f"scripts/marketplace/{marketplace_channel(lower)}/legacy/", "marketplace script at scripts root"
        if any(keyword in lower for keyword in KEYWORDS_MAINT):
            return "maintenance_script", "scripts/maintenance/legacy/", "maintenance script at scripts root"
        if any(keyword in lower for keyword in KEYWORDS_PRODUCTION):
            return "production_script", "scripts/production/legacy/", "production-like script at scripts root"
        return "dev_script", "scripts/dev/legacy-scripts/", "unclassified Python script at scripts root"
    if value.startswith("tmp-") or "/tmp-" in value:
        return "temporary_artifact", "archive/2026/tmp-artifacts/", "temporary artifact"
    if value.startswith("release-notes/patches/") or name.endswith(".patch"):
        return "legacy_patch", "archive/2026/release-patches/", "legacy patch"
    return "ok_or_unclassified", "", "no safe automatic rule"


def unique_destination(base: Path, name: str) -> Path:
    base.mkdir(parents=True, exist_ok=True)
    candidate = base / name
    if not candidate.exists():
        return candidate
    stem = candidate.stem
    suffix = candidate.suffix
    index = 2
    while True:
        alternate = base / f"{stem}-legacy-{index}{suffix}"
        if not alternate.exists():
            return alternate
        index += 1


def relative_link(source: Path, destination: Path) -> str:
    return os.path.relpath(destination, source.parent).replace(os.sep, "/")


def document_stub(destination: Path, sanitized: bool = False) -> str:
    relative = destination.relative_to(ROOT).as_posix()
    title = "# Documento migrado e sanitizado" if sanitized else "# Documento migrado"
    extra = "\nO conteúdo original não foi copiado porque apresentou padrão de credencial. A credencial deve ser rotacionada.\n" if sanitized else ""
    return f"{title}\n\nDestino canônico: [`{relative}`]({relative}).\n{extra}\nEste arquivo permanece somente como ponte. Não adicionar conteúdo novo aqui.\n"


def python_wrapper(source: Path, destination: Path) -> str:
    relative = relative_link(source, destination)
    return (
        "#!/usr/bin/env python3\n"
        "\"\"\"Wrapper legado gerado pela reorganização estrutural.\"\"\"\n"
        "from pathlib import Path\n"
        f"_TARGET = (Path(__file__).resolve().parent / {relative!r}).resolve()\n"
        "_LEGACY_FILE = str(Path(__file__).resolve())\n"
        "_GLOBALS = globals()\n"
        "_GLOBALS['__file__'] = _LEGACY_FILE\n"
        "exec(compile(_TARGET.read_text(encoding='utf-8'), _LEGACY_FILE, 'exec'), _GLOBALS)\n"
    )


def load_manifest() -> dict:
    if not MANIFEST_PATH.exists():
        return {"version": 2, "script_migrations": {}, "document_migrations": {}, "removed_malformed_artifacts": {}}
    return json.loads(read_text(MANIFEST_PATH))


def save_manifest(manifest: dict) -> None:
    manifest["version"] = max(int(manifest.get("version", 1)), 3)
    manifest["updated_at"] = datetime.now(timezone.utc).date().isoformat()
    MANIFEST_PATH.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def quarantine_sensitive_document(source: Path, manifest: dict) -> dict:
    base = ROOT / "docs" / "audits" / "security" / "quarantined-legacy-documents"
    destination = unique_destination(base, source.name)
    destination.write_text(
        "# Documento legado sanitizado\n\n"
        f"Origem: `{rel(source)}`\n\n"
        "O conteúdo não foi preservado na árvore atual porque o scanner encontrou padrão de credencial. "
        "O valor não é reproduzido. Considere a credencial comprometida, rotacione no provedor e mantenha o novo valor somente em secret protegido.\n",
        encoding="utf-8",
    )
    source.write_text(document_stub(destination, sanitized=True), encoding="utf-8")
    manifest.setdefault("document_migrations", {})[rel(source)] = rel(destination)
    return {"source": rel(source), "destination": rel(destination), "action": "sanitized", "category": "sensitive_document"}


def migrate_document(source: Path, target: str, manifest: dict) -> dict:
    if contains_secret(source):
        return quarantine_sensitive_document(source, manifest)
    destination = unique_destination(ROOT / target, source.name)
    shutil.move(str(source), str(destination))
    source.write_text(document_stub(destination), encoding="utf-8")
    manifest.setdefault("document_migrations", {})[rel(source)] = rel(destination)
    return {"source": rel(source), "destination": rel(destination), "action": "moved_with_stub", "category": "document"}


def migrate_python(source: Path, target: str, manifest: dict) -> dict:
    if contains_secret(source):
        return {"source": rel(source), "action": "blocked_sensitive", "category": "python"}
    destination = unique_destination(ROOT / target, source.name)
    shutil.move(str(source), str(destination))
    source.write_text(python_wrapper(source, destination), encoding="utf-8")
    source.chmod(destination.stat().st_mode)
    manifest.setdefault("script_migrations", {})[rel(source)] = rel(destination)
    return {"source": rel(source), "destination": rel(destination), "action": "moved_with_wrapper", "category": "python"}


def archive_artifact(source: Path, target: str, manifest: dict) -> dict:
    destination = unique_destination(ROOT / target, source.name.lstrip("."))
    shutil.move(str(source), str(destination))
    manifest.setdefault("removed_malformed_artifacts", {})[rel(source)] = rel(destination)
    return {"source": rel(source), "destination": rel(destination), "action": "archived", "category": "artifact"}


def apply_categories(categories: set[str]) -> dict:
    manifest = load_manifest()
    applied: list[dict] = []
    blocked: list[dict] = []

    for source in list(iter_files()):
        category, target, _reason = classify(source)
        selected = (
            ("root-docs" in categories and category in {"root_operational_doc", "root_audit_or_report"})
            or ("root-python" in categories and category == "root_python")
            or ("scripts-python" in categories and category in {"agent_script", "marketplace_script", "maintenance_script", "production_script", "dev_script"})
            or ("temp-artifacts" in categories and category == "temporary_artifact")
        )
        if not selected:
            continue
        if category in {"root_operational_doc", "root_audit_or_report"}:
            result = migrate_document(source, target, manifest)
        elif category in {"root_python", "agent_script", "marketplace_script", "maintenance_script", "production_script", "dev_script"}:
            result = migrate_python(source, target, manifest)
        else:
            result = archive_artifact(source, target, manifest)
        (blocked if result["action"].startswith("blocked") else applied).append(result)

    save_manifest(manifest)
    BLOCKED_REPORT.parent.mkdir(parents=True, exist_ok=True)
    BLOCKED_REPORT.write_text(json.dumps({"blocked": blocked}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return {"applied": applied, "blocked": blocked}


def build_report(files: list[Path]) -> dict:
    entries: list[dict] = []
    counts: dict[str, int] = defaultdict(int)
    for path in files:
        category, target, reason = classify(path)
        counts[category] += 1
        if category not in {"ok_or_unclassified", "canonical", "root_allowed", "root_stub", "root_wrapper", "scripts_wrapper"}:
            entries.append({"path": rel(path), "category": category, "target": target, "reason": reason})
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
        "# Relatório de Estrutura do Repositório Inteiro", "",
        f"Gerado em: `{report['generated_at']}`",
        f"Arquivos varridos: `{report['total_files_scanned']}`", "",
        "## Contagem por categoria", "", "| Categoria | Quantidade |", "|---|---:|",
    ]
    for category, total in report["counts"].items():
        lines.append(f"| `{category}` | {total} |")
    lines += ["", "## Candidatos restantes", "", "| Arquivo | Categoria | Destino sugerido | Motivo |", "|---|---|---|---|"]
    for item in report["migration_candidates"]:
        lines.append(f"| `{item['path']}` | `{item['category']}` | `{item['target']}` | {item['reason']} |")
    lines += ["", "## Regra", "", "Somente candidatos listados aqui ainda exigem lote adicional ou decisão manual."]
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Scan or safely restructure the repository")
    parser.add_argument("--write-report", action="store_true")
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--categories", default="", help="comma-separated: root-docs,root-python,scripts-python,temp-artifacts")
    args = parser.parse_args()

    if args.apply:
        categories = {item.strip() for item in args.categories.split(",") if item.strip()}
        if not categories:
            parser.error("--apply requires --categories")
        result = apply_categories(categories)
        print(json.dumps({"applied_count": len(result["applied"]), "blocked_count": len(result["blocked"])}, indent=2))

    report = build_report(iter_files())
    print(json.dumps(report, ensure_ascii=False, indent=2))
    if args.write_report:
        write_reports(report)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
