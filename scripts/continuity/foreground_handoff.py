#!/usr/bin/env python3
from __future__ import annotations
import importlib.util
import time
from pathlib import Path
from typing import Any, Callable
import sys
HERE = Path(__file__).resolve().parent
if str(HERE) not in sys.path:
    sys.path.insert(0, str(HERE))
import conversation_lease

ROOT = Path(__file__).resolve().parents[1]
_AGENT_PATH = ROOT / 'agent_task_state.py'
_spec = importlib.util.spec_from_file_location('shopvivaliz_agent_task_state_handoff', _AGENT_PATH)
agent_task_state = importlib.util.module_from_spec(_spec)
assert _spec and _spec.loader
_spec.loader.exec_module(agent_task_state)
Submitter = Callable[[list[str]], dict[str, Any]]

def _missing_submitter(_command: list[str]) -> dict[str, Any]:
    raise RuntimeError('foreground durable submitter is not configured')

def handoff_foreground(task_id: str, conversation_id: str, checkpoint_version: int,
                       durable_command: list[str], lease_ttl_seconds: int = 90, *,
                       _submitter: Submitter | None = None) -> dict[str, Any]:
    started = time.monotonic()
    if not durable_command or not all(str(x).strip() for x in durable_command):
        raise ValueError('durable_command must contain non-empty argv')
    state = agent_task_state.load_task(task_id)
    bound = str(state.get('conversation_id', '')).strip()
    effective_version = int(checkpoint_version)
    if not bound:
        if int(state.get('checkpoint_version') or max(1, len(state.get('history', [])))) != effective_version:
            raise agent_task_state.TaskStateError('foreground handoff checkpoint version is stale')
        state = agent_task_state.bind_conversation(task_id, conversation_id=conversation_id)
        effective_version = int(state.get('checkpoint_version') or max(1, len(state.get('history', []))))
    elif bound != conversation_id:
        raise agent_task_state.TaskStateError('foreground handoff conversation does not match task binding')
    elif int(state.get('checkpoint_version') or max(1, len(state.get('history', [])))) != effective_version:
        raise agent_task_state.TaskStateError('foreground handoff checkpoint version is stale')
    lease = conversation_lease.acquire_conversation_lease(
        conversation_id, 'foreground', f'foreground:{task_id}', effective_version,
        int(lease_ttl_seconds), ['read', 'handoff'])
    submitter = _submitter or _missing_submitter
    try:
        submitted = submitter([str(x) for x in durable_command])
        durable_id = str(submitted.get('task_id') or submitted.get('durable_execution_id') or '').strip()
        if not durable_id:
            raise RuntimeError('durable submission returned no task id')
        queue_position = int(submitted.get('queue_position') or 1)
        duration_ms = max(0, int((time.monotonic() - started) * 1000))
        agent_task_state.record_foreground_handoff(
            task_id, conversation_id=conversation_id, expected_checkpoint_version=effective_version,
            durable_execution_id=durable_id, lease_id=lease['lease_id'],
            fencing_token=lease['fencing_token'], queue_position=queue_position,
            foreground_duration_ms=duration_ms)
        return {'task_id': task_id, 'conversation_id': conversation_id,
                'durable_execution_id': durable_id, 'lease_id': lease['lease_id'],
                'fencing_token': lease['fencing_token'], 'checkpoint_version': effective_version,
                'queue_position': queue_position, 'foreground_duration_ms': duration_ms}
    except Exception:
        try:
            conversation_lease.release_conversation_lease(
                conversation_id, lease['lease_id'], lease['fencing_token'], 'durable_submission_failed')
        except Exception:
            pass
        raise

def renew_foreground(task_id: str, *, lease_id: str, fencing_token: int, ttl_seconds: int = 90) -> dict[str, Any]:
    return agent_task_state.renew_foreground_lease_for_task(
        task_id, lease_id=lease_id, fencing_token=int(fencing_token), ttl_seconds=int(ttl_seconds))


def release_foreground(task_id: str, *, lease_id: str, fencing_token: int, reason: str) -> dict[str, Any]:
    return agent_task_state.release_foreground_lease_for_task(
        task_id, lease_id=lease_id, fencing_token=int(fencing_token), reason=str(reason))
