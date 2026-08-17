import json
import urllib.request

BASE = "http://127.0.0.1:5557/mcp/tool/"


def call(tool, params, timeout=30):
    body = json.dumps({"params": params}).encode()
    req = urllib.request.Request(
        BASE + tool,
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as response:
        return json.loads(response.read().decode())


with open("/tmp/fredwin-defender-unblock-exact.ps1", "r", encoding="utf-8") as fh:
    ps = fh.read()

written = call(
    "write_file",
    {
        "path": r"C:\site-shopvivaliz\scripts\fredwin-defender-unblock-exact.ps1",
        "content": ps,
    },
)
print(json.dumps(written, ensure_ascii=False))

executed = call(
    "execute_command",
    {
        "command": r"powershell -NoProfile -ExecutionPolicy Bypass -File scripts\fredwin-defender-unblock-exact.ps1",
        "timeout": 90,
    },
    100,
)
print(json.dumps(executed, ensure_ascii=False))
if not (executed.get("result") or {}).get("success"):
    raise SystemExit(3)
