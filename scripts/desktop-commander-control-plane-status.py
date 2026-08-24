#!/usr/bin/env python3
import argparse
import datetime as dt
import json
from pathlib import Path

ALLOWED_KEYS = {
    'CONTROL_REACHABLE', 'DEVICE_STATE_EXISTS', 'CANONICAL_AGENT_COUNT',
    'NONCANONICAL_AGENT_COUNT', 'TASK_EXISTS', 'TASK_STATE',
    'TASK_LOGON_TYPE', 'TASK_RUN_LEVEL', 'AUTH_REQUIRED',
    'SERVICE_ENABLED', 'SERVICE_ACTIVE', 'CANONICAL_REMOTE_COUNT',
    'NONCANONICAL_REMOTE_COUNT'
}
HOSTS = ('LAPTOP-NIG4IFUU', 'shopvivaliz-ai', 'DESKTOP-KOCEPSV')


def parse_status(path: str) -> dict:
    values = {}
    p = Path(path)
    if not p.exists():
        return values
    for raw in p.read_text(encoding='utf-8', errors='replace').splitlines():
        if '=' not in raw:
            continue
        key, value = raw.split('=', 1)
        key = key.strip()
        if key in ALLOWED_KEYS:
            values[key] = value.strip()
    return values


def is_true(value) -> bool:
    return str(value).strip().lower() == 'true'


def int_value(value, default=0) -> int:
    try:
        return int(str(value).strip())
    except (TypeError, ValueError):
        return default


def windows_host(name: str, values: dict, recovery: dict, run_id: str, now: str) -> dict:
    healthy = (
        is_true(values.get('CONTROL_REACHABLE'))
        and is_true(values.get('DEVICE_STATE_EXISTS'))
        and is_true(values.get('TASK_EXISTS'))
        and values.get('TASK_LOGON_TYPE', '').lower() == 's4u'
        and values.get('TASK_RUN_LEVEL', '').lower() == 'highest'
        and int_value(values.get('CANONICAL_AGENT_COUNT')) == 1
        and int_value(values.get('NONCANONICAL_AGENT_COUNT')) == 0
        and not is_true(values.get('AUTH_REQUIRED'))
    )
    if not is_true(values.get('CONTROL_REACHABLE')):
        state = 'unreachable'
    else:
        state = 'healthy' if healthy else 'degraded'
    task_state = values.get('TASK_STATE', 'missing') if is_true(values.get('TASK_EXISTS')) else 'missing'
    return {
        'host': name,
        'state': state,
        'watchdog': 'ready' if healthy else task_state.lower(),
        'canonical_agent_count': int_value(values.get('CANONICAL_AGENT_COUNT')),
        'auth_required': is_true(values.get('AUTH_REQUIRED')),
        'last_success': now if healthy else '',
        'last_recovery': recovery.get('outcome', 'none'),
        'run_id': str(run_id),
    }


def vm_host(values: dict, recovery: dict, run_id: str, now: str) -> dict:
    healthy = (
        is_true(values.get('CONTROL_REACHABLE'))
        and is_true(values.get('DEVICE_STATE_EXISTS'))
        and values.get('SERVICE_ENABLED') == 'enabled'
        and values.get('SERVICE_ACTIVE') == 'active'
        and int_value(values.get('CANONICAL_REMOTE_COUNT')) == 1
        and int_value(values.get('NONCANONICAL_REMOTE_COUNT')) == 0
        and not is_true(values.get('AUTH_REQUIRED'))
    )
    if not is_true(values.get('CONTROL_REACHABLE')):
        state = 'unreachable'
    else:
        state = 'healthy' if healthy else 'degraded'
    return {
        'host': 'shopvivaliz-ai',
        'state': state,
        'watchdog': 'active' if healthy else values.get('SERVICE_ACTIVE', 'missing'),
        'canonical_agent_count': int_value(values.get('CANONICAL_REMOTE_COUNT')),
        'auth_required': is_true(values.get('AUTH_REQUIRED')),
        'last_success': now if healthy else '',
        'last_recovery': recovery.get('outcome', 'none'),
        'run_id': str(run_id),
    }


def load_recovery(path: str) -> dict:
    try:
        data = json.loads(Path(path).read_text(encoding='utf-8'))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--fred-status', required=True)
    parser.add_argument('--vm-status', required=True)
    parser.add_argument('--desktop-status', required=True)
    parser.add_argument('--recovery-json', required=True)
    parser.add_argument('--run-id', required=True)
    parser.add_argument('--json-out', required=True)
    parser.add_argument('--markdown-out', required=True)
    args = parser.parse_args()

    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z')
    recovery = load_recovery(args.recovery_json)
    hosts = [
        windows_host('LAPTOP-NIG4IFUU', parse_status(args.fred_status), recovery.get('LAPTOP-NIG4IFUU', {}), args.run_id, now),
        vm_host(parse_status(args.vm_status), recovery.get('shopvivaliz-ai', {}), args.run_id, now),
        windows_host('DESKTOP-KOCEPSV', parse_status(args.desktop_status), recovery.get('DESKTOP-KOCEPSV', {}), args.run_id, now),
    ]
    payload = {'recorded_at': now, 'hosts': hosts}
    Path(args.json_out).write_text(json.dumps(payload, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

    lines = [
        '# Desktop Commander 24h Control Plane Status',
        '',
        f'Updated: `{now}`',
        '',
        '| Host | State | Watchdog | Canonical DC | Auth required | Last success | Last recovery | Run |',
        '|---|---|---|---:|---|---|---|---|',
    ]
    for host in hosts:
        lines.append(
            f"| {host['host']} | {host['state']} | {host['watchdog']} | {host['canonical_agent_count']} | "
            f"{str(host['auth_required']).lower()} | {host['last_success'] or '-'} | {host['last_recovery']} | {host['run_id']} |"
        )
    Path(args.markdown_out).write_text('\n'.join(lines) + '\n', encoding='utf-8')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
