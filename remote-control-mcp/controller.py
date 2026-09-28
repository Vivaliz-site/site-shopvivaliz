#!/usr/bin/env python3
import argparse, json, os, sqlite3, subprocess, time, uuid, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

DB=Path(os.getenv("SHOPVIVALIZ_REMOTE_DB","/var/lib/shopvivaliz-remote/control.db"))
HOSTS={
 "always-free-arm-1787907847-26":{"kind":"local"},
 "shopvivaliz-free-a1":{"kind":"ssh","target":"shopvivaliz-agent@10.0.1.112"},
 "fred-win":{"kind":"relay","url":"http://127.0.0.1:5557"},
 "kocepsv":{"kind":"relay","url":"http://127.0.0.1:5558"},
}
MAX_OUTPUT=200000

def db():
 DB.parent.mkdir(parents=True,exist_ok=True)
 c=sqlite3.connect(DB); c.execute("pragma journal_mode=wal")
 c.execute("""create table if not exists tasks(id text primary key,host text,command text,state text,created real,started real,finished real,exit_code integer,stdout text,stderr text)""")
 c.execute("""create table if not exists audit(id text primary key,ts real,host text,action text,status text,detail text)"""); c.commit(); return c

def audit(host,action,status,detail=""):
 c=db(); aid=str(uuid.uuid4()); c.execute("insert into audit values(?,?,?,?,?,?)",(aid,time.time(),host,action,status,detail[:2000])); c.commit(); c.close(); return aid

def run(host,command,timeout=300):
 if host not in HOSTS: raise ValueError("unknown host")
 h=HOSTS[host]
 if h["kind"]=="local":
  p=subprocess.run(command,shell=True,text=True,capture_output=True,timeout=timeout)
  return p.returncode,p.stdout[-MAX_OUTPUT:],p.stderr[-MAX_OUTPUT:]
 if h["kind"]=="ssh":
  p=subprocess.run(["ssh","-o","BatchMode=yes","-o","ConnectTimeout=10",h["target"],command],text=True,capture_output=True,timeout=timeout)
  return p.returncode,p.stdout[-MAX_OUTPUT:],p.stderr[-MAX_OUTPUT:]
 body=json.dumps({"params":{"command":command,"timeout":timeout}}).encode()
 req=urllib.request.Request(h["url"]+"/mcp/tool/execute_command",data=body,headers={"Content-Type":"application/json"},method="POST")
 with urllib.request.urlopen(req,timeout=timeout+10) as r: data=json.loads(r.read())
 result=data.get("result") or {}
 return (0 if result.get("success") else 1),str(result.get("output") or "")[-MAX_OUTPUT:],str(result.get("error") or "")[-MAX_OUTPUT:]

def health(host):
 cmd="powershell -NoProfile -NonInteractive -Command \"hostname; whoami\"" if host in ("fred-win","kocepsv") else "hostname; whoami; uptime"
 rc,out,err=run(host,cmd,30); return {"host":host,"ok":rc==0,"output":out,"error":err}

def worker_once():
 c=db(); row=c.execute("select id,host,command from tasks where state='queued' order by created limit 1").fetchone()
 if not row: c.close(); return False
 tid,host,command=row; c.execute("update tasks set state='running',started=? where id=?",(time.time(),tid)); c.commit(); c.close()
 try: rc,out,err=run(host,command,1800); state="succeeded" if rc==0 else "failed"
 except Exception as e: rc,out,err,state=255,"",str(e),"failed"
 c=db(); c.execute("update tasks set state=?,finished=?,exit_code=?,stdout=?,stderr=? where id=?",(state,time.time(),rc,out,err,tid)); c.commit(); c.close(); audit(host,"task:"+tid,state); return True

class H(BaseHTTPRequestHandler):
 def sendj(self,code,obj):
  b=json.dumps(obj).encode(); self.send_response(code); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(b))); self.end_headers(); self.wfile.write(b)
 def do_GET(self):
  if self.path=="/health":
   self.sendj(200,{"ok":True,"service":"shopvivaliz-remote-control","version":"1.0.0","hosts":list(HOSTS)}); return
  if self.path=="/hosts":
   self.sendj(200,{"hosts":[health(x) for x in HOSTS]}); return
  if self.path.startswith("/task/"):
   tid=self.path.split("/")[-1]; c=db(); r=c.execute("select * from tasks where id=?",(tid,)).fetchone(); cols=[x[0] for x in c.execute("select * from tasks limit 0").description]; c.close()
   self.sendj(200 if r else 404,dict(zip(cols,r)) if r else {"error":"not found"}); return
  self.sendj(404,{"error":"not found"})
 def do_POST(self):
  n=int(self.headers.get("Content-Length","0")); d=json.loads(self.rfile.read(n) or b"{}")
  if self.path=="/exec":
   host=d.get("host"); cmd=d.get("command",""); timeout=min(int(d.get("timeout",300)),1800)
   if not cmd or host not in HOSTS: self.sendj(400,{"error":"invalid request"}); return
   try: rc,out,err=run(host,cmd,timeout); aid=audit(host,"exec","ok" if rc==0 else "failed"); self.sendj(200,{"ok":rc==0,"exit_code":rc,"stdout":out,"stderr":err,"audit_id":aid})
   except Exception as e: aid=audit(host,"exec","failed",str(e)); self.sendj(500,{"ok":False,"error":str(e),"audit_id":aid})
   return
  if self.path=="/task":
   host=d.get("host"); cmd=d.get("command","")
   if not cmd or host not in HOSTS: self.sendj(400,{"error":"invalid request"}); return
   tid=str(uuid.uuid4()); c=db(); c.execute("insert into tasks(id,host,command,state,created) values(?,?,?,?,?)",(tid,host,cmd,"queued",time.time())); c.commit(); c.close(); audit(host,"task_submit","queued",tid); self.sendj(202,{"task_id":tid,"state":"queued"}); return
  self.sendj(404,{"error":"not found"})
 def log_message(self,*a): pass

def main():
 p=argparse.ArgumentParser(); p.add_argument("--worker",action="store_true"); a=p.parse_args()
 if a.worker:
  while True:
   if not worker_once(): time.sleep(1)
 else: ThreadingHTTPServer(("127.0.0.1",5560),H).serve_forever()
if __name__=="__main__": main()
