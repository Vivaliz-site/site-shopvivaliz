#!/usr/bin/env python3
"""Validate the machine-readable repository structure manifest."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MANIFEST_PATH = ROOT / "config" / "repository-structure-manifest.json"

PYTHON_WRAPPER_MARKERS = ("runpy.run_path", "exec(compile(")
SHELL_WRAPPER_MARKERS = ("exec",)
JS_WRAPPER_MARKERS = ("require(",)
POWERSHELL_WRAPPER_MARKERS = ("Join-Path",)
DOC_STUB_MARKERS = (
    "migrado",
    "migrada",
    "preservado",
    "preservada",
    "ponte",
    "sanitizado",
    "sanitizada",
)


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def wrapper_markers(path: Path) -> tuple[str, ...]:
    suffix = path.suffix.lower()
    if suffix == ".py":
        return PYTHON_WRAPPER_MARKERS
    if suffix == ".sh":
        return SHELL_WRAPPER_MARKERS
    if suffix == ".js":
        return JS_WRAPPER_MARKERS
    if suffix == ".ps1":
        return POWERSHELL_WRAPPER_MARKERS
    return ()


def main() -> int:
    errors: list[str] = []

    if not MANIFEST_PATH.exists():
        print(f"ERROR: manifest missing: {MANIFEST_PATH.relative_to(ROOT)}")
        return 1

    manifest = json.loads(read_text(MANIFEST_PATH))

    for directory in manifest.get("canonical_directories", []):
        if not (ROOT / directory).is_dir():
            errors.append(f"canonical directory missing: {directory}")

    for legacy, canonical in manifest.get("script_migrations", {}).items():
        legacy_path = ROOT / legacy
        canonical_path = ROOT / canonical
        if not canonical_path.is_file():
            errors.append(f"canonical script missing: {canonical}")
        if not legacy_path.is_file():
            errors.append(f"legacy wrapper missing: {legacy}")
            continue
        markers = wrapper_markers(legacy_path)
        legacy_text = read_text(legacy_path)
        if markers and not any(marker in legacy_text for marker in markers):
            errors.append(f"legacy wrapper marker missing in {legacy}; expected one of {markers}")
        if canonical_path.name not in legacy_text:
            errors.append(f"legacy wrapper does not reference canonical filename: {legacy} -> {canonical}")

    for legacy, canonical in manifest.get("document_migrations", {}).items():
        legacy_path = ROOT / legacy
        canonical_path = ROOT / canonical
        if not canonical_path.is_file():
            errors.append(f"canonical document missing: {canonical}")
        if not legacy_path.is_file():
            errors.append(f"legacy document stub missing: {legacy}")
            continue
        lowered = read_text(legacy_path).lower()
        if not any(marker in lowered for marker in DOC_STUB_MARKERS):
            errors.append(f"legacy document is not a migration stub: {legacy}")

    for legacy, canonical in manifest.get("test_migrations", {}).items():
        if (ROOT / legacy).exists():
            errors.append(f"legacy test path still exists: {legacy}")
        if not (ROOT / canonical).is_file():
            errors.append(f"canonical test missing: {canonical}")

    for active, archived in manifest.get("archived_workflows", {}).items():
        if (ROOT / active).exists():
            errors.append(f"paused workflow still active: {active}")
        if not (ROOT / archived).is_file():
            errors.append(f"archived workflow missing: {archived}")

    for removed, archived in manifest.get("removed_malformed_artifacts", {}).items():
        if (ROOT / removed).exists():
            errors.append(f"archived artifact still exists at old path: {removed}")
        if not (ROOT / archived).is_file():
            errors.append(f"archived artifact missing: {archived}")

    print("# Structure manifest validation")
    print(f"Script migrations: {len(manifest.get('script_migrations', {}))}")
    print(f"Document migrations: {len(manifest.get('document_migrations', {}))}")
    print(f"Test migrations: {len(manifest.get('test_migrations', {}))}")
    print(f"Archived workflows: {len(manifest.get('archived_workflows', {}))}")
    print(f"Archived artifacts: {len(manifest.get('removed_malformed_artifacts', {}))}")
    print(f"Errors: {len(errors)}")

    for error in errors:
        print(f"- {error}")

    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
