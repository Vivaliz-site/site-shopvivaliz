#!/usr/bin/env python3
import json, urllib.request, time
BASE="http://127.0.0.1:5560"
def get(p):
 with urllib.request.urlopen(BASE+p,timeout=60) as r:return json.loads(r.read())
def post(p,d):
 req=urllib.request.Request(BASE+p,data=json.dumps(d).encode(),headers={"Content-Type":"application/json"},method="POST")
 with urllib.request.urlopen(req,timeout=70) as r:return json.loads(r.read())
h=get("/hosts"); assert len(h["hosts"])==4, h
for x in h["hosts"]: assert x["ok"], x
for host in ["always-free-arm-1787907847-26","shopvivaliz-free-a1","fred-win","kocepsv"]:
 cmd="powershell -NoProfile -NonInteractive -Command \"[Security.Principal.WindowsIdentity]::GetCurrent().Name\"" if host in ("fred-win","kocepsv") else "id -u"
 r=post("/exec",{"host":host,"command":cmd}); assert r["ok"],(host,r)
t=post("/task",{"host":"always-free-arm-1787907847-26","command":"sleep 3; echo durable-ok"})
tid=t["task_id"]
for _ in range(20):
 s=get("/task/"+tid)
 if s["state"] in ("succeeded","failed"):break
 time.sleep(1)
assert s["state"]=="succeeded" and "durable-ok" in s["stdout"],s
print(json.dumps({"four_hosts":"PASS","durable_task":"PASS","task_id":tid}))
