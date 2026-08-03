#!/usr/bin/env python3
"""Apply a small, explicit and reviewable repository migration plan.

The plan moves existing files to canonical paths and leaves compatibility
wrappers/stubs at the old paths. It refuses path traversal, target collisions,
workflow moves, and documents containing likely credentials.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_PLAN = ROOT / ".github" / "migrations" / "current-plan.json"
DEFAULT_REPORT = ROOT / "docs" / "audits" / "repository-safe-migration-report.json"
MANIFEST = ROOT / "config" / "repository-structure-manifest.json"

ALLOWED_TARGET_PREFIXES = (
    "scripts/ai/",
    "scripts/maintenance/",
    "scripts/marketplace/",
    "scripts/production/",
    "scripts/dev/",
    "tests/unit/",
    "tests/integration/",
    "tests/smoke/",
    "docs/knowledge/",
    "docs/operations/",
    "docs/audits/",
    "archive/",
    ".github/workflows-archive/",
)

SECRET_PATTERNS = (
    re.compile(r"github_pat_[A-Za-z0-9_]{30,}"),
    re.compile(r"gh[pousr]_[A-Za-z0-9_]{20,}"),
    re.compile(r"sk-(?:proj-)?[A-Za-z0-9_-]{24,}"),
    re.compile(r"-----BEGIN (?:RSA |OPENSSH |EC |DSA |)PRIVATE KEY-----"),
    re.compile(r"(?i)\b(?:token|password|senha|secret|api[_ -]?key)\s*[:=]\s*[A-Za-z0-9_./+-]{24,}"),
)

VALID_KINDS = {"python", "shell", "powershell", "javascript", "document", "raw"}


class MigrationError(RuntimeError):
    pass


@dataclass(frozen=True)
class Migration:
    source: str
    target: str
    kind: str
    keep_compatibility: bool = True


def normalized_relative(value: str) -> str:
    candidate = PurePosixPath(value.replace("\\", "/"))
    if candidate.is_absolute() or not candidate.parts:
        raise MigrationError(f"path must be relative: {value}")
    if any(part in {"", ".", ".."} for part in candidate.parts):
        raise MigrationError(f"unsafe path: {value}")
    return candidate.as_posix()


def resolve_inside(root: Path, value: str) -> Path:
    relative = normalized_relative(value)
    resolved = (root / relative).resolve()
    if root.resolve() not in (resolved, *resolved.parents):
        raise MigrationError(f"path escapes repository: {value}")
    return resolved


def parse_plan(path: Path) -> list[Migration]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    entries = payload.get("migrations")
    if not isinstance(entries, list) or not entries:
        raise MigrationError("plan must contain a non-empty migrations list")

    migrations: list[Migration] = []
    for index, entry in enumerate(entries):
        if not isinstance(entry, dict):
            raise MigrationError(f"migration #{index + 1} must be an object")
        source = normalized_relative(str(entry.get("source", "")))
        target = normalized_relative(str(entry.get("target", "")))
        kind = str(entry.get("kind", "")).lower()
        if kind not in VALID_KINDS:
            raise MigrationError(f"invalid kind for {source}: {kind}")
        if source == target:
            raise MigrationError(f"source equals target: {source}")
        if source.startswith(".github/workflows/"):
            raise MigrationError("active workflow moves require a dedicated reviewed PR")
        if not target.startswith(ALLOWED_TARGET_PREFIXES):
            raise MigrationError(f"target is outside canonical areas: {target}")
        migrations.append(
            Migration(
                source=source,
                target=target,
                kind=kind,
                keep_compatibility=bool(entry.get("keep_compatibility", True)),
            )
        )

    sources = [item.source for item in migrations]
    targets = [item.target for item in migrations]
    if len(sources) != len(set(sources)):
        raise MigrationError("plan contains duplicate sources")
    if len(targets) != len(set(targets)):
        raise MigrationError("plan contains duplicate targets")
    return migrations


def contains_likely_secret(path: Path) -> bool:
    if path.suffix.lower() not in {".md", ".txt", ".json", ".yml", ".yaml", ".env", ".ini", ".conf"}:
        return False
    text = path.read_text(encoding="utf-8", errors="replace")
    return any(pattern.search(text) for pattern in SECRET_PATTERNS)


def relative_target(source_path: Path, target_path: Path) -> str:
    return os.path.relpath(target_path, source_path.parent).replace(os.sep, "/")


def compatibility_content(item: Migration, source_path: Path, target_path: Path) -> str:
    relative = relative_target(source_path, target_path)
    if item.kind == "python":
        return (
            "#!/usr/bin/env python3\n"
            f'\"\"\"Wrapper legado; implementação canônica em {item.target}.\"\"\"\n'
            "from pathlib import Path\n"
            "import runpy\n\n"
            f"_TARGET = (Path(__file__).resolve().parent / {relative!r}).resolve()\n"
            "if __name__ == '__main__':\n"
            "    runpy.run_path(str(_TARGET), run_name='__main__')\n"
            "else:\n"
            "    globals().update(runpy.run_path(str(_TARGET), run_name=__name__))\n"
        )
    if item.kind == "shell":
        return (
            "#!/bin/sh\nset -eu\n"
            'SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)\n'
            f'exec "$SCRIPT_DIR/{relative}" "$@"\n'
        )
    if item.kind == "powershell":
        return (
            f"# Wrapper legado; implementação canônica em {item.target}.\n"
            f"$target = Join-Path $PSScriptRoot '{relative}'\n"
            "& $target @args\nexit $LASTEXITCODE\n"
        )
    if item.kind == "javascript":
        return (
            f"// Wrapper legado; implementação canônica em {item.target}.\n"
            "const path = require('path');\n"
            f"require(path.resolve(__dirname, {relative!r}));\n"
        )
    if item.kind == "document":
        return (
            "# Documento migrado\n\n"
            f"Conteúdo preservado em [`{item.target}`]({relative}).\n\n"
            "Este arquivo permanece somente como ponte para referências antigas. "
            "Não adicione conteúdo novo aqui.\n"
        )
    if item.kind == "raw":
        raise MigrationError(f"raw migration cannot keep compatibility: {item.source}")
    raise MigrationError(f"unsupported kind: {item.kind}")


def preflight(root: Path, migrations: list[Migration]) -> None:
    for item in migrations:
        source_path = resolve_inside(root, item.source)
        target_path = resolve_inside(root, item.target)
        if not source_path.is_file():
            raise MigrationError(f"source does not exist: {item.source}")
        if target_path.exists():
            raise MigrationError(f"target already exists: {item.target}")
        if item.kind == "document" and contains_likely_secret(source_path):
            raise MigrationError(f"document may contain a credential and requires sanitization: {item.source}")
        if item.kind == "raw" and item.keep_compatibility:
            raise MigrationError(f"raw migration must set keep_compatibility=false: {item.source}")


def update_manifest(root: Path, migrations: list[Migration]) -> None:
    manifest_path = root / MANIFEST.relative_to(ROOT)
    data: dict[str, Any]
    if manifest_path.exists():
        data = json.loads(manifest_path.read_text(encoding="utf-8"))
    else:
        data = {"version": 1}

    for item in migrations:
        section = "document_migrations" if item.kind == "document" else "script_migrations"
        if item.kind == "raw":
            section = "archived_artifacts"
        data.setdefault(section, {})[item.source] = item.target

    data["version"] = max(int(data.get("version", 1)), 1) + 1
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    manifest_path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def apply_plan(root: Path, migrations: list[Migration]) -> list[dict[str, Any]]:
    preflight(root, migrations)
    results: list[dict[str, Any]] = []

    for item in migrations:
        source_path = resolve_inside(root, item.source)
        target_path = resolve_inside(root, item.target)
        original_mode = source_path.stat().st_mode
        target_path.parent.mkdir(parents=True, exist_ok=True)
        shutil.move(str(source_path), str(target_path))
        target_path.chmod(original_mode)

        if item.keep_compatibility:
            source_path.parent.mkdir(parents=True, exist_ok=True)
            source_path.write_text(
                compatibility_content(item, source_path, target_path),
                encoding="utf-8",
            )
            source_path.chmod(original_mode)

        results.append(
            {
                "source": item.source,
                "target": item.target,
                "kind": item.kind,
                "compatibility": item.keep_compatibility,
            }
        )

    update_manifest(root, migrations)
    return results


def validate_applied(root: Path, migrations: list[Migration]) -> None:
    for item in migrations:
        source_path = resolve_inside(root, item.source)
        target_path = resolve_inside(root, item.target)
        if not target_path.is_file():
            raise MigrationError(f"target missing after migration: {item.target}")
        if item.keep_compatibility and not source_path.is_file():
            raise MigrationError(f"compatibility path missing: {item.source}")
        if not item.keep_compatibility and source_path.exists():
            raise MigrationError(f"source should have been removed: {item.source}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Apply an explicit repository migration plan")
    parser.add_argument("--plan", type=Path, default=DEFAULT_PLAN)
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--check-applied", action="store_true")
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    args = parser.parse_args()

    root = args.root.resolve()
    plan_path = args.plan if args.plan.is_absolute() else root / args.plan
    migrations = parse_plan(plan_path)

    if args.check_applied:
        validate_applied(root, migrations)
        print(f"validated {len(migrations)} applied migrations")
        return 0

    preflight(root, migrations)
    if not args.apply:
        print(json.dumps({"status": "preflight_ok", "count": len(migrations)}, indent=2))
        return 0

    results = apply_plan(root, migrations)
    validate_applied(root, migrations)

    report_path = args.report if args.report.is_absolute() else root / args.report
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(
        json.dumps({"status": "applied", "migrations": results}, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(json.dumps({"status": "applied", "count": len(results)}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (MigrationError, json.JSONDecodeError) as exc:
        print(f"ERROR: {exc}")
        raise SystemExit(1)
