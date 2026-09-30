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
import tempfile
from pathlib import Path
from typing import Mapping

DEFAULT_ENV_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/.env")
DEFAULT_GEMINI_BIN = Path("/home/ubuntu/.local/bin/gemini")
SUPPORTED_KEYS = ("GEMINI_API_KEY", "GOOGLE_API_KEY", "GOOGLE_GEMINI_API_KEY")
DEFAULT_FALLBACK_MODELS = ("gemini-flash-lite-latest",)
RETRYABLE_GEMINI_REASONS = frozenset({"quota_exhausted", "model_unavailable", "authentication_failed"})
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


def select_gemini_credentials(text: str) -> list[tuple[str, str]]:
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

    credentials: list[tuple[str, str]] = []
    seen_values: set[str] = set()
    for key in SUPPORTED_KEYS:
        value = values.get(key, "").strip()
        if value and value not in seen_values:
            credentials.append((key, value))
            seen_values.add(value)
    return credentials


def select_gemini_credential(text: str) -> tuple[str, str]:
    credentials = select_gemini_credentials(text)
    return credentials[0] if credentials else ("", "")


def build_model_candidates(primary: str, fallbacks: tuple[str, ...]) -> list[str]:
    candidates: list[str] = []
    for value in (primary, *fallbacks):
        model = str(value).strip()
        if model and model not in candidates:
            candidates.append(model)
    return candidates


def redact_credentials(output: str, credentials: list[tuple[str, str]]) -> str:
    redacted = output
    for _source, credential in credentials:
        if credential:
            redacted = redacted.replace(credential, "***")
    return redacted


def build_child_env(base: Mapping[str, str], credential: str) -> dict[str, str]:
    child = dict(base)
    for key in SECRET_KEYS_TO_REMOVE:
        child.pop(key, None)
    child["GEMINI_API_KEY"] = credential
    child["GEMINI_CLI_TRUST_WORKSPACE"] = "true"
    return child



HEADLESS_SHELL_PREFIXES: tuple[str, ...] = (
    "python3 scripts/agent_task_state.py",
    "./scripts/agent_task_state.py",
    "python3 -m unittest",
    "git status",
    "git diff",
    "git add",
    "git commit",
    "python3 scripts/safe_git_push.py",
    "gh pr",
    "bash tests/",
    "bash scripts/repository-governance-validate.sh",
)


def build_headless_tools_core() -> list[str]:
    """Bounded list of tools this headless recovery run may register.

    Non-interactive `--prompt` + `--approval-mode auto_edit` never
    registers run_shell_command at all without this (upstream:
    google-gemini/gemini-cli#20469, "Tool 'run_shell_command' not
    found"). `tools.core` in `.gemini/settings.json` gates tool
    *registration*. Registration alone is not authorization, though: see
    build_headless_admin_policy_text().
    """
    return [f"run_shell_command({prefix})" for prefix in HEADLESS_SHELL_PREFIXES]


def build_gemini_settings_json(tools_core: list[str]) -> str:
    return json.dumps({"tools": {"core": tools_core}}, indent=2, sort_keys=True) + "\n"


def build_headless_admin_policy_text(prefixes: tuple[str, ...]) -> str:
    """Explicit allow rule for the same bounded prefixes tools.core registers.

    Per the current upstream policy-engine docs, a run_shell_command call
    that matches no rule falls back to the "ask_user" decision, which is
    treated as "deny" in non-interactive mode — confirmed by production
    evidence (run 36296738560): tools.core alone fixed registration but
    still exited nonzero with no explicit approval. commandPrefix/toolName
    accept arrays, so one rule covers every bounded prefix; the admin tier
    (highest priority) ensures this allow always wins.
    """
    quoted = ", ".join(repr(prefix) for prefix in prefixes)
    return (
        "[[rule]]\n"
        'toolName = "run_shell_command"\n'
        f"commandPrefix = [{quoted}]\n"
        'decision = "allow"\n'
        "priority = 900\n"
        "interactive = false\n"
    )


def build_gemini_command(
    *, executable: str, model: str, prompt: str, admin_policy_path: Path
) -> list[str]:
    return [
        executable,
        "--model",
        model,
        "--approval-mode",
        "auto_edit",
        "--admin-policy",
        str(admin_policy_path),
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
    fallback_models: tuple[str, ...] = DEFAULT_FALLBACK_MODELS,
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

    credentials = select_gemini_credentials(env_text)
    if not credentials:
        print("background_gemini_error=credential_missing", file=sys.stderr)
        return 78

    executable = _resolve_binary(gemini_bin)
    if not executable:
        print("background_gemini_error=cli_missing", file=sys.stderr)
        return 127

    prompt = prompt_file.read_text(encoding="utf-8", errors="strict")
    models = build_model_candidates(model, fallback_models)
    gemini_dir = Path(".gemini")
    settings_path = gemini_dir / "settings.json"
    gemini_dir_created = not gemini_dir.exists()
    settings_created = False
    policy_path: Path | None = None
    try:
        gemini_dir.mkdir(parents=True, exist_ok=True)
        settings_path.write_text(
            build_gemini_settings_json(build_headless_tools_core()),
            encoding="utf-8",
        )
        settings_created = True
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            prefix="shopvivaliz-gemini-admin-policy-",
            suffix=".toml",
            delete=False,
        ) as handle:
            handle.write(build_headless_admin_policy_text(HEADLESS_SHELL_PREFIXES))
            handle.flush()
            policy_path = Path(handle.name)
        last_output = ""
        last_returncode = 1
        last_reason = "unknown_safe_error"
        last_source = ""
        last_model = ""
        stop_all = False

        for source, credential in credentials:
            child = build_child_env(os.environ, credential)
            for candidate_model in models:
                command = build_gemini_command(
                    executable=executable,
                    model=candidate_model,
                    prompt=prompt,
                    admin_policy_path=policy_path,
                )
                completed = subprocess.run(
                    command,
                    env=child,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.STDOUT,
                    text=True,
                    check=False,
                )
                output = redact_credentials(completed.stdout or "", credentials)
                reason = classify_gemini_failure(output, completed.returncode)
                print(
                    "background_gemini_attempt="
                    f"credential_source:{source},model:{candidate_model},"
                    f"exit_code:{completed.returncode},reason:{reason or 'success'}"
                )

                if completed.returncode == 0:
                    if output:
                        sys.stdout.write(output)
                        if not output.endswith("\n"):
                            sys.stdout.write("\n")
                    print(f"background_gemini_credential_source={source}")
                    print(f"background_gemini_model={candidate_model}")
                    print("background_gemini_exit_code=0")
                    return 0

                last_output = output
                last_returncode = int(completed.returncode)
                last_reason = reason or "unknown_safe_error"
                last_source = source
                last_model = candidate_model

                if last_reason == "authentication_failed":
                    break
                if last_reason in {"quota_exhausted", "model_unavailable"}:
                    continue

                stop_all = True
                break
            if stop_all:
                break
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
        if policy_path is not None:
            policy_path.unlink(missing_ok=True)

    if last_output:
        sys.stdout.write(last_output)
        if not last_output.endswith("\n"):
            sys.stdout.write("\n")
    print(f"background_gemini_credential_source={last_source}")
    print(f"background_gemini_model={last_model}")
    print(f"background_gemini_exit_code={last_returncode}")
    print(f"background_gemini_reason={last_reason}")
    return int(last_returncode)


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
    parser.add_argument(
        "--fallback-model",
        action="append",
        default=None,
        help="Gemini-only fallback model; may be repeated.",
    )
    args = parser.parse_args()
    configured_fallbacks = tuple(
        value.strip()
        for value in os.getenv("GEMINI_FALLBACK_MODELS", "").split(",")
        if value.strip()
    )
    fallback_models = tuple(args.fallback_model or configured_fallbacks or DEFAULT_FALLBACK_MODELS)
    return run(
        model=args.model,
        prompt_file=Path(args.prompt_file).expanduser(),
        env_file=Path(args.env_file).expanduser(),
        gemini_bin=args.gemini_bin,
        fallback_models=fallback_models,
    )


if __name__ == "__main__":
    raise SystemExit(main())
