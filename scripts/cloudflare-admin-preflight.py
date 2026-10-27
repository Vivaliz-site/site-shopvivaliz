#!/usr/bin/env python3
"""Read-only diagnostic for one dedicated Cloudflare admin tunnel per OCI VM.

Never reads secret bytes, changes services, runs sudo, or performs login.
This is NOT an attestation of Cloudflare authentication or sudo delegation.
"""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import sys

HOSTS = {
    'always-free-arm-1787907847-26',
    'shopvivaliz-free-a1',
    'marina-a1-4c24g',
}


def no_new_privileges(status_text):
    match = re.search(r'^NoNewPrivs:\s*([01])\s*$', status_text, re.M)
    return bool(int(match.group(1))) if match else None


def ssh_listener(ss_output):
    for line in ss_output.splitlines():
        columns = line.split()
        if len(columns) >= 4 and columns[0] == 'LISTEN':
            address = columns[3]
            if address.rsplit(':', 1)[-1] == '22':
                return True
    return False


def run_readonly(*argv):
    try:
        p = subprocess.run(argv, capture_output=True, text=True, timeout=4, check=False)
        return p.stdout if p.returncode == 0 else ''
    except (OSError, subprocess.TimeoutExpired):
        return ''


def token_metadata(path):
    try:
        if path.is_symlink():
            return {'present': True, 'regular_file': False, 'mode_0600': False, 'root_owned': False}
        st = path.stat()
    except OSError:
        return {'present': False, 'regular_file': False, 'mode_0600': False, 'root_owned': False}
    return {
        'present': True,
        'regular_file': stat.S_ISREG(st.st_mode),
        'mode_0600': stat.S_IMODE(st.st_mode) == 0o600,
        'root_owned': st.st_uid == 0,
    }


def evaluate(target, current_host, euid, nnp, ssh_active, ssh_listen,
             binary, unit_state, token):
    host_match = target == current_host
    privileged_installer = euid == 0 and nnp is False
    token_safe = all(token.values())
    return {
        'schema_version': 1,
        'target': target,
        'hostname': current_host,
        'host_match': host_match,
        'effective_uid': euid,
        'no_new_privileges_in_caller': nnp,
        'ssh_service_active': ssh_active,
        'ssh_port_22_listening': ssh_listen,
        'cloudflared_installed': bool(binary),
        'dedicated_tunnel_unit_state': unit_state,
        'dedicated_runtime_token_metadata': token,
        'local_stage_prerequisites': (host_match and privileged_installer and ssh_active
                                      and ssh_listen and bool(binary) and token_safe),
        'cloudflare_access_policy_verified': 'NOT_TESTED',
        'tunnel_authenticated': 'NOT_TESTED',
        'ssh_access_e2e': 'NOT_TESTED',
        'sudo_delegation': 'NOT_TESTED',
        'status': ('HOST_MISMATCH' if not host_match else
                   'EXTERNAL_AUTH_AND_E2E_REQUIRED' if unit_state == 'active' else
                   'NOT_INSTALLED_OR_INACTIVE'),
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--target', required=True, choices=sorted(HOSTS))
    parser.add_argument('--require-local-stage-ready', action='store_true')
    args = parser.parse_args(argv)
    current_host = socket.gethostname().split('.')[0]
    try:
        status_text = Path('/proc/self/status').read_text(encoding='utf-8')
    except OSError:
        status_text = ''
    ssh_active = (run_readonly('systemctl', 'is-active', 'ssh.service').strip() == 'active'
                  or run_readonly('systemctl', 'is-active', 'sshd.service').strip() == 'active')
    ssh_listen = ssh_listener(run_readonly('ss', '-H', '-ltn'))
    unit_state = run_readonly('systemctl', 'is-active', 'shopvivaliz-cloudflare-admin.service').strip() or 'inactive'
    token = token_metadata(Path('/etc/shopvivaliz/cloudflare-admin') / (args.target + '.token'))
    result = evaluate(args.target, current_host, os.geteuid(), no_new_privileges(status_text),
                      ssh_active, ssh_listen, shutil.which('cloudflared'), unit_state, token)
    print(json.dumps(result, sort_keys=True, separators=(',', ':')))
    if not result['host_match']:
        return 2
    if args.require_local_stage_ready and not result['local_stage_prerequisites']:
        return 3
    return 0


if __name__ == '__main__':
    sys.exit(main())
