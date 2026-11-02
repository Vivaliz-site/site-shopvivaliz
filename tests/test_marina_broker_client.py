"""Unprivileged MCP -> fixed local root broker; no production effects."""
import importlib.util
import json
import struct
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / 'marina-direct-mcp' / 'broker_client.py'
GRANT = {'operation': 'olist_browser_restart', 'pr_number': 4000,
         'head_sha': 'a' * 40, 'merge_sha': 'b' * 40}
TICKET = {**GRANT, 'ok': True, 'idempotency_key': 'broker-' + 'a' * 32,
          'fencing_token': 1, 'expires_at': 1060}
REQUEST = {k: v for k, v in TICKET.items() if k not in {'ok', 'expires_at'}}

class WireSocket:
    def __init__(self, reply, uid=0, error=None):
        self.reply = bytearray(reply)
        self.uid, self.error = uid, error
        self.sent = []
        self.address = None
    def __enter__(self):
        return self
    def __exit__(self, *args):
        return False
    def settimeout(self, timeout):
        self.timeout = timeout
    def connect(self, address):
        self.address = address
        if self.error:
            raise self.error
    def getsockopt(self, *args):
        return struct.pack('3i', 123, self.uid, 0)
    def sendall(self, data):
        self.sent.append(data)
    def recv(self, size):
        out = bytes(self.reply[:size])
        del self.reply[:size]
        return out

class BrokerClientTests(unittest.TestCase):
    def load(self):
        self.assertTrue(SOURCE.is_file(), 'missing protected Unix broker client')
        spec = importlib.util.spec_from_file_location('marina_broker_client', SOURCE)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module
    def exchange(self, action, response, *, request=None, uid=0, error=None):
        m = self.load()
        wire = WireSocket(response if isinstance(response, bytes) else json.dumps(response).encode() + b'\n', uid, error)
        with patch.object(m.socket, 'socket', return_value=wire), patch.object(m.time, 'time', return_value=1000):
            client = m.BrokerClient(GRANT)
            result = getattr(client, action)(request if request is not None else (REQUEST if action == 'execute' else GRANT))
        return result, wire, m
    def test_preflight_uses_fixed_unix_socket_and_checks_root_peer(self):
        result, wire, m = self.exchange('preflight', {'ok': True, 'operation': GRANT['operation']})
        self.assertTrue(result['ok'])
        self.assertEqual(wire.address, '/run/shopvivaliz-marina-broker/mcp-root.sock')
        self.assertEqual(json.loads(wire.sent[0]), {'action': 'preflight', 'request': GRANT})
        self.assertFalse(hasattr(m.BrokerClient, 'effective_uid'))
    def test_untrusted_peer_denied_before_sending_request(self):
        result, wire, _ = self.exchange('prepare', TICKET, uid=1000)
        self.assertEqual(result, {'ok': False, 'reason': 'broker_peer_identity_denied'})
        self.assertEqual(wire.sent, [])
    def test_prepare_returns_host_issued_ticket_without_changing_binding(self):
        result, wire, _ = self.exchange('prepare', TICKET)
        self.assertEqual(result, TICKET)
        self.assertEqual(json.loads(wire.sent[0])['request'], GRANT)
    def test_execute_transmits_exact_request_once(self):
        result, wire, _ = self.exchange('execute', {'ok': True, 'reason': 'restart_requested'})
        self.assertEqual(result, {'ok': True, 'reason': 'restart_requested'})
        self.assertEqual(len(wire.sent), 1)
        self.assertEqual(json.loads(wire.sent[0]), {'action': 'execute', 'request': REQUEST})
    def test_denial_is_preserved_and_not_retried(self):
        for reason in ('owner_pr_grant_denied', 'maintenance_lease_or_replay_denied'):
            result, wire, _ = self.exchange('execute', {'ok': False, 'reason': reason})
            self.assertEqual(result, {'ok': False, 'reason': reason})
            self.assertEqual(len(wire.sent), 1)
    def test_unknown_operation_extra_fields_and_other_grant_never_reach_socket(self):
        for action, req in [('prepare', {**GRANT, 'operation': 'shell'}),
                            ('prepare', {**GRANT, 'pr_number': True}),
                            ('prepare', {**GRANT, 'command': 'id'}),
                            ('prepare', {**GRANT, 'head_sha': 'c' * 40}),
                            ('execute', {**REQUEST, 'fencing_token': True}),
                            ('execute', {**REQUEST, 'fencing_token': 0}),
                            ('execute', {**REQUEST, 'idempotency_key': '../x'}),
                            ('execute', {**REQUEST, 'command': 'id'})]:
            with self.subTest(action=action, request=req):
                result, wire, _ = self.exchange(action, TICKET, request=req)
                self.assertEqual(result, {'ok': False, 'reason': 'invalid_broker_request'})
                self.assertEqual(wire.sent, [])
                self.assertIsNone(wire.address)
    def test_malformed_or_unbounded_response_does_not_leak_payload(self):
        for payload in (b'not-json\n', b'{}', b'[]\n', b'x' * 9000 + b'\n',
                        b'{"ok":true,"ok":false}\n', b'{}\n{}\n',
                        b'{"ok":false,"reason":"secret value!"}\n',
                        b'{"ok":true,"reason":"restart_requested","secret":"test"}\n'):
            with self.subTest(payload=payload[:50]):
                result, _, _ = self.exchange('execute', payload)
                self.assertEqual(result, {'ok': False, 'reason': 'broker_result_indeterminate'})
                self.assertNotIn('secret', json.dumps(result))
    def test_prepare_rejects_wrong_binding_expiry_and_bool_fencing(self):
        for change in ({'pr_number': 9999}, {'head_sha': 'f' * 40},
                       {'expires_at': 999}, {'expires_at': 2000},
                       {'fencing_token': True}, {'idempotency_key': 'unsafe'},
                       {'operation': 'shell'}, {'ok': 1}):
            with self.subTest(change=change):
                result, _, _ = self.exchange('prepare', {**TICKET, **change})
                self.assertEqual(result, {'ok': False, 'reason': 'broker_response_invalid'})
    def test_no_retry_on_timeout_and_indeterminate_execute(self):
        result, wire, _ = self.exchange('execute', b'', error=TimeoutError())
        self.assertEqual(result, {'ok': False, 'reason': 'broker_result_indeterminate'})
        self.assertLessEqual(len(wire.sent), 1)
        result, _, _ = self.exchange('prepare', b'', error=FileNotFoundError())
        self.assertEqual(result, {'ok': False, 'reason': 'broker_unavailable'})
    def test_constructor_rejects_invalid_grants(self):
        m = self.load()
        for data in (None, {}, {**GRANT, 'pr_number': True}, {**GRANT, 'socket_path': '/tmp/other'}):
            with self.subTest(data=data), self.assertRaises(ValueError):
                m.BrokerClient(data)
    def test_bootstrap_refuses_root_and_wrong_host_before_socket(self):
        from types import SimpleNamespace
        m = self.load()
        for uid, host in [(0, m.EXPECTED_HOST), (1005, 'other-host')]:
            with self.subTest(uid=uid, host=host), patch.object(m.os, 'geteuid', return_value=uid), patch.object(m.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=1005)), patch.object(m.socket, 'gethostname', return_value=host), patch.object(m.socket, 'socket') as connect, self.assertRaises(RuntimeError):
                m.bootstrap_client()
            connect.assert_not_called()
    def test_bootstrap_requires_fresh_broker_grant_verification(self):
        from types import SimpleNamespace
        m = self.load()
        for allowed in (True, False):
            result = {'ok': True, 'operation': GRANT['operation']} if allowed else {'ok': False, 'reason': 'owner_pr_grant_denied'}
            wire = WireSocket(json.dumps(result).encode() + b'\n')
            with patch.object(m.os, 'geteuid', return_value=1005), patch.object(m.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=1005)), patch.object(m.socket, 'gethostname', return_value=m.EXPECTED_HOST), patch.object(m, 'load_grant_config', return_value=GRANT), patch.object(m.socket, 'socket', return_value=wire):
                if allowed:
                    self.assertIsInstance(m.bootstrap_client(), m.BrokerClient)
                else:
                    with self.assertRaises(RuntimeError):
                        m.bootstrap_client()
            self.assertEqual(json.loads(wire.sent[0])['action'], 'preflight')

if __name__ == '__main__':
    unittest.main(verbosity=2)
