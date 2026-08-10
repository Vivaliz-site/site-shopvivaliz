#!/usr/bin/env python3
"""Fail-closed documentation preflight for every ShopVivaliz agent.

An agent/task must first run `read`, then explicitly acknowledge the digest that
was delivered, and finally pass `verify` before any mutating action.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

ROOT = Path(__file__).resolve().parents[1]
POLICY_ID = "AGENT_DOCS_PREFLIGHT_V1"
REQUIRED_DOCS = (
    "REGRAS-AGENTES-CENTRALIZADAS.md",
    "AGENTS.md",
    "AGENTS-ACCESS-INDEX.md",
    "docs/ai-agents-map.md",
    "docs/AGENT-DOCS-PREFLIGHT.md",
)
RUNTIME_DIR = ROOT / "storage" / "private" / "agent-docs-read"


class DocsGateError(RuntimeError):
    pass


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _safe_id(value: str, label: str) -> str:
    normalized = re.sub(r"[^A-Za-z0-9._-]+", "-", value.strip()).strip("-.")
    if not normalized:
        raise DocsGateError(f"{label} is required")
    return normalized[:120]


def _normalize_extra_docs(extra_docs: Iterable[str] | None) -> list[str]:
    result: list[str] = []
    for raw in extra_docs or []:
        path = str(raw).strip().replace("\\", "/")
        if not path or path in result:
            continue
        candidate = (ROOT / path).resolve()
        try:
            candidate.relative_to(ROOT.resolve())
        except ValueError as exc:
            raise DocsGateError(f"scope document escapes repository: {path}") from exc
        if not candidate.is_file():
            raise DocsGateError(f"required scope document does not exist: {path}")
        result.append(path)
    return result


def required_paths(extra_docs: Iterable[str] | None = None) -> list[str]:
    result = list(REQUIRED_DOCS)
    for path in _normalize_extra_docs(extra_docs):
        if path not in result:
            result.append(path)
    return result


def build_manifest(extra_docs: Iterable[str] | None = None) -> dict[str, Any]:
    documents: list[dict[str, Any]] = []
    for relative in required_paths(extra_docs):
        path = ROOT / relative
        if not path.is_file():
            raise DocsGateError(f"required documentation missing: {relative}")
        payload = path.read_bytes()
        documents.append(
            {
                "path": relative,
                "sha256": hashlib.sha256(payload).hexdigest(),
                "bytes": len(payload),
            }
        )

    canonical = json.dumps(documents, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    digest = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return {
        "policy_id": POLICY_ID,
        "digest": digest,
        "documents": documents,
    }


def _receipt_path(agent: str, task: str) -> Path:
    return RUNTIME_DIR / f"{_safe_id(agent, 'agent')}--{_safe_id(task, 'task')}.json"


def _atomic_write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def _load_receipt(agent: str, task: str) -> dict[str, Any] | None:
    path = _receipt_path(agent, task)
    if not path.is_file():
        return None
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return value if isinstance(value, dict) else None


def deliver_read_packet(agent: str, task: str, extra_docs: Iterable[str] | None = None) -> dict[str, Any]:
    agent_id = _safe_id(agent, "agent")
    task_id = _safe_id(task, "task")
    manifest = build_manifest(extra_docs)
    document_payloads: list[dict[str, Any]] = []
    for doc in manifest["documents"]:
        relative = str(doc["path"])
        document_payloads.append(
            {
                **doc,
                "content": (ROOT / relative).read_text(encoding="utf-8", errors="replace"),
            }
        )

    delivered_at = utc_now()
    receipt = {
        "schema_version": 1,
        "policy_id": POLICY_ID,
        "agent_id": agent_id,
        "task_id": task_id,
        "digest": manifest["digest"],
        "documents": manifest["documents"],
        "delivered_at": delivered_at,
        "acknowledged": False,
        "acknowledged_at": None,
    }
    _atomic_write_json(_receipt_path(agent_id, task_id), receipt)
    return {
        "policy_id": POLICY_ID,
        "agent_id": agent_id,
        "task_id": task_id,
        "digest": manifest["digest"],
        "delivered_at": delivered_at,
        "documents": document_payloads,
        "next_action": (
            "After reading all emitted documents, run acknowledge with exactly this digest, "
            "then run verify before the first mutation."
        ),
    }


def acknowledge(agent: str, task: str, digest: str, extra_docs: Iterable[str] | None = None) -> dict[str, Any]:
    agent_id = _safe_id(agent, "agent")
    task_id = _safe_id(task, "task")
    receipt = _load_receipt(agent_id, task_id)
    if receipt is None:
        raise DocsGateError("no prior docs read delivery exists for this agent/task")

    current = build_manifest(extra_docs)
    expected = str(current["digest"])
    if digest.strip() != expected:
        raise DocsGateError("acknowledged digest does not match current required documentation")
    if str(receipt.get("digest", "")) != expected:
        raise DocsGateError("delivered documentation is stale; run read again")
    if receipt.get("policy_id") != POLICY_ID:
        raise DocsGateError("receipt uses an unsupported docs policy")

    receipt["acknowledged"] = True
    receipt["acknowledged_at"] = utc_now()
    receipt["documents"] = current["documents"]
    _atomic_write_json(_receipt_path(agent_id, task_id), receipt)
    return receipt


def verify_receipt(agent: str, task: str, extra_docs: Iterable[str] | None = None) -> tuple[bool, str, dict[str, Any] | None]:
    agent_id = _safe_id(agent, "agent")
    task_id = _safe_id(task, "task")
    receipt = _load_receipt(agent_id, task_id)
    if receipt is None:
        return False, "missing docs receipt; run read and acknowledge before mutation", None
    if receipt.get("policy_id") != POLICY_ID:
        return False, "unsupported docs policy in receipt", receipt
    if receipt.get("agent_id") != agent_id or receipt.get("task_id") != task_id:
        return False, "receipt identity mismatch", receipt
    if receipt.get("acknowledged") is not True or not receipt.get("acknowledged_at"):
        return False, "docs were delivered but not acknowledged after reading", receipt

    current = build_manifest(extra_docs)
    if receipt.get("digest") != current["digest"]:
        return False, "required documentation changed; read and acknowledge again", receipt
    if receipt.get("documents") != current["documents"]:
        return False, "receipt document manifest is stale", receipt
    return True, "current docs receipt verified", receipt


def require_docs_receipt(agent: str, task: str, extra_docs: Iterable[str] | None = None) -> dict[str, Any]:
    valid, reason, receipt = verify_receipt(agent, task, extra_docs)
    if not valid:
        raise DocsGateError(reason)
    assert receipt is not None
    return receipt


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Mandatory ShopVivaliz agent documentation preflight")
    sub = parser.add_subparsers(dest="command", required=True)
    for name in ("read", "verify"):
        command = sub.add_parser(name)
        command.add_argument("--agent", required=True)
        command.add_argument("--task", required=True)
        command.add_argument("--doc", action="append", default=[])
    ack = sub.add_parser("acknowledge")
    ack.add_argument("--agent", required=True)
    ack.add_argument("--task", required=True)
    ack.add_argument("--digest", required=True)
    ack.add_argument("--doc", action="append", default=[])
    manifest = sub.add_parser("manifest")
    manifest.add_argument("--doc", action="append", default=[])
    return parser


def main() -> int:
    args = _parser().parse_args()
    try:
        if args.command == "manifest":
            print(json.dumps(build_manifest(args.doc), ensure_ascii=False, indent=2))
            return 0
        if args.command == "read":
            print(json.dumps(deliver_read_packet(args.agent, args.task, args.doc), ensure_ascii=False, indent=2))
            return 0
        if args.command == "acknowledge":
            receipt = acknowledge(args.agent, args.task, args.digest, args.doc)
            print(json.dumps({"ok": True, "receipt": receipt}, ensure_ascii=False, indent=2))
            return 0
        if args.command == "verify":
            valid, reason, receipt = verify_receipt(args.agent, args.task, args.doc)
            print(json.dumps({"ok": valid, "reason": reason, "receipt": receipt}, ensure_ascii=False, indent=2))
            return 0 if valid else 3
    except DocsGateError as exc:
        print(json.dumps({"ok": False, "error": str(exc), "policy_id": POLICY_ID}, ensure_ascii=False))
        return 3
    return 64


if __name__ == "__main__":
    raise SystemExit(main())
