#!/usr/bin/env python3
"""Fail-closed terminal proof for V5 audits. No network, secrets or UI mutation."""
from __future__ import annotations

from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import unicodedata


class AuditProofError(RuntimeError):
    pass


def is_extreme_audit(payload: dict) -> bool:
    if payload.get("audit_extreme_required") is True:
        return True
    goal = unicodedata.normalize("NFKD", str(payload.get("goal", ""))).encode("ascii", "ignore").decode().lower()
    return bool(
        re.search(r"\b(?:auditoria|audit)\s+(?:extrema|extreme|v5|completa|complete|e2e)\b", goal)
        or re.search(r"\b(?:extreme|full|v5)\s+audit\b", goal)
    )


def _path(value: str, *, directory: bool) -> Path:
    supplied = Path(str(value)).expanduser()
    if not supplied.is_absolute() or supplied.is_symlink():
        raise AuditProofError("V5 evidence path must be absolute, not a symlink")
    try:
        resolved = supplied.resolve(strict=True)
    except (OSError, RuntimeError) as exc:
        raise AuditProofError("V5 evidence path not available") from exc
    if (directory and not resolved.is_dir()) or (not directory and not resolved.is_file()):
        raise AuditProofError("V5 evidence has the wrong file type")
    return resolved


def _trusted_certifier(repo_root: Path) -> Path:
    script = repo_root / "scripts" / "certify-audit-manifest.py"
    manifest = repo_root / "docs" / "quality" / "GLOBAL_AUDIT_MANIFEST.json"
    try:
        expected = json.loads(manifest.read_text(encoding="utf-8"))["required_blobs"]["scripts/certify-audit-manifest.py"]
        raw = script.read_bytes()
        actual = hashlib.sha1(f"blob {len(raw)}\0".encode() + raw, usedforsecurity=False).hexdigest()
    except (KeyError, OSError, ValueError, TypeError) as exc:
        raise AuditProofError("V5 canonical certifier unavailable") from exc
    if expected != actual:
        raise AuditProofError("V5 certifier does not match canonical governance hash")
    return script


def _identity_and_hash(manifest: Path) -> tuple[dict[str, str], str]:
    try:
        raw = manifest.read_bytes()
        identity = json.loads(raw.decode("utf-8"))["identity"]
        fields = ("repository", "commit_sha", "release", "environment", "certified_scope")
        exact = {name: str(identity[name]) for name in fields}
        if not all(value.strip() for value in exact.values()):
            raise ValueError("incomplete V5 identity")
    except (KeyError, OSError, ValueError, TypeError) as exc:
        raise AuditProofError("V5 manifest identity missing or malformed") from exc
    return exact, hashlib.sha256(raw).hexdigest()


def verify(task: dict, proof: dict | None, repo_root: Path) -> dict[str, str]:
    """Always rerun the official file-verifying CLI, even after earlier APTO."""
    if not isinstance(proof, dict):
        raise AuditProofError("V5 audit has no verified E2E/APTO proof; keep RUNNING")
    manifest = _path(proof.get("manifest_path", ""), directory=False)
    evidence_root = _path(proof.get("evidence_root", ""), directory=True)
    requirements = _path(proof.get("project_requirements", ""), directory=False)
    identity, digest = _identity_and_hash(manifest)
    if proof.get("identity") != identity or proof.get("manifest_sha256") != digest:
        raise AuditProofError("V5 audit proof is stale; repeat real E2E")
    if identity["repository"] != task.get("repository"):
        raise AuditProofError("V5 audit proof is for another repository")
    script = _trusted_certifier(repo_root)
    try:
        result = subprocess.run(
            [sys.executable, str(script), str(manifest),
             "--evidence-root", str(evidence_root),
             "--project-requirements", str(requirements)],
            cwd=str(repo_root), stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, timeout=30, check=False, text=True,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise AuditProofError("V5 certifier unavailable/timeout; keep audit RUNNING") from exc
    if result.returncode != 0 or "AUDIT_VERDICT=APTO" not in result.stdout.splitlines():
        raise AuditProofError("V5 real UI E2E certifier did not return APTO; keep RUNNING")
    return {"manifest_sha256": digest, "verified_at": datetime.now(timezone.utc).isoformat()}


def certify(
    task: dict, manifest_path: str, evidence_root: str,
    project_requirements: str, repo_root: Path,
) -> dict:
    manifest = _path(manifest_path, directory=False)
    root = _path(evidence_root, directory=True)
    requirements = _path(project_requirements, directory=False)
    identity, digest = _identity_and_hash(manifest)
    proof = {
        "manifest_path": str(manifest),
        "evidence_root": str(root),
        "project_requirements": str(requirements),
        "manifest_sha256": digest,
        "identity": identity,
    }
    return {**proof, **verify(task, proof, repo_root)}
