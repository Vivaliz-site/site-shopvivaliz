from __future__ import annotations

import json
import os
import subprocess
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

PROFILES = ('fredmourao', 'marinaofaleiro')
FAILOVER_PATTERNS = (
    'usage limit', 'rate limit', 'quota', 'too many requests',
    'authentication required', 'unauthorized', 'token expired',
    'refresh token', 'not logged in', 'limit reached',
)
CANCEL_CODES = {130, -2}


@dataclass(frozen=True)
class AttemptResult:
    profile: str
    returncode: int
    stdout: str = ''
    stderr: str = ''


class NoUsableProfile(RuntimeError):
    pass


def classify_failure(returncode: int, stderr: str, stdout: str = '') -> str:
    if returncode == 0:
        return 'success'
    if returncode in CANCEL_CODES:
        return 'cancelled'
    text = f'{stderr}\n{stdout}'.lower()
    return 'failover' if any(pattern in text for pattern in FAILOVER_PATTERNS) else 'terminal'


def ordered_profiles(preferred: str) -> tuple[str, str]:
    if preferred not in PROFILES:
        preferred = PROFILES[0]
    other = PROFILES[1] if preferred == PROFILES[0] else PROFILES[0]
    return preferred, other


def write_state(path: Path, preferred: str, result_class: str, attempts: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        'preferred_profile': preferred if preferred in PROFILES else PROFILES[0],
        'updated_at': datetime.now(timezone.utc).isoformat(),
        'result_class': result_class,
        'attempts': int(attempts),
    }
    tmp = path.with_suffix(path.suffix + '.tmp')
    tmp.write_text(json.dumps(payload, sort_keys=True) + '\n', encoding='utf-8')
    os.replace(tmp, path)


def read_state(path: Path) -> dict:
    if not path.exists():
        return {'preferred_profile': PROFILES[0]}
    try:
        data = json.loads(path.read_text(encoding='utf-8'))
    except (OSError, json.JSONDecodeError):
        return {'preferred_profile': PROFILES[0]}
    if data.get('preferred_profile') not in PROFILES:
        data['preferred_profile'] = PROFILES[0]
    return data


def select_profile(preferred: str, probe_runner: Callable[[str], AttemptResult]) -> tuple[str, int]:
    attempts = 0
    last_class = 'terminal'
    for profile in ordered_profiles(preferred):
        attempts += 1
        result = probe_runner(profile)
        result_class = classify_failure(result.returncode, result.stderr, result.stdout)
        if result_class == 'success':
            return profile, attempts
        if result_class != 'failover':
            last_class = result_class
            break
        last_class = result_class
    raise NoUsableProfile(last_class)


def run_model_command(
    argv: list[str],
    preferred: str,
    probe_runner: Callable[[str], AttemptResult],
    task_runner: Callable[[str, list[str]], AttemptResult],
) -> tuple[int, str, str]:
    profile, _ = select_profile(preferred, probe_runner)
    result = task_runner(profile, argv)
    result_class = classify_failure(result.returncode, result.stderr, result.stdout)
    return result.returncode, profile, result_class


MODEL_COMMANDS = {'exec', 'e', 'review'}
ADMIN_FLAGS = {'--version', '-V', '--help', '-h'}
ADMIN_COMMANDS = {
    'login', 'logout', 'doctor', 'mcp', 'plugin', 'completion', 'features',
    'update', 'agents', 'remote-control', 'cloud', 'debug', 'sandbox', 'apply',
    'resume', 'queue', 'archive', 'delete', 'migrate-rollouts', 'unarchive',
    'fork', 'app-server', 'exec-server', 'help',
}
PROBE_TIMEOUT_SECONDS = 45
PROBE_ARGS = [
    '--ask-for-approval', 'never',
    'exec', '--ephemeral', '--skip-git-repo-check', '--ignore-user-config',
    '--ignore-rules', '--sandbox', 'read-only',
    'Reply exactly PROFILE_OK and do not use tools.',
]


def command_mode(argv: list[str]) -> str:
    if not argv:
        return 'interactive'
    first = argv[0]
    if first in ADMIN_FLAGS:
        return 'admin'
    for token in argv:
        if token == '--':
            break
        if token in MODEL_COMMANDS:
            return 'model'
    if first in ADMIN_COMMANDS:
        return 'admin'
    return 'interactive'


def preference_after_task(selected: str, result_class: str) -> str:
    if selected not in PROFILES:
        selected = PROFILES[0]
    if result_class != 'failover':
        return selected
    return PROFILES[1] if selected == PROFILES[0] else PROFILES[0]


def probe_profile(real_codex: str, profile: str, profile_home: Path) -> AttemptResult:
    env = _profile_env(profile_home)
    try:
        completed = subprocess.run(
            [real_codex, *PROBE_ARGS],
            env=env,
            text=True,
            capture_output=True,
            timeout=PROBE_TIMEOUT_SECONDS,
            stdin=subprocess.DEVNULL,
            check=False,
        )
        return AttemptResult(profile, completed.returncode, completed.stdout, completed.stderr)
    except subprocess.TimeoutExpired:
        return AttemptResult(profile, 124, '', 'probe timeout')


def run_captured(
    real_codex: str,
    profile: str,
    profile_home: Path,
    argv: list[str],
    timeout: float | None = None,
) -> AttemptResult:
    env = os.environ.copy()
    env['CODEX_HOME'] = str(profile_home)
    env.pop('OPENAI_API_KEY', None)
    env.pop('CODEX_API_KEY', None)
    try:
        completed = subprocess.run(
            [real_codex, *argv],
            env=env,
            text=True,
            capture_output=True,
            timeout=timeout,
            check=False,
        )
        return AttemptResult(profile, completed.returncode, completed.stdout, completed.stderr)
    except subprocess.TimeoutExpired:
        return AttemptResult(profile, 124, '', 'probe timeout')


def _resolve_real_codex(business_home: Path) -> str:
    env_real = os.environ.get('CODEX_REAL', '').strip()
    if env_real:
        return env_real
    path_file = business_home / 'codex-real-path'
    if path_file.is_file():
        value = path_file.read_text(encoding='utf-8').strip()
        if value:
            return value
    raise FileNotFoundError('real Codex executable path is not configured')


def _emit(result: AttemptResult) -> None:
    if result.stdout:
        sys.stdout.write(result.stdout)
        sys.stdout.flush()
    if result.stderr:
        sys.stderr.write(result.stderr)
        sys.stderr.flush()


def _profile_env(profile_home: Path) -> dict[str, str]:
    env = os.environ.copy()
    env['CODEX_HOME'] = str(profile_home)
    env.pop('OPENAI_API_KEY', None)
    env.pop('CODEX_API_KEY', None)
    return env


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv[1:] if argv is None else argv)
    business = Path.home() / '.codex-business'
    state_path = business / 'failover-state.json'
    state = read_state(state_path)
    preferred = state.get('preferred_profile', PROFILES[0])
    real_codex = _resolve_real_codex(business)
    mode = command_mode(argv)

    if mode == 'admin':
        profile = preferred if preferred in PROFILES else PROFILES[0]
        result = run_captured(real_codex, profile, business / profile, argv)
        _emit(result)
        result_class = classify_failure(result.returncode, result.stderr, result.stdout)
        write_state(state_path, profile, result_class, 1)
        return result.returncode

    def probe(profile: str) -> AttemptResult:
        return probe_profile(real_codex, profile, business / profile)

    try:
        selected, probe_attempts = select_profile(preferred, probe)
    except NoUsableProfile as exc:
        write_state(state_path, preferred, str(exc) or 'unavailable', 2)
        sys.stderr.write('Codex native failover: no usable ChatGPT profile is available.\n')
        return 78

    if mode == 'model':
        result = run_captured(real_codex, selected, business / selected, argv)
        _emit(result)
        result_class = classify_failure(result.returncode, result.stderr, result.stdout)
        next_preferred = preference_after_task(selected, result_class)
        write_state(state_path, next_preferred, result_class, probe_attempts + 1)
        return result.returncode

    write_state(state_path, selected, 'selected', probe_attempts)
    completed = subprocess.run(
        [real_codex, *argv],
        env=_profile_env(business / selected),
        check=False,
    )
    return completed.returncode


if __name__ == '__main__':
    raise SystemExit(main())
