#!/usr/bin/env bash
set -Eeuo pipefail
if [[ $# -ne 3 ]]; then
  echo "usage: $0 <python-smoke> <result-marker> <result-json>" >&2
  exit 64
fi
script_path="$1"; marker="$2"; result_path="$3"
backend_host="${SV_BROWSER_BACKEND_HOST:-10.0.1.38}"
ssh_key="${SV_BROWSER_VM_KEY:-$HOME/.ssh/id_rsa}"
known_hosts="${SV_BROWSER_KNOWN_HOSTS:-$HOME/.ssh/known_hosts}"
[[ -f "$script_path" ]] || exit 66
[[ "$marker" =~ ^[A-Z0-9_]+=$ ]] || exit 64
[[ -f "$ssh_key" && -f "$known_hosts" ]] || exit 77
ssh_opts=(-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -i "$ssh_key")
run_id="${GITHUB_RUN_ID:-local}-$(date +%s)-$$"
remote="/tmp/shopvivaliz-browser-smoke-${run_id}.py"
remote_log="$(mktemp)"
trap 'rm -f "$remote_log"' EXIT
identity="$(ssh "${ssh_opts[@]}" "ubuntu@$backend_host" 'printf "%s/%s" "$(hostname)" "$(id -un)"')"
[[ "$identity" == "always-free-arm-1787907847-26/ubuntu" ]] || { echo "backend browser host identity mismatch: $identity" >&2; exit 78; }
scp -q "${ssh_opts[@]}" "$script_path" "ubuntu@$backend_host:$remote"
set +e
ssh "${ssh_opts[@]}" "ubuntu@$backend_host" "SV_ADMIN_SESSION_ID='${SV_ADMIN_SESSION_ID:-}' SV_ADMIN_SESSION_NAME='${SV_ADMIN_SESSION_NAME:-PHPSESSID}' REMOTE_SMOKE='$remote' bash -s" <<'REMOTE' >"$remote_log" 2>&1
set -Eeuo pipefail
test "$(hostname)" = "always-free-arm-1787907847-26"
root=/home/ubuntu/shopvivaliz-browser-worker
python="$root/python-venv/bin/python"
test -x "$python"
"$python" -c 'import playwright'
chrome="$(find /home/ubuntu/.cache/ms-playwright -maxdepth 3 -type f -path '*/chromium-*/chrome-linux/chrome' -perm -u+x 2>/dev/null | sort -V | tail -1)"
test -n "$chrome"
export SV_BROWSER_EXECUTABLE="$chrome"
export SV_ADMIN_SESSION_ID="${SV_ADMIN_SESSION_ID:-}"
export SV_ADMIN_SESSION_NAME="${SV_ADMIN_SESSION_NAME:-PHPSESSID}"
"$python" "$REMOTE_SMOKE"
REMOTE
status=$?
set -e
ssh "${ssh_opts[@]}" "ubuntu@$backend_host" "rm -f '$remote'" >/dev/null 2>&1
mkdir -p "$(dirname "$result_path")"
python3 - "$remote_log" "$result_path" "$marker" <<'PYRESULT'
import base64,json,pathlib,sys
text=pathlib.Path(sys.argv[1]).read_text(encoding='utf-8',errors='replace')
encoded=''
for line in text.splitlines():
    if line.startswith(sys.argv[3]):
        encoded=line[len(sys.argv[3]):].strip()
if encoded:
    data=json.loads(base64.b64decode(encoded).decode('utf-8'))
else:
    data={'overall':False,'failures':['backend_vm_structured_result_missing'],'output_tail':text[-2400:]}
pathlib.Path(sys.argv[2]).write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(data,ensure_ascii=False,indent=2))
PYRESULT
[[ "$status" -eq 0 ]] || exit "$status"
python3 - "$result_path" <<'PYCHECK'
import json,sys
with open(sys.argv[1],encoding='utf-8') as fh: data=json.load(fh)
raise SystemExit(0 if data.get('overall') is True else 7)
PYCHECK
