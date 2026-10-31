#!/usr/bin/env python3
"""SHA-bound fail-closed evidence that an agent runner loaded canonical V5 rules.

This proves file bytes were loaded and an acknowledgement supplied. It cannot
prove that a language model understood the rules: independent review is required.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

REQUIRED = (
    "AGENTS.md",
    "AUDIT_POLICY.md",
    "docs/knowledge/atendimento-chatgpt-bootstrap.md",
    "docs/knowledge/host-access.md",
    "docs/knowledge/README.md",
    "docs/knowledge/agent-rules.md",
    "docs/quality/EXTREME_AUDIT_PROTOCOL.md",
    "docs/quality/AUDIT_APTO_REMEDIATION_LOOP_V1.md",
    "docs/quality/AUDIT_ABSOLUTE_GATE_V1.md",
    "docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md",
    "docs/quality/AUDIT_AGENT_PREFLIGHT_AND_PR_CONTINUITY_V1.md",
)
MARKERS = {
    "AGENTS.md": ("rules_loaded", "Retome"),
    "AUDIT_POLICY.md": ("certify-audit-manifest.py", "governance-gate"),
    "docs/knowledge/agent-rules.md": ("rules_loaded", "BLOCKED_EXTERNAL"),
    "docs/quality/EXTREME_AUDIT_PROTOCOL.md": ("AUDIT_MERGE_ENFORCEMENT_V1", "AUDIT_VERDICT"),
    "docs/quality/AUDIT_APTO_REMEDIATION_LOOP_V1.md": ("RUNNING", "AUDIT_VERDICT"),
    "docs/quality/AUDIT_ABSOLUTE_GATE_V1.md": ("AUDIT_VERDICT=APTO",),
    "docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md": ("governance-gate",),
    "docs/quality/AUDIT_AGENT_PREFLIGHT_AND_PR_CONTINUITY_V1.md": ("rules_loaded", "next_action", "governance"),
}
ACK = "V5_ACKNOWLEDGED_READ_VERIFIED"
SCHEMA = "V5_RULES_LOADED_V1"


def git(repo: Path, *args: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(repo), *args], stderr=subprocess.DEVNULL)


def collect(repo: Path, ref: str) -> tuple[str, dict[str, str]]:
    if not ref or ref.startswith("-") or ":" in ref or any(ch.isspace() for ch in ref):
        raise ValueError("invalid revision")
    revision = git(repo, "rev-parse", "--verify", f"{ref}^{{commit}}").decode().strip()
    if len(revision) != 40 or any(c not in "0123456789abcdef" for c in revision):
        raise ValueError("invalid git revision")
    digests = {}
    for path in REQUIRED:
        content = git(repo, "show", f"{revision}:{path}")
        if not content.strip():
            raise ValueError("empty rule file: " + path)
        decoded = content.decode("utf-8")
        for marker in MARKERS.get(path, ()):
            if marker not in decoded:
                raise ValueError("missing invariant: " + path + ":" + marker)
        digests[path] = hashlib.sha256(content).hexdigest()
    return revision, digests


def capture(repo: Path, ref: str, receipt: Path, agent: str, ack: str) -> dict:
    if not agent or not agent.strip() or len(agent) > 128:
        raise ValueError("invalid agent identity")
    if ack != ACK:
        raise ValueError("explicit read and acknowledge step missing")
    sha, files = collect(repo, ref)
    result = {"schema": SCHEMA, "revision": sha, "agent": agent.strip(),
              "acknowledgement": ACK, "utc_epoch": int(time.time()),
              "rules_loaded": [{"path": p, "sha256": digest, "ref": ref}
                               for p, digest in files.items()]}
    receipt.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix=".v5-rules-", dir=str(receipt.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fp:
            json.dump(result, fp, sort_keys=True)
            fp.flush()
            os.fsync(fp.fileno())
        os.chmod(tmp, 0o600)
        os.replace(tmp, receipt)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)
    return result


def verify(repo: Path, ref: str, receipt: Path, agent: str, ttl: int = 900) -> dict:
    if ttl < 1 or ttl > 86400:
        raise ValueError("invalid receipt TTL")
    if receipt.is_symlink() or not receipt.is_file():
        raise ValueError("rules_loaded receipt missing or symlink")
    result = json.loads(receipt.read_text(encoding="utf-8"))
    if result.get("schema") != SCHEMA or result.get("acknowledgement") != ACK:
        raise ValueError("rules_loaded acknowledgement not verified")
    if result.get("agent") != agent or not agent:
        raise ValueError("agent identity changed")
    now = int(time.time())
    ts = result.get("utc_epoch")
    if type(ts) is not int or ts > now + 30 or now - ts > ttl:
        raise ValueError("rules_loaded expired or future-dated")
    sha, current = collect(repo, ref)
    if result.get("revision") != sha:
        raise ValueError("canonical main revision changed")
    entries = result.get("rules_loaded")
    if not isinstance(entries, list) or len(entries) != len(REQUIRED):
        raise ValueError("incomplete rules_loaded")
    expected = [{"path": p, "sha256": digest, "ref": ref} for p, digest in current.items()]
    if entries != expected:
        raise ValueError("rules_loaded content differs from canonical main")
    return result


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=("capture", "verify"))
    ap.add_argument("--repo", type=Path, default=Path.cwd())
    ap.add_argument("--ref", default="HEAD")
    ap.add_argument("--receipt", type=Path, required=True)
    ap.add_argument("--agent", required=True)
    ap.add_argument("--ack", default="")
    ap.add_argument("--ttl", type=int, default=900)
    args = ap.parse_args()
    try:
        if args.mode == "capture":
            result = capture(args.repo, args.ref, args.receipt, args.agent, args.ack)
        else:
            result = verify(args.repo, args.ref, args.receipt, args.agent, args.ttl)
    except (ValueError, OSError, KeyError, UnicodeError, json.JSONDecodeError, subprocess.CalledProcessError) as exc:
        print("RULES_NOT_LOADED: " + str(exc), file=sys.stderr)
        return 2
    print("RULES_LOADED_VERIFIED sha=" + result["revision"] + " agent=" + result["agent"])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
