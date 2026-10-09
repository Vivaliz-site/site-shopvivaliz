#!/usr/bin/env python3
from __future__ import annotations
import argparse, json, os, sys, time
from pathlib import Path
from typing import Any

HERE=Path(__file__).resolve().parent
if str(HERE) not in sys.path: sys.path.insert(0,str(HERE))
import conversation_lease, runtime_lock


def _live(payload: dict[str, Any] | None, now: float) -> bool:
    if not payload or payload.get('released_at') is not None: return False
    try: return float(payload.get('expires_at_epoch',0)) > now
    except (TypeError,ValueError): return False

def _deny(reason: str) -> dict[str, Any]: return {'authorized':False,'reason':reason}

def authorize_mutation(conversation_id: str, checkpoint_version: int,
                       conversation_lease_state: dict[str, Any] | None,
                       runtime_lock_state: dict[str, Any] | None,
                       action: str, session_identity: str) -> dict[str, Any]:
    now=time.time(); cid=str(conversation_id or '').strip(); act=str(action or '').strip(); session=str(session_identity or '').strip()
    if not cid or not act: return _deny('invalid_request')
    if not conversation_lease_state: return _deny('conversation_lease_missing')
    if str(conversation_lease_state.get('conversation_id','')).strip()!=cid: return _deny('conversation_mismatch')
    if not _live(conversation_lease_state,now): return _deny('conversation_lease_inactive')
    if str(conversation_lease_state.get('owner_kind',''))=='foreground': return _deny('foreground_active')
    if str(conversation_lease_state.get('owner_kind','')) not in {'durable-recovery','maintenance'}: return _deny('conversation_owner_invalid')
    if int(conversation_lease_state.get('checkpoint_version',-1))!=int(checkpoint_version): return _deny('stale_checkpoint')
    expected_session=str(conversation_lease_state.get('session_identity','')).strip()
    if not expected_session or expected_session!=session: return _deny('session_mismatch')
    if act not in set(conversation_lease_state.get('allowed_actions',[])): return _deny('conversation_action_not_allowed')
    if float(conversation_lease_state.get('cooldown_until_epoch',0) or 0)>now: return _deny('cooldown_active')
    if act in set(conversation_lease_state.get('completed_actions',[])): return _deny('duplicate_action')
    if not runtime_lock_state or not _live(runtime_lock_state,now): return _deny('runtime_lock_inactive')
    if act not in set(runtime_lock_state.get('allowed_actions',[])): return _deny('runtime_action_not_allowed')
    return {'authorized':True,'reason':'authorized','conversation_id':cid,'checkpoint_version':int(checkpoint_version),'action':act,'session_identity':session}

def _task_state(task_id: str) -> dict[str, Any]:
    root=Path(os.environ.get('SHOPVIVALIZ_AGENT_TASK_STATE_DIR','/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state'))
    p=root/f'{task_id}.json'
    return json.loads(p.read_text())

def authorize_current(task_id: str, conversation_id: str, checkpoint_version: int, action: str, session_identity: str,
                      conversation_lease_id: str = '', conversation_fencing_token: int | None = None,
                      runtime_lease_id: str = '', runtime_fencing_token: int | None = None) -> dict[str, Any]:
    task=_task_state(task_id)
    if str(task.get('conversation_id','')).strip()!=str(conversation_id).strip(): return _deny('conversation_mismatch')
    if str(task.get('browser_session','')).strip()!=str(session_identity).strip(): return _deny('session_mismatch')
    if int(task.get('checkpoint_version') or max(1, len(task.get('history',[]))))!=int(checkpoint_version): return _deny('stale_checkpoint')
    if not conversation_lease_id or conversation_fencing_token is None: return _deny('conversation_lease_identity_required')
    if not runtime_lease_id or runtime_fencing_token is None: return _deny('runtime_lock_identity_required')
    try:
        lease=conversation_lease.assert_conversation_lease(conversation_id, conversation_lease_id, int(conversation_fencing_token), action)
    except Exception:
        return _deny('conversation_lease_invalid')
    try:
        lock=runtime_lock.assert_runtime_lock(runtime_lease_id, int(runtime_fencing_token), action)
    except Exception:
        return _deny('runtime_lock_invalid')
    lease={**lease,'session_identity':str(task.get('browser_session','')).strip()}
    return authorize_mutation(conversation_id,checkpoint_version,lease,lock,action,session_identity)

def main()->int:
    p=argparse.ArgumentParser(); sub=p.add_subparsers(dest='cmd',required=True); a=sub.add_parser('authorize-current')
    a.add_argument('--task-id',required=True); a.add_argument('--conversation-id',required=True); a.add_argument('--checkpoint-version',required=True,type=int); a.add_argument('--action',required=True); a.add_argument('--session-identity',required=True)
    a.add_argument('--conversation-lease-id',required=True); a.add_argument('--conversation-fencing-token',required=True,type=int)
    a.add_argument('--runtime-lease-id',required=True); a.add_argument('--runtime-fencing-token',required=True,type=int)
    ns=p.parse_args(); out=authorize_current(ns.task_id,ns.conversation_id,ns.checkpoint_version,ns.action,ns.session_identity,ns.conversation_lease_id,ns.conversation_fencing_token,ns.runtime_lease_id,ns.runtime_fencing_token); print(json.dumps(out,separators=(',',':'))); return 0 if out.get('authorized') else 3
if __name__=='__main__': raise SystemExit(main())
