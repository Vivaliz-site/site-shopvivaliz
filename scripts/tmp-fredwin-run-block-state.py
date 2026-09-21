import json
import urllib.request

BASE = "http://127.0.0.1:5557/mcp/tool/"

def call(tool, params, timeout=30):
    body = json.dumps({"params": params}).encode()
    req = urllib.request.Request(BASE + tool, data=body, headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=timeout) as response:
        return json.loads(response.read().decode())

with open("/tmp/fredwin-defender-block-state.ps1", "r", encoding="utf-8") as fh:
    ps = fh.read()

print(json.dumps(call("write_file", {"path": r"C:\site-shopvivaliz\scripts\fredwin-defender-block-state.ps1", "content": ps}), ensure_ascii=False))
print(json.dumps(call("execute_command", {"command": r"powershell -NoProfile -ExecutionPolicy Bypass -File scripts\fredwin-defender-block-state.ps1", "timeout": 45}, 55), ensure_ascii=False))
