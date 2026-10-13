#!/usr/bin/env python3
"""Read-only status observer for three separate corporate Chromium sessions."""
import datetime
import importlib.util
import json
import os
from pathlib import Path
import pwd
import subprocess
import sys
import tempfile
import urllib.request

DEST=Path("/var/lib/shopvivaliz-login-integrity/state.json")
EXPECTED={
 "dev_9559":("dev","shopvivaliz-dev-chromium","shopvivaliz-dev-browser.service"),
 "atendimento_9556":("atendimento","shopvivaliz-atendimento-chromium","shopvivaliz-atendimento-browser.service"),
 "dev_aux_9557":(None,"shopvivaliz-atendimento-mcp-chromium","shopvivaliz-atendimento-mcp-browser.service"),
}
def aux_health():
 # The dedicated Dev endpoint can take several seconds under backend load.
 # Retry one transient I/O or malformed-response failure without accepting
 # a missing, mismatched or unauthenticated identity as success.
 for attempt in range(2):
  try:
   with urllib.request.urlopen("http://127.0.0.1:5583/health",timeout=12) as response:
    return json.load(response)
  except (OSError,TimeoutError,ValueError):
   if attempt==1:
    raise

def probe(mod,session):
 try:
  if session:
   d=mod.browser_auth_status({"session":session})
   state=d.get("state","UNKNOWN")
   verified=state=="AUTHENTICATED" and d.get("identity_match") is True and d.get("cdp_accessible") is True
  else:
   d=aux_health()
   verified=all(d.get(k) is True for k in ("ok","cdp_reachable","account_authenticated","account_identity_verified")) and d.get("session")=="dev"
   state="AUTHENTICATED" if verified else "UNKNOWN"
  return {"state":state if state in ("AUTHENTICATED","NOT_AUTHENTICATED","IDENTITY_MISMATCH","UNKNOWN") else "UNKNOWN","verified":verified}
 except Exception:return {"state":"PROBE_ERROR","verified":False}
def profile_ok(name,uid):
 try:
  p=Path("/home/fredrdp/.config")/name
  s=p.lstat()
  local=p/"Local State"
  return p.is_dir() and not p.is_symlink() and s.st_uid==uid and not (s.st_mode&0o077) and local.is_file() and local.stat().st_size>0 and ((p/"Default").is_dir() or (p/"Profile 1").is_dir())
 except OSError:return False
def service_ok(name):
 try:return subprocess.run(["/usr/bin/systemctl","is-active","--quiet",name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=4).returncode==0
 except (OSError,subprocess.TimeoutExpired):return False
def write_state(d):
 DEST.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
 fd,tmp=tempfile.mkstemp(prefix=".state-",dir=str(DEST.parent))
 try:
  os.fchmod(fd,0o600)
  with os.fdopen(fd,"w",encoding="utf8") as f:
   json.dump(d,f,sort_keys=True,separators=(",",":"))
   f.write("\n")
   f.flush()
   os.fsync(f.fileno())
  os.replace(tmp,DEST)
 finally:
  try:os.unlink(tmp)
  except FileNotFoundError:pass
def main():
 if len(sys.argv)!=1:return 64
 spec=importlib.util.spec_from_file_location("svv_auth_integrity","/opt/shopvivaliz-remote-control-browser/server.py")
 if not spec or not spec.loader:return 3
 mod=importlib.util.module_from_spec(spec)
 spec.loader.exec_module(mod)
 uid=pwd.getpwnam("fredrdp").pw_uid
 data={}
 for name,(session,prof,unit) in EXPECTED.items():
  row=probe(mod,session)
  row["profile_intact"]=profile_ok(prof,uid)
  row["service_active"]=service_ok(unit)
  data[name]=row
 healthy=all(x["verified"] and x["profile_intact"] and x["service_active"] for x in data.values())
 try:
  prev=json.loads(DEST.read_text(encoding="utf8"))
  n=max(0,min(999,int(prev.get("consecutive_failures",0))))
 except (OSError,ValueError,TypeError):n=0
 streak=0 if healthy else n+1
 status="HEALTHY" if healthy else ("WATCH" if streak==1 else "DEGRADED")
 write_state({"schema_version":1,"readonly":True,"checked_at":datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),"status":status,"consecutive_failures":streak,"sessions":data})
 print("LOGIN_INTEGRITY="+status+" consecutive_failures="+str(streak)+" dev_9559="+data["dev_9559"]["state"]+" atendimento_9556="+data["atendimento_9556"]["state"]+" dev_aux_9557="+data["dev_aux_9557"]["state"],flush=True)
 return 2 if status=="DEGRADED" else 0
if __name__=="__main__":raise SystemExit(main())

