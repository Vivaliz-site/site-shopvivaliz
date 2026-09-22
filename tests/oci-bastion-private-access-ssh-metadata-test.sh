#!/usr/bin/env bash
set -Eeuo pipefail

WF=".github/workflows/oci-bastion-private-access-bootstrap.yml"
test -f "$WF"

python3 - "$WF" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")

required = [
    'data."ssh-metadata".command',
    'BACKEND_BASTION_SSH_COMMAND',
    'SITE_BASTION_SSH_COMMAND',
    '<privateKey>',
    '<localPort>',
    'ssh-keygen -q -t rsa -b 4096',
    'HostKeyAlgorithms=+ssh-rsa',
    'PubkeyAcceptedAlgorithms=+ssh-rsa',
]
missing = [needle for needle in required if needle not in text]
if missing:
    raise SystemExit("OCI_BASTION_SSH_METADATA_TEST=FAIL missing=" + ",".join(missing))

for forbidden in [
    'bastion_host="host.bastion.$OCI_REGION.oci.oraclecloud.com"',
    '"$sid@$bastion_host"',
]:
    if forbidden in text:
        raise SystemExit("OCI_BASTION_SSH_METADATA_TEST=FAIL manual_endpoint=" + forbidden)

print("OCI_BASTION_SSH_METADATA_TEST=PASS")
PY
