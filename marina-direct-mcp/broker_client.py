#!/usr/bin/env python3
"""Default-off unprivileged client for Marina's fixed local maintenance broker.

No shell, privilege elevation, caller-selected socket, token access, retries or
production activation. The root broker revalidates the human grant per action.
"""
from __future__ import annotations

import json
import os
import pwd
import re
import socket
import stat
import struct
import time
from pathlib import Path

EXPECTED_HOST = 'marina-a1-4c24g'
SERVICE_USER = 'shopvivaliz-marina-mcp'
SOCKET_PATH = '/run/shopvivaliz-marina-broker/mcp-root.sock'
GRANT_CONFIG = Path('/etc/shopvivaliz/marina-root-broker/client-grant.json')
OPERATION = 'olist_browser_restart'
MAX_RESPONSE_BYTES = 8192
CALL_TIMEOUT_SECONDS = 80.0
GRANT_FIELDS = frozenset({'operation', 'pr_number', 'head_sha', 'merge_sha'})
EXECUTE_FIELDS = GRANT_FIELDS | {'idempotency_key', 'fencing_token'}


def _no_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate_json_key')
        result[key] = value
    return result


def _valid_grant(data: object) -> bool:
    return (isinstance(data, dict) and set(data) == GRANT_FIELDS
            and data.get('operation') == OPERATION
            and type(data.get('pr_number')) is int and data['pr_number'] > 0
            and all(type(data.get(k)) is str and re.fullmatch(r'[0-9a-f]{40}', data[k])
                    for k in ('head_sha', 'merge_sha')))


def load_grant_config() -> dict:
    """Read non-secret operator-installed metadata; never write/provision a grant."""
    try:
        for parent in GRANT_CONFIG.parents:
            meta = parent.lstat()
            if (not stat.S_ISDIR(meta.st_mode) or meta.st_uid != 0
                    or stat.S_IMODE(meta.st_mode) & 0o022):
                raise RuntimeError('broker_grant_metadata_invalid')
        fd = os.open(GRANT_CONFIG, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        with os.fdopen(fd, 'rb') as stream:
            meta = os.fstat(stream.fileno())
            if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != 0
                    or stat.S_IMODE(meta.st_mode) not in {0o400, 0o440, 0o444, 0o600, 0o640, 0o644}
                    or meta.st_size > 4096):
                raise RuntimeError('broker_grant_metadata_invalid')
            raw = stream.read(4097)
        if len(raw) > 4096:
            raise ValueError('oversized_grant')
        result = json.loads(raw, object_pairs_hook=_no_duplicates)
        if not _valid_grant(result):
            raise ValueError('invalid_grant')
        return result
    except (OSError, ValueError, UnicodeError):
        raise RuntimeError('broker_grant_metadata_unavailable') from None


class BrokerClient:
    """Authenticates the root socket; never masquerades as a root operator."""
    def __init__(self, grant: dict):
        if not _valid_grant(grant):
            raise ValueError('invalid_broker_grant')
        self._grant = dict(grant)

    def preflight(self, request: object) -> dict:
        return self._call('preflight', request)

    def prepare(self, request: object) -> dict:
        return self._call('prepare', request)

    def execute(self, request: object) -> dict:
        return self._call('execute', request)

    def _valid_request(self, action: str, request: object) -> bool:
        if not isinstance(request, dict):
            return False
        fields = EXECUTE_FIELDS if action == 'execute' else GRANT_FIELDS
        if set(request) != fields or any(request.get(k) != v or type(request.get(k)) is not type(v)
                                         for k, v in self._grant.items()):
            return False
        if action == 'execute':
            return (type(request['fencing_token']) is int and 0 < request['fencing_token'] <= 2**63-1
                    and type(request['idempotency_key']) is str
                    and re.fullmatch(r'broker-[0-9a-f]{32}', request['idempotency_key']) is not None)
        return action in {'preflight', 'prepare'}

    def _validated_response(self, action: str, value: object) -> dict:
        if not isinstance(value, dict) or type(value.get('ok')) is not bool:
            raise ValueError('invalid_broker_response')
        if value['ok'] is False:
            if (set(value) != {'ok', 'reason'} or type(value['reason']) is not str
                    or re.fullmatch(r'[a-z][a-z0-9_]{0,79}', value['reason']) is None):
                raise ValueError('invalid_broker_denial')
            return value
        if action == 'execute':
            if value != {'ok': True, 'reason': 'restart_requested'}:
                raise ValueError('invalid_broker_result')
        elif action == 'preflight':
            if value != {'ok': True, 'operation': OPERATION}:
                raise ValueError('invalid_broker_preflight')
        else:
            now = time.time()
            if (set(value) != EXECUTE_FIELDS | {'ok', 'expires_at'}
                    or not self._valid_request('execute', {k: value[k] for k in EXECUTE_FIELDS})
                    or type(value['expires_at']) is not int
                    or not now < value['expires_at'] <= now + 90):
                raise ValueError('invalid_broker_ticket')
        return value

    def _call(self, action: str, request: object) -> dict:
        if not self._valid_request(action, request):
            return {'ok': False, 'reason': 'invalid_broker_request'}
        deadline = time.monotonic() + CALL_TIMEOUT_SECONDS
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
                conn.settimeout(CALL_TIMEOUT_SECONDS)
                conn.connect(SOCKET_PATH)
                # Authenticate the listening process before sending metadata.
                _, peer_uid, _ = struct.unpack('3i', conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
                if peer_uid != 0:
                    return {'ok': False, 'reason': 'broker_peer_identity_denied'}
                frame = json.dumps({'action': action, 'request': request}, separators=(',', ':')).encode() + b'\n'
                conn.sendall(frame)
                reply = bytearray()
                while b'\n' not in reply:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0:
                        raise TimeoutError()
                    conn.settimeout(remaining)
                    chunk = conn.recv(min(2048, MAX_RESPONSE_BYTES + 1 - len(reply)))
                    if not chunk:
                        raise ValueError('incomplete_broker_frame')
                    reply.extend(chunk)
                    if len(reply) > MAX_RESPONSE_BYTES:
                        raise ValueError('oversized_broker_frame')
                if not reply.endswith(b'\n') or reply.count(b'\n') != 1:
                    raise ValueError('invalid_broker_frame')
                value = json.loads(reply, object_pairs_hook=_no_duplicates)
                return self._validated_response(action, value)
        except (ValueError, UnicodeError, struct.error):
            reason = 'broker_result_indeterminate' if action == 'execute' else 'broker_response_invalid'
            return {'ok': False, 'reason': reason}
        except OSError:
            # Missing/late execute response cannot prove whether the service
            # changed. Never retry or invent success; reconcile the effect.
            reason = 'broker_result_indeterminate' if action == 'execute' else 'broker_unavailable'
            return {'ok': False, 'reason': reason}


def bootstrap_client() -> BrokerClient:
    try:
        uid = os.geteuid()
        expected_uid = pwd.getpwnam(SERVICE_USER).pw_uid
        if uid == 0 or uid != expected_uid or socket.gethostname().split('.')[0] != EXPECTED_HOST:
            raise RuntimeError('broker_client_identity_denied')
        client = BrokerClient(load_grant_config())
        if client.preflight(client._grant).get('ok') is not True:
            raise RuntimeError('broker_preflight_denied')
        return client
    except (KeyError, OSError, ValueError):
        raise RuntimeError('broker_client_unavailable') from None
