#!/usr/bin/env python3
"""Run Gemini CLI safely for detached background task continuation.

This wrapper deliberately reads only the Gemini credential from the protected
runtime env file. It does not source the full .env into the daemon process and
removes unrelated AI provider secrets from the Gemini child environment.
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Mapping

DEFAULT_ENV_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/.env")
DEFAULT_GEMINI_BIN = Path("/home/ubuntu/.local/bin/gemini")
SUPPORTED_KEYS = ("GEMINI_API_KEY", "GOOGLE_API_KEY", "GOOGLE_GEMINI_API_KEY")
SECRET_KEYS_TO_REMOVE = (
    "OPENAI_API_KEY",
    "CODEX_API_KEY",
    "ANTHROPIC_API_KEY",
    "GOOGLE_API_KEY",
    "GOOGLE_GEMINI_API_KEY",
)


def _unquote(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
        return value[1:-1]
    return value


def select_gemini_credential(text: str) -> tuple[str, str]:
    values: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key, value = line.split("=", 1)
        key = key.strip()
        if key in SUPPORTED_KEYS:
            values[key] = _unquote(value)
    for key in SUPPORTED_KEYS:
        value = values.get(key, "").strip()
        if value:
            return key, value
    return "", ""


def build_child_env(base: Mapping[str, str], credential: str) -> dict[str, str]:
    child = dict(base)
    for key in SECRET_KEYS_TO_REMOVE:
        child.pop(key, None)
    child["GEMINI_API_KEY"] = credential
    child["GEMINI_CLI_TRUST_WORKSPACE"] = "true"
    return child



def build_headless_tools_core() -> list[str]:
    """Bounded list of tools this headless recovery run may register.

    gemini-cli's --admin-policy TOML rules for run_shell_command are
    silently ignored in non-interactive `--prompt` + `--approval-mode
    auto_edit` mode (upstream: google-gemini/gemini-cli#20469 — the tool
    is not even registered, "Tool 'run_shell_command' not found").
    `tools.core` in `.gemini/settings.json` gates tool registration
    itself, so it is not subject to that policy-engine bypass.
    """
    prefixes = (
        "python3 scripts/agent_task_state.py",
        "./scripts/agent_task_state.py",
        "python3 -m unittest",
        "git status",
        "git diff",
        "git add",
        "git commit",
        "git push",
        "gh pr",
        "bash tests/",
        "bash scripts/repository-governance-validate.sh",
    )
    return [f"run_shell_command({prefix})" for prefix in prefixes]


def build_gemini_settings_json(tools_core: list[str]) -> str:
    return json.dumps({"tools": {"core": tools_core}}, indent=2, sort_keys=True) + "\n"


def build_gemini_command(*, executable: str, model: str, prompt: str) -> list[str]:
    return [
        executable,
        "--model",
        model,
        "--approval-mode",
        "auto_edit",
        "--prompt",
        prompt,
    ]


_FAILURE_CLASSIFIERS: tuple[tuple[str, tuple[str, ...]], ...] = (
    (
        "approval_required",
        (
            "waiting for user confirmation",
            "requires approval",
            "confirmation required",
        ),
    ),
    (
        "tool_not_registered",
        (
            "tool 'run_shell_command' not found",
            "tool not found",
        ),
    ),
    (
        "tool_not_allowed",
        (
            "not in the list of allowed tools",
            "not allowed",
            "blocked by policy",
        ),
    ),
    (
        "authentication_failed",
        (
            "permission_denied",
            "authentication failed",
            "unauthorized",
            "invalid api key",
        ),
    ),
    (
        "quota_exhausted",
        (
            "resource_exhausted",
            "quota exceeded",
            "rate limit",
        ),
    ),
    (
        "model_unavailable",
        (
            "not_found",
            "is not available",
            "model not found",
        ),
    ),
    (
        "workspace_untrusted",
        (
            "not trusted",
            "trust this folder",
        ),
    ),
)


def classify_gemini_failure(output: str, returncode: int) -> str | None:
    """Classify a failed run into a bounded, allowlisted reason code.

    Only the classification survives past this function — callers must never
    persist `output` itself. Unmatched or empty output on failure still
    yields a safe, non-empty classification.
    """
    if returncode == 0:
        return None
    haystack = output.lower()
    for reason, needles in _FAILURE_CLASSIFIERS:
        if any(needle in haystack for needle in needles):
            return reason
    return "unknown_safe_error"


def _resolve_binary(configured: str) -> str:
    candidate = Path(configured).expanduser()
    if candidate.is_file() and os.access(candidate, os.X_OK):
        return str(candidate)
    fallback = shutil.which("gemini")
    return fallback or ""


def run(
    *,
    model: str,
    prompt_file: Path,
    env_file: Path,
    gemini_bin: str,
) -> int:
    if not prompt_file.is_file():
        print("background_gemini_error=prompt_file_missing", file=sys.stderr)
        return 66
    if not env_file.is_file():
        print("background_gemini_error=runtime_env_missing", file=sys.stderr)
        return 78

    try:
        env_text = env_file.read_text(encoding="utf-8", errors="strict")
    except OSError:
        print("background_gemini_error=runtime_env_unreadable", file=sys.stderr)
        return 78

    source, credential = select_gemini_credential(env_text)
    if not credential:
        print("background_gemini_error=credential_missing", file=sys.stderr)
        return 78

    executable = _resolve_binary(gemini_bin)
    if not executable:
        print("background_gemini_error=cli_missing", file=sys.stderr)
        return 127

    prompt = prompt_file.read_text(encoding="utf-8", errors="strict")
    child = build_child_env(os.environ, credential)
    gemini_dir = Path(".gemini")
    settings_path = gemini_dir / "settings.json"
    gemini_dir_created = not gemini_dir.exists()
    settings_created = False
    try:
        gemini_dir.mkdir(parents=True, exist_ok=True)
        settings_path.write_text(
            build_gemini_settings_json(build_headless_tools_core()),
            encoding="utf-8",
        )
        settings_created = True
        command = build_gemini_command(
            executable=executable,
            model=model,
            prompt=prompt,
        )
        completed = subprocess.run(
            command,
            env=child,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            check=False,
        )
    except OSError:
        print("background_gemini_error=exec_failed", file=sys.stderr)
        return 127
    finally:
        if settings_created:
            settings_path.unlink(missing_ok=True)
        if gemini_dir_created:
            try:
                gemini_dir.rmdir()
            except OSError:
                pass

    output = (completed.stdout or "").replace(credential, "***")
    if output:
        sys.stdout.write(output)
        if not output.endswith("\n"):
            sys.stdout.write("\n")
    print(f"background_gemini_credential_source={source}")
    print(f"background_gemini_exit_code={completed.returncode}")
    reason = classify_gemini_failure(output, completed.returncode)
    if reason is not None:
        print(f"background_gemini_reason={reason}")
    return int(completed.returncode)


def main() -> int:
    parser = argparse.ArgumentParser(description="Run protected Gemini CLI background continuation.")
    parser.add_argument("--model", required=True)
    parser.add_argument("--prompt-file", required=True)
    parser.add_argument(
        "--env-file",
        default=os.getenv("GEMINI_ENV_FILE", str(DEFAULT_ENV_FILE)),
    )
    parser.add_argument(
        "--gemini-bin",
        default=os.getenv("SHOPVIVALIZ_GEMINI_BIN", str(DEFAULT_GEMINI_BIN)),
    )
    args = parser.parse_args()
    return run(
        model=args.model,
        prompt_file=Path(args.prompt_file).expanduser(),
        env_file=Path(args.env_file).expanduser(),
        gemini_bin=args.gemini_bin,
    )


if __name__ == "__main__":
    raise SystemExit(main())
