#!/usr/bin/env python3
from __future__ import annotations
import fcntl,json,os,tempfile,time,uuid
from pathlib import Path
from typing import Any
class RuntimeLockConflict(RuntimeError): pass

def _root()->Path:
    base=os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR","/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state").strip()
    if not base: raise RuntimeLockConflict("runtime lock state directory is required")
    p=Path(base)/"_runtime-lock"; p.mkdir(parents=True,exist_ok=True); return p

def _paths():
    r=_root(); return r/"lock.json",r/"lock.lock"
def _load(path):
    if not path.exists(): return None
    try:d=json.loads(path.read_text())
    except (OSError,json.JSONDecodeError) as e: raise RuntimeLockConflict(f"invalid runtime lock state: {e}") from e
    return d if isinstance(d,dict) else None
def _write(path,payload):
    fd,name=tempfile.mkstemp(prefix="runtime-lock.",dir=str(path.parent))
    try:
        with os.fdopen(fd,"w") as h: json.dump(payload,h,sort_keys=True,separators=(",",":")); h.write("\n"); h.flush(); os.fsync(h.fileno())
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)
class _Guard:
    def __enter__(self):
        self.path,lp=_paths(); self.h=lp.open("a+"); fcntl.flock(self.h.fileno(),fcntl.LOCK_EX); return self.path
    def __exit__(self,*_): fcntl.flock(self.h.fileno(),fcntl.LOCK_UN); self.h.close()
def _live(p,now=None): return bool(p and p.get("released_at") is None and float(p.get("expires_at_epoch",0))>(time.time() if now is None else now))
def acquire_runtime_lock(owner_kind:str,owner_id:str,ttl_seconds:int,allowed_actions:list[str])->dict[str,Any]:
    if owner_kind not in {"foreground","durable-recovery","maintenance"}: raise RuntimeLockConflict("invalid owner_kind")
    if not owner_id: raise RuntimeLockConflict("owner_id is required")
    if int(ttl_seconds)<=0: raise RuntimeLockConflict("ttl_seconds must be positive")
    now=time.time()
    with _Guard() as path:
        prev=_load(path)
        if _live(prev,now): raise RuntimeLockConflict("runtime lock already held")
        p={"schema_version":1,"lease_id":str(uuid.uuid4()),"owner_kind":owner_kind,"owner_id":str(owner_id),"fencing_token":int((prev or {}).get("fencing_token",0))+1,"issued_at_epoch":now,"expires_at_epoch":now+int(ttl_seconds),"allowed_actions":sorted({str(x) for x in allowed_actions}),"released_at":None,"release_reason":""}; _write(path,p); return dict(p)
def get_runtime_lock():
    path,_=_paths(); p=_load(path); return dict(p) if p else None
def _current(p,lease_id,token):
    if not p or p.get("lease_id")!=lease_id or int(p.get("fencing_token",-1))!=int(token): raise RuntimeLockConflict("stale runtime lock owner")
    if not _live(p): raise RuntimeLockConflict("runtime lock is not live")
    return p
def assert_runtime_lock(lease_id:str,fencing_token:int,required_action:str)->dict[str,Any]:
    p=_current(get_runtime_lock(),lease_id,fencing_token)
    if required_action not in p.get("allowed_actions",[]): raise RuntimeLockConflict("runtime lock does not allow requested action")
    return dict(p)
def release_runtime_lock(lease_id:str,fencing_token:int,reason:str)->dict[str,Any]:
    with _Guard() as path:
        p=_current(_load(path),lease_id,fencing_token); p["released_at"]=time.time(); p["release_reason"]=str(reason); _write(path,p); return dict(p)
