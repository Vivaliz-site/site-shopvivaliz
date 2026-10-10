#!/usr/bin/env python3
from __future__ import annotations
import fcntl, hashlib, json, os, tempfile, time, uuid
from pathlib import Path
from typing import Any
class LeaseConflict(RuntimeError): pass

def _state_root()->Path:
    root=os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR","").strip()
    if not root: raise LeaseConflict("SHOPVIVALIZ_AGENT_TASK_STATE_DIR is required")
    p=Path(root)/"_conversation-leases"; p.mkdir(parents=True,exist_ok=True); return p

def _key(conversation_id:str)->str:
    if not str(conversation_id or "").strip(): raise LeaseConflict("conversation_id is required")
    return hashlib.sha256(str(conversation_id).encode()).hexdigest()

def _paths(conversation_id:str):
    root=_state_root(); key=_key(conversation_id); return root/f"{key}.json",root/f"{key}.lock"

def _load(path:Path):
    if not path.exists(): return None
    try: data=json.loads(path.read_text())
    except (OSError,json.JSONDecodeError) as e: raise LeaseConflict(f"invalid lease state: {e}") from e
    return data if isinstance(data,dict) else None

def _write_atomic(path:Path,payload:dict[str,Any])->None:
    fd,name=tempfile.mkstemp(prefix=path.name+".",dir=str(path.parent))
    try:
        with os.fdopen(fd,"w") as h: json.dump(payload,h,sort_keys=True,separators=(",",":")); h.write("\n"); h.flush(); os.fsync(h.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)

def _locked(conversation_id:str):
    class Lock:
        def __enter__(self):
            self.state_path,lock_path=_paths(conversation_id); self.handle=lock_path.open("a+"); fcntl.flock(self.handle.fileno(),fcntl.LOCK_EX); return self.state_path
        def __exit__(self,*_): fcntl.flock(self.handle.fileno(),fcntl.LOCK_UN); self.handle.close()
    return Lock()

def _now(): return time.time()
def _is_live(payload,now):
    if not payload or payload.get("released_at") is not None: return False
    try: return float(payload.get("expires_at_epoch",0))>now
    except (TypeError,ValueError): return False

def acquire_conversation_lease(conversation_id,owner_kind,owner_id,checkpoint_version,ttl_seconds,allowed_actions):
    if owner_kind not in {"foreground","durable-recovery","maintenance"}: raise LeaseConflict("invalid owner_kind")
    if not owner_id: raise LeaseConflict("owner_id is required")
    if int(ttl_seconds)<=0: raise LeaseConflict("ttl_seconds must be positive")
    now=_now()
    with _locked(conversation_id) as path:
        prev=_load(path)
        if _is_live(prev,now): raise LeaseConflict("conversation lease already held")
        payload={"schema_version":1,"conversation_id":str(conversation_id),"lease_id":str(uuid.uuid4()),"owner_kind":owner_kind,"owner_id":str(owner_id),"fencing_token":int((prev or {}).get("fencing_token",0))+1,"issued_at_epoch":now,"expires_at_epoch":now+int(ttl_seconds),"checkpoint_version":int(checkpoint_version),"allowed_actions":sorted({str(v) for v in allowed_actions}),"released_at":None,"release_reason":""}
        _write_atomic(path,payload); return dict(payload)

def get_conversation_lease(conversation_id):
    path,_=_paths(conversation_id); p=_load(path); return dict(p) if p else None

def _require_current(payload,lease_id,fencing_token):
    if not payload: raise LeaseConflict("conversation lease not found")
    if payload.get("lease_id")!=lease_id or int(payload.get("fencing_token",-1))!=int(fencing_token): raise LeaseConflict("stale conversation lease owner")
    return payload

def renew_conversation_lease(conversation_id,lease_id,fencing_token,ttl_seconds):
    if int(ttl_seconds)<=0: raise LeaseConflict("ttl_seconds must be positive")
    now=_now()
    with _locked(conversation_id) as path:
        p=_require_current(_load(path),lease_id,fencing_token)
        if p.get("released_at") is not None or not _is_live(p,now): raise LeaseConflict("conversation lease is not live")
        p["expires_at_epoch"]=now+int(ttl_seconds); _write_atomic(path,p); return dict(p)

def release_conversation_lease(conversation_id,lease_id,fencing_token,reason):
    with _locked(conversation_id) as path:
        p=_require_current(_load(path),lease_id,fencing_token)
        if p.get("released_at") is not None: raise LeaseConflict("conversation lease already released")
        p["released_at"]=_now(); p["release_reason"]=str(reason); _write_atomic(path,p); return dict(p)

def assert_conversation_lease(conversation_id,lease_id,fencing_token,required_action):
    p=_require_current(get_conversation_lease(conversation_id),lease_id,fencing_token)
    if not _is_live(p,_now()): raise LeaseConflict("conversation lease is not live")
    if required_action not in p.get("allowed_actions",[]): raise LeaseConflict("conversation lease does not allow requested action")
    return dict(p)
