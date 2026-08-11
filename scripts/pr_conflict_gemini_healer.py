#!/usr/bin/env python3
"""Resolve Git merge conflicts with Gemini using a rotating credential pool.

Security invariants:
- never prints API key values;
- never executes code from the pull request;
- only rewrites files already reported by Git as conflicted;
- refuses binary/symlink/oversized conflict payloads;
- stages a file only after conflict markers are gone;
- can read Gemini credentials from a private env file without exporting them.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping, Sequence

DEFAULT_MODELS = ("gemini-3.6-flash", "gemini-2.5-flash")
KEY_ENV_NAMES = (
    "GEMINI_API_KEY",
    "GOOGLE_GEMINI_API_KEY",
    "GOOGLE_IMAGEN_API_KEY",
    "GOOGLE_API_KEY",
    "GEMINI_API_KEYS",
    "GOOGLE_GEMINI_API_KEYS",
    "GOOGLE_IMAGEN_API_KEYS",
    "GOOGLE_API_KEYS",
)
CONFLICT_MARKERS = ("<<<<<<<", "=======", ">>>>>>>")
MAX_FILE_BYTES = 350_000
MAX_CONFLICT_FILES = 12


@dataclass(frozen=True)
class GeminiResult:
    ok: bool
    status: int
    text: str = ""


def split_secret_bundle(raw: str) -> list[str]:
    """Split comma/semicolon/newline secret bundles without logging contents."""
    if not raw:
        return []
    return [part.strip() for part in re.split(r"[\n,;]+", raw) if part.strip()]


def parse_env_file(path: Path) -> dict[str, str]:
    """Read dotenv assignments without sourcing or executing file content."""
    values: dict[str, str] = {}
    if not path.is_file():
        raise FileNotFoundError(f"gemini_env_file_missing:{path}")
    for raw in path.read_text(encoding="utf-8", errors="strict").splitlines():
        text = raw.strip()
        if not text or text.startswith("#") or "=" not in text:
            continue
        if text.startswith("export "):
            text = text[7:].strip()
        key, value = text.split("=", 1)
        key = key.strip()
        if key not in KEY_ENV_NAMES:
            continue
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        values[key] = value
    return values


def credential_environment(env: Mapping[str, str] | None = None) -> dict[str, str]:
    source = dict(os.environ if env is None else env)
    env_file = source.get("GEMINI_ENV_FILE", "").strip()
    if env_file:
        # Private runtime file is authoritative for the credential aliases it
        # contains. Only Gemini-related names are parsed from it.
        source.update(parse_env_file(Path(env_file)))
    return source


def collect_api_keys(env: Mapping[str, str] | None = None) -> list[str]:
    env = credential_environment() if env is None else env
    seen: set[str] = set()
    keys: list[str] = []
    for name in KEY_ENV_NAMES:
        for value in split_secret_bundle(env.get(name, "")):
            if value.lower().startswith(("replace-with-", "change_me", "changeme")):
                continue
            if value not in seen:
                seen.add(value)
                keys.append(value)
    return keys


def configured_models(env: Mapping[str, str] | None = None) -> list[str]:
    env = credential_environment() if env is None else env
    raw = env.get("GEMINI_CONFLICT_MODELS", "") or env.get("GEMINI_CONFLICT_MODEL", "")
    models = split_secret_bundle(raw)
    return models or list(DEFAULT_MODELS)


def credential_preflight() -> int:
    env = credential_environment()
    keys = collect_api_keys(env)
    models = configured_models(env)
    print(f"gemini_credentials_configured={len(keys)}")
    print(f"gemini_models_configured={len(models)}")
    print("gemini_credential_rotation_enabled=true")
    print("secret_values_printed=false")
    if not keys:
        print("::error::no_gemini_credentials_configured", file=sys.stderr)
        return 3
    return 0


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        ["git", *args],
        check=check,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )


def conflicted_files() -> list[str]:
    result = run_git("diff", "--name-only", "--diff-filter=U", "-z")
    return [item.decode("utf-8") for item in result.stdout.split(b"\0") if item]


def conflict_modes(path: str) -> set[str]:
    result = run_git("ls-files", "-u", "--", path)
    modes: set[str] = set()
    for raw in result.stdout.decode("utf-8", errors="replace").splitlines():
        fields = raw.split()
        if fields:
            modes.add(fields[0])
    return modes


def git_stage(path: str, stage: int) -> bytes:
    result = run_git("show", f":{stage}:{path}", check=False)
    return result.stdout if result.returncode == 0 else b""


def decode_source(raw: bytes, path: str) -> str:
    if b"\0" in raw:
        raise ValueError(f"binary_conflict:{path}")
    if len(raw) > MAX_FILE_BYTES:
        raise ValueError(f"conflict_file_too_large:{path}")
    return raw.decode("utf-8", errors="strict")


def build_prompt(path: str, base: str, ours: str, theirs: str) -> str:
    return f"""You are the ShopVivaliz PR Conflict Auto-Healer.
Resolve exactly one Git merge conflict. Return ONLY valid JSON with one key named \"resolved\" whose value is the complete final UTF-8 file content.

Mandatory rules:
1. Preserve the intended behavior of BOTH the PR branch (OURS) and current main (THEIRS) whenever compatible.
2. Never delete tests, validations, security checks, branch protections, logging, backups, fail-closed behavior, or error handling merely to make a conflict disappear.
3. Never introduce credentials, tokens, passwords, private keys, secret values, or placeholder credentials.
4. Never weaken permissions or convert a failing condition into unconditional success.
5. Keep the repository's existing style and syntax. Do not add commentary outside the file.
6. The final file must contain no Git conflict markers.
7. If a policy/rule from main conflicts with older branch text, retain the stricter current policy unless the PR explicitly strengthens it further.

PATH: {path}

<BASE>
{base}
</BASE>

<OURS_PR_BRANCH>
{ours}
</OURS_PR_BRANCH>

<THEIRS_CURRENT_MAIN>
{theirs}
</THEIRS_CURRENT_MAIN>
"""


def _extract_candidate_text(payload: Mapping[str, object]) -> str:
    candidates = payload.get("candidates")
    if not isinstance(candidates, list) or not candidates:
        return ""
    first = candidates[0]
    if not isinstance(first, dict):
        return ""
    content = first.get("content")
    if not isinstance(content, dict):
        return ""
    parts = content.get("parts")
    if not isinstance(parts, list):
        return ""
    for part in parts:
        if isinstance(part, dict) and isinstance(part.get("text"), str):
            return part["text"]
    return ""


def call_gemini(api_key: str, model: str, prompt: str) -> GeminiResult:
    endpoint = (
        "https://generativelanguage.googleapis.com/v1beta/models/"
        + urllib.parse.quote(model, safe="")
        + ":generateContent"
    )
    body = json.dumps(
        {
            "contents": [{"role": "user", "parts": [{"text": prompt}]}],
            "generationConfig": {"responseMimeType": "application/json"},
        },
        ensure_ascii=False,
    ).encode("utf-8")
    request = urllib.request.Request(
        endpoint,
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "x-goog-api-key": api_key,
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=90) as response:
            payload = json.loads(response.read().decode("utf-8"))
            return GeminiResult(True, int(response.status), _extract_candidate_text(payload))
    except urllib.error.HTTPError as exc:
        # Deliberately do not print response bodies: provider errors can contain
        # request metadata and are unnecessary for credential rotation.
        return GeminiResult(False, int(exc.code), "")
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError):
        return GeminiResult(False, 0, "")


def parse_resolution(raw: str) -> str:
    text = raw.strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
    payload = json.loads(text)
    resolved = payload.get("resolved") if isinstance(payload, dict) else None
    if not isinstance(resolved, str):
        raise ValueError("missing_resolved_string")
    if any(marker in resolved for marker in CONFLICT_MARKERS):
        raise ValueError("conflict_marker_remains")
    return resolved


def resolve_file(path: str, keys: Sequence[str], models: Sequence[str]) -> tuple[str, int]:
    modes = conflict_modes(path)
    if not modes or not modes.issubset({"100644", "100755"}):
        raise ValueError(f"unsupported_conflict_mode:{path}")

    base = decode_source(git_stage(path, 1), path)
    ours = decode_source(git_stage(path, 2), path)
    theirs = decode_source(git_stage(path, 3), path)
    prompt = build_prompt(path, base, ours, theirs)

    attempts = 0
    for key in keys:
        key_unavailable = False
        for model in models:
            attempts += 1
            result = call_gemini(key, model, prompt)
            if result.ok:
                try:
                    resolved = parse_resolution(result.text)
                except (json.JSONDecodeError, ValueError):
                    continue
                return resolved, attempts

            # Authentication/quota/permission errors are credential-scoped.
            if result.status in {401, 403, 429}:
                key_unavailable = True
                break
            # Model/shape errors may be model-scoped; try the fallback model.
            if result.status in {400, 404}:
                continue
            # Transport/5xx: rotate credentials rather than getting stuck.
            if result.status == 0 or result.status >= 500:
                key_unavailable = True
                break
        if key_unavailable:
            continue
    raise RuntimeError(f"gemini_pool_exhausted_for:{path}:attempts={attempts}")


def write_and_stage(path: str, content: str) -> None:
    target = Path(path)
    target.write_text(content, encoding="utf-8")
    run_git("add", "--", path)


def main() -> int:
    args = sys.argv[1:]
    if args == ["--credential-preflight"]:
        return credential_preflight()
    if args:
        print("::error::unsupported_arguments", file=sys.stderr)
        return 2

    files = conflicted_files()
    if not files:
        print("conflicts_detected=0")
        return 0
    if len(files) > MAX_CONFLICT_FILES:
        print(f"::error::too_many_conflicts={len(files)}", file=sys.stderr)
        return 2

    env = credential_environment()
    keys = collect_api_keys(env)
    models = configured_models(env)
    print(f"conflicts_detected={len(files)}")
    print(f"gemini_credentials_configured={len(keys)}")
    print(f"gemini_models_configured={len(models)}")
    if not keys:
        print("::error::no_gemini_credentials_configured", file=sys.stderr)
        return 3

    total_attempts = 0
    try:
        for path in files:
            resolved, attempts = resolve_file(path, keys, models)
            total_attempts += attempts
            write_and_stage(path, resolved)
            print(f"resolved_file={path}")
    except Exception as exc:  # fail closed; no secret values are part of errors
        print(f"::error::{exc}", file=sys.stderr)
        return 4

    remaining = conflicted_files()
    if remaining:
        print(f"::error::unmerged_files_remaining={len(remaining)}", file=sys.stderr)
        return 5
    print(f"gemini_attempts_total={total_attempts}")
    print("conflict_resolution_validated=true")
    print("secret_values_printed=false")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
