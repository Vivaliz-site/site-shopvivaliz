#!/usr/bin/env python3
"""Independent health watchdog for the Shopee logistics worker.

Checks that the worker timer is enabled/active and that a recent cycle exists.
On failure it performs one bounded repair attempt, re-checks health, and sends a
throttled alert using the worker's validated Brevo/SMTP notification path.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
from collections import deque
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))

from shopee_logistics_worker import AlertSender  # noqa: E402

WORKER_SERVICE = 'shopvivaliz-shopee-logistics-worker.service'
WORKER_TIMER = 'shopvivaliz-shopee-logistics-worker.timer'
DEFAULT_SHARED_ROOT = Path('/home/ubuntu/shopvivaliz-deploy/shared')
DEFAULT_STALE_AFTER = 12 * 60
DEFAULT_ALERT_COOLDOWN = 30 * 60


def parse_epoch(value: str | None) -> int | None:
    if not value:
        return None
    try:
        dt = datetime.fromisoformat(str(value).replace('Z', '+00:00'))
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return int(dt.timestamp())
    except (TypeError, ValueError, OverflowError):
        return None


def read_last_cycle(path: Path) -> dict[str, Any] | None:
    if not path.is_file():
        return None
    try:
        with path.open('r', encoding='utf-8') as handle:
            recent = deque(handle, maxlen=256)
    except OSError:
        return None
    for raw in reversed(recent):
        try:
            row = json.loads(raw)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict) and row.get('kind') == 'cycle':
            return row
    return None


def systemctl_state(unit: str, action: str) -> str:
    cp = subprocess.run(
        ['systemctl', action, unit],
        capture_output=True,
        text=True,
        timeout=15,
        check=False,
    )
    value = (cp.stdout or cp.stderr or '').strip().splitlines()
    return value[-1].strip() if value else ''


def evaluate_health(
    *,
    timer_enabled: bool,
    timer_active: bool,
    last_cycle_epoch: int | None,
    now: int,
    stale_after: int,
) -> dict[str, Any]:
    reasons: list[str] = []
    if not timer_enabled:
        reasons.append('timer_disabled')
    if not timer_active:
        reasons.append('timer_inactive')
    age: int | None = None
    if last_cycle_epoch is None:
        reasons.append('cycle_missing')
    else:
        age = max(0, int(now) - int(last_cycle_epoch))
        if age > max(60, int(stale_after)):
            reasons.append('cycle_stale')
    return {
        'healthy': not reasons,
        'reasons': reasons,
        'cycle_age_seconds': age,
        'timer_enabled': bool(timer_enabled),
        'timer_active': bool(timer_active),
    }


def repair_worker() -> bool:
    commands = [
        ['systemctl', 'reset-failed', WORKER_SERVICE],
        ['systemctl', 'enable', '--now', WORKER_TIMER],
        ['systemctl', 'start', WORKER_SERVICE],
    ]
    for command in commands:
        cp = subprocess.run(command, capture_output=True, text=True, timeout=90, check=False)
        if cp.returncode != 0:
            return False
    return True


def send_alert(subject: str, body: str) -> bool:
    return AlertSender().send(subject, body)


def _load_state(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding='utf-8'))
        return data if isinstance(data, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def _save_state(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f'.{path.name}.{os.getpid()}.tmp')
    temporary.write_text(json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    temporary.chmod(0o660)
    os.replace(temporary, path)


def _append_event(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    row = {'at': datetime.now(timezone.utc).isoformat(), **payload}
    with path.open('a', encoding='utf-8') as handle:
        handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + '\n')


def run_watchdog(
    *,
    shared_root: Path | None = None,
    now: int | None = None,
    stale_after: int = DEFAULT_STALE_AFTER,
    alert_cooldown: int = DEFAULT_ALERT_COOLDOWN,
) -> dict[str, Any]:
    shared = shared_root or Path(os.environ.get('SHOPVIVALIZ_SHARED_ROOT') or DEFAULT_SHARED_ROOT)
    current = int(now or time.time())
    worker_log = shared / 'logs' / 'shopee-logistics-worker.jsonl'
    watchdog_log = shared / 'logs' / 'shopee-logistics-watchdog.jsonl'
    state_path = shared / 'storage' / 'shopee-logistics-watchdog' / 'state.json'

    timer_enabled = systemctl_state(WORKER_TIMER, 'is-enabled') == 'enabled'
    timer_active = systemctl_state(WORKER_TIMER, 'is-active') == 'active'
    cycle = read_last_cycle(worker_log)
    initial = evaluate_health(
        timer_enabled=timer_enabled,
        timer_active=timer_active,
        last_cycle_epoch=parse_epoch((cycle or {}).get('at')),
        now=current,
        stale_after=stale_after,
    )
    result: dict[str, Any] = {
        **initial,
        'repaired': False,
        'healthy_after_repair': initial['healthy'],
        'alert_sent': False,
        'alert_throttled': False,
    }

    if initial['healthy']:
        _append_event(watchdog_log, {'kind': 'watchdog', **result})
        return result

    fingerprint = ','.join(sorted(initial['reasons'])) or 'unknown'
    result['repaired'] = repair_worker()

    timer_enabled_after = systemctl_state(WORKER_TIMER, 'is-enabled') == 'enabled'
    timer_active_after = systemctl_state(WORKER_TIMER, 'is-active') == 'active'
    cycle_after = read_last_cycle(worker_log)
    after = evaluate_health(
        timer_enabled=timer_enabled_after,
        timer_active=timer_active_after,
        last_cycle_epoch=parse_epoch((cycle_after or {}).get('at')),
        now=current,
        stale_after=stale_after,
    )
    result['healthy_after_repair'] = after['healthy']
    result['after_reasons'] = after['reasons']
    result['after_cycle_age_seconds'] = after['cycle_age_seconds']

    state = _load_state(state_path)
    last_fingerprint = str(state.get('last_fingerprint') or '')
    last_alert_at = int(state.get('last_alert_at') or 0)
    should_alert = fingerprint != last_fingerprint or current - last_alert_at >= max(60, int(alert_cooldown))

    if should_alert:
        subject = '[SHOPEE] Watchdog reparou automacao de despacho' if after['healthy'] else '[SHOPEE] Watchdog detectou falha persistente no despacho'
        body = (
            f'Falha detectada: {fingerprint}. '
            f'Tentativa de autorreparo: {"executada" if result["repaired"] else "falhou"}. '
            f'Estado apos reparo: {"saudavel" if after["healthy"] else ",".join(after["reasons"]) or "indefinido"}. '
            'Verifique a integracao Shopee imediatamente se a falha persistir.'
        )
        result['alert_sent'] = bool(send_alert(subject, body))
        if result['alert_sent']:
            state['last_alert_at'] = current
            state['last_fingerprint'] = fingerprint
    else:
        result['alert_throttled'] = True

    state['last_check_at'] = current
    state['last_healthy'] = bool(after['healthy'])
    state['last_reasons'] = after['reasons']
    _save_state(state_path, state)
    _append_event(watchdog_log, {'kind': 'watchdog', **result})
    return result


def main() -> int:
    stale_after = int(os.environ.get('SHOPEE_LOGISTICS_STALE_AFTER_SECONDS') or DEFAULT_STALE_AFTER)
    alert_cooldown = int(os.environ.get('SHOPEE_LOGISTICS_WATCHDOG_ALERT_COOLDOWN_SECONDS') or DEFAULT_ALERT_COOLDOWN)
    result = run_watchdog(stale_after=stale_after, alert_cooldown=alert_cooldown)
    print(json.dumps({'status': 'ok' if result['healthy_after_repair'] else 'degraded', **result}, ensure_ascii=False, sort_keys=True))
    return 0 if result['healthy_after_repair'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
