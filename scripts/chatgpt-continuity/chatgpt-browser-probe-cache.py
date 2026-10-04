#!/usr/bin/env python3
"""Bound negative authentication probes without caching positive readiness.

Only local CDP metadata is queried. Page contents, query strings, cookies and
credentials are never stored. An unchanged negative observation is reusable for
at most five minutes; task-set, page-path or browser identity changes wake it.
"""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
from urllib.parse import urlsplit

NEGATIVE = {'AUTH_FLOW', 'AUTH_TERMINAL', 'LOGGED_OUT'}
STATES = NEGATIVE | {'AUTHENTICATED', 'UNKNOWN', 'UNREACHABLE'}
MAX_WAIT_SECONDS = 300
MAX_JSON = 1_048_576


def read_json(path: Path):
    with path.open('rb') as handle:
        raw = handle.read(MAX_JSON + 1)
    if len(raw) > MAX_JSON:
        raise ValueError('oversized metadata')
    return json.loads(raw)


def stamp() -> str:
    return datetime.now(timezone.utc).isoformat(timespec='milliseconds')


def age_seconds(value: str) -> float:
    observed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if observed.tzinfo is None:
        raise ValueError('timestamp without timezone')
    return (datetime.now(timezone.utc) - observed).total_seconds()


def atomic_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.' + path.name + '.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as handle:
            json.dump(data, handle, sort_keys=True, separators=(',', ':'))
            handle.write('\n')
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def context_digest(base: str, tasks: Path) -> str:
    parsed = urlsplit(base)
    if parsed.scheme != 'http' or parsed.hostname not in {'127.0.0.1', '::1', 'localhost'}:
        raise ValueError('loopback CDP required')
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError('invalid CDP base')

    def local_json(suffix: str):
        result = subprocess.run(
            ['curl', '-fsS', '--connect-timeout', '1', '--max-time', '2', base.rstrip('/') + suffix],
            capture_output=True, timeout=3, check=True,
        )
        if len(result.stdout) > MAX_JSON:
            raise ValueError('oversized CDP metadata')
        return json.loads(result.stdout)

    version = local_json('/json/version')
    browser = version.get('webSocketDebuggerUrl') if isinstance(version, dict) else None
    if not isinstance(browser, str) or not browser.startswith(('ws://', 'wss://')):
        raise ValueError('missing browser identity')
    pages = local_json('/json')
    if not isinstance(pages, list) or len(pages) > 512:
        raise ValueError('invalid page list')
    page_keys = []
    for page in pages:
        if not isinstance(page, dict) or page.get('type') != 'page':
            continue
        url = urlsplit(str(page.get('url', '')))
        if url.hostname in {'chatgpt.com', 'auth.openai.com', 'accounts.google.com', 'login.microsoftonline.com', 'appleid.apple.com'}:
            # Exclude OAuth state, nonce, code, query strings and fragments.
            page_keys.append((str(page.get('id', '')), url.scheme, url.hostname, url.path))
    if not page_keys or not tasks.is_dir():
        raise ValueError('missing probe context')
    checkpoints = []
    for index, path in enumerate(tasks.glob('*.json')):
        if index >= 4096:
            raise ValueError('too many checkpoints')
        if path.name.startswith('_'):
            continue
        task = read_json(path)
        if not isinstance(task, dict):
            raise ValueError('invalid checkpoint')
        if task.get('status') in {'RUNNING', 'READY_TO_COMPLETE'}:
            checkpoints.append((path.name, str(task.get('task_id', '')), str(task['status'])))
    raw = json.dumps([browser, sorted(page_keys), sorted(checkpoints)], separators=(',', ':'))
    return hashlib.sha256(raw.encode()).hexdigest()


def record(path: Path, state: str, base: str, tasks: Path) -> None:
    if state not in STATES:
        state = 'UNKNOWN'
    observed = stamp()
    digest = ''
    if state in NEGATIVE:
        try:
            digest = context_digest(base, tasks)
        except (OSError, ValueError, TypeError, subprocess.SubprocessError):
            pass  # Missing context means no deferral, never a healthy result.
    atomic_json(path, {
        'schema_version': 1, 'updated_at': observed,
        'session_observed_at': observed, 'session_state': state,
        'authenticated': state == 'AUTHENTICATED', 'degraded': state != 'AUTHENTICATED',
        'probe_skipped': False, 'probe_interval_seconds': MAX_WAIT_SECONDS,
        'probe_context': digest, 'transport_checked_at': observed if digest else None,
    })


def reuse_negative(path: Path, base: str, tasks: Path) -> str | None:
    data = read_json(path)
    if not isinstance(data, dict) or data.get('session_state') not in NEGATIVE or data.get('authenticated') is not False:
        return None
    age = age_seconds(data.get('session_observed_at', ''))
    if not 0 <= age < MAX_WAIT_SECONDS:
        return None
    if not data.get('probe_context') or context_digest(base, tasks) != data['probe_context']:
        return None
    now = stamp()
    data.update(updated_at=now, transport_checked_at=now, probe_skipped=True, degraded=True)
    atomic_json(path, data)
    return data['session_state']


def verify_ready(path: Path) -> bool:
    data = read_json(path)
    return (isinstance(data, dict) and data.get('authenticated') is True
            and data.get('session_state') == 'AUTHENTICATED'
            and data.get('probe_skipped') is False
            and 0 <= age_seconds(data.get('session_observed_at', '')) <= 90)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=['check', 'record', 'verify-ready'])
    parser.add_argument('--health', required=True, type=Path)
    parser.add_argument('--base', default='http://127.0.0.1:9556')
    parser.add_argument('--tasks', type=Path, default=Path('/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state'))
    parser.add_argument('--state', default='UNKNOWN', choices=sorted(STATES))
    args = parser.parse_args()
    try:
        if args.operation == 'record':
            record(args.health, args.state, args.base, args.tasks)
            return 0
        if args.operation == 'verify-ready':
            ready = verify_ready(args.health)
            print('CHATGPT_CONTINUITY_BACKEND_READY=' + ('PASS' if ready else 'FAIL'))
            return 0 if ready else 1
        cached = reuse_negative(args.health, args.base, args.tasks)
        if cached:
            print(cached)
            return 0
        return 1
    except (OSError, ValueError, TypeError, AttributeError, subprocess.SubprocessError):
        if args.operation == 'verify-ready':
            print('CHATGPT_CONTINUITY_BACKEND_READY=FAIL')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
