"""Safe root-only regression of the DORMANT Marina Unix maintenance broker.

CI runs as sudo root in an isolated temporary directory. NEVER calls
production systemctl nor provider APIs. All service actions are mocked.
"""
from __future__ import annotations

import importlib.util
import json
import os
import socket
import sqlite3
import tempfile
import threading
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "root_broker.py"
spec = importlib.util.spec_from_file_location("marina_root_broker_test", SOURCE)
assert spec and spec.loader
broker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(broker)
HEAD = "a" * 40
MERGE = "b" * 40
PR = 3530
REQUEST = {"operation": broker.OPERATION, "pr_number": PR,
           "head_sha": HEAD, "merge_sha": MERGE}


class RootBrokerTests(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0:
            self.skipTest("Run this isolated test as root under CI sudo")
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.state = Path(self.temp.name)
        self.calls = []
        def runner(argv, **kwargs):
            self.calls.append(tuple(argv))
            if argv[1] == "show":
                return SimpleNamespace(returncode=0, stdout="loaded\n")
            if argv[1] == "is-active":
                return SimpleNamespace(returncode=0, stdout="active\n")
            if argv[1] == "restart":
                return SimpleNamespace(returncode=0, stdout="")
            raise AssertionError("unexpected privileged command")
        self.runner = runner
        self.grants = []
        def check(*args):
            self.grants.append(args)
            return True
        self.op = broker.BrokerOperator(state=self.state, grant_checker=check,
                                       runner=runner)
        self.patch_host = patch.object(broker.socket, "gethostname", return_value=broker.HOST)
        self.patch_host.start()
        self.addCleanup(self.patch_host.stop)

    def ticket_to_request(self, ticket):
        return {k: ticket[k] for k in (
            "operation", "pr_number", "head_sha", "merge_sha",
            "idempotency_key", "fencing_token")}

    def test_preflight_checks_grant_without_issuing_lease_or_mutating(self):
        self.assertTrue(hasattr(self.op, "preflight"), "broker lacks side-effect-free preflight")
        self.assertEqual(self.op.preflight(REQUEST), {"ok": True, "operation": broker.OPERATION})
        self.assertFalse((self.state / "leases.sqlite3").exists())
        self.assertFalse((self.state / "broker-audit.jsonl").exists())
        self.assertFalse(any(x[1] == "restart" for x in self.calls))
        self.op.grant_checker = lambda *args: False
        self.assertEqual(self.op.preflight(REQUEST)["reason"], "owner_pr_grant_denied")
        self.assertEqual(self.op.preflight({**REQUEST, "command": "id"})["reason"], "invalid_grant_request")

    def test_client_real_unix_roundtrip_and_replay_without_real_systemctl(self):
        import runpy
        module = runpy.run_path(str(SOURCE.with_name("broker_client.py")))
        cls = module["BrokerClient"]
        path = str(self.state / "fixture.sock")
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        listener.bind(path)
        listener.listen(5)
        listener.settimeout(5)
        failures = []
        def serve():
            try:
                for _ in range(4):
                    conn, _ = listener.accept()
                    with conn:
                        broker._serve_connection(conn, self.op, os.geteuid())
            except Exception as exc:
                failures.append(type(exc).__name__)
        thread = threading.Thread(target=serve, daemon=True)
        thread.start()
        try:
            with patch.dict(cls._call.__globals__, {"SOCKET_PATH": path}):
                client = cls(REQUEST)
                self.assertTrue(client.preflight(REQUEST)["ok"])
                self.assertFalse((self.state / "leases.sqlite3").exists())
                ticket = client.prepare(REQUEST)
                self.assertTrue(ticket["ok"], ticket)
                request = self.ticket_to_request(ticket)
                self.assertTrue(client.execute(request)["ok"])
                self.assertEqual(client.execute(request)["reason"], "maintenance_lease_or_replay_denied")
        finally:
            thread.join(5)
            listener.close()
        self.assertFalse(thread.is_alive())
        self.assertEqual(failures, [])
        self.assertEqual(sum(x[1] == "restart" for x in self.calls), 1)

    def test_client_config_requires_protected_real_file_and_ancestors(self):
        import runpy
        module = runpy.run_path(str(SOURCE.with_name("broker_client.py")))
        load = module["load_grant_config"]
        with tempfile.TemporaryDirectory(dir="/root") as tmp:
            directory = Path(tmp)
            config = directory / "grant.json"
            config.write_text(json.dumps(REQUEST))
            config.chmod(0o644)
            with patch.dict(load.__globals__, {"GRANT_CONFIG": config}):
                self.assertEqual(load(), REQUEST)
                for mode in (0o666, 0o775):
                    config.chmod(mode)
                    with self.assertRaises(RuntimeError):
                        load()
                config.chmod(0o644)
                os.chown(config, 65534, 65534)
                with self.assertRaises(RuntimeError):
                    load()
                os.chown(config, 0, 0)
                config.write_text('{"operation":"olist_browser_restart","pr_number":1,"pr_number":2}')
                with self.assertRaises(RuntimeError):
                    load()
                config.write_text(json.dumps(REQUEST))
                directory.chmod(0o777)
                with self.assertRaises(RuntimeError):
                    load()
                directory.chmod(0o700)
                target = directory / "real.json"
                config.rename(target)
                config.symlink_to(target)
                with self.assertRaises(RuntimeError):
                    load()


    def test_newer_fencing_ticket_invalidates_previous_unconsumed_ticket(self):
        first = self.ticket_to_request(self.op.prepare(REQUEST))
        second = self.ticket_to_request(self.op.prepare(REQUEST))
        self.assertGreater(second["fencing_token"], first["fencing_token"])
        self.assertEqual(self.op.execute(first),
                         {"ok": False, "reason": "maintenance_lease_or_replay_denied"})
        self.assertTrue(self.op.execute(second)["ok"])
        self.assertEqual(sum(x[1] == "restart" for x in self.calls), 1)

    def test_lease_is_expired_at_exact_deadline(self):
        with patch.object(broker.time, "time", return_value=1000):
            ticket = self.op.prepare(REQUEST)
        with patch.object(broker.time, "time", return_value=ticket["expires_at"]):
            result = self.op.execute(self.ticket_to_request(ticket))
        self.assertEqual(result, {"ok": False, "reason": "maintenance_lease_or_replay_denied"})
        self.assertFalse(any(x[1] == "restart" for x in self.calls))

    def test_ledger_connections_close_after_prepare_and_denied_reservation(self):
        opened = []
        connect = sqlite3.connect
        def tracked(*args, **kwargs):
            con = connect(*args, **kwargs)
            opened.append(con)
            return con
        with patch.object(broker.sqlite3, "connect", side_effect=tracked):
            ticket = self.op.prepare(REQUEST)
            request = self.ticket_to_request(ticket)
            denied = self.op.execute({**request, "fencing_token": ticket["fencing_token"] + 1})
        self.assertFalse(denied["ok"])
        self.assertEqual(len(opened), 2)
        for con in opened:
            with self.assertRaises(sqlite3.ProgrammingError):
                con.execute("SELECT 1")

    def test_ledger_initialization_error_closes_connection(self):
        opened = []
        connect = sqlite3.connect
        class BrokenSchemaConnection(sqlite3.Connection):
            def execute(self, sql, *args):
                if sql.startswith("CREATE TABLE"):
                    raise sqlite3.OperationalError("controlled_schema_failure")
                return super().execute(sql, *args)
        def tracked(*args, **kwargs):
            con = connect(*args, factory=BrokenSchemaConnection, **kwargs)
            opened.append(con)
            return con
        with patch.object(broker.sqlite3, "connect", side_effect=tracked):
            result = self.op.prepare(REQUEST)
        self.assertEqual(result, {"ok": False, "reason": "protected_lease_unavailable"})
        self.assertEqual(len(opened), 1)
        with self.assertRaises(sqlite3.ProgrammingError):
            opened[0].execute("SELECT 1")

    def test_positive_fixed_restart_and_one_time_lease(self):
        lease = self.op.prepare(REQUEST)
        self.assertTrue(lease["ok"])
        self.assertEqual(lease["operation"], broker.OPERATION)
        req = self.ticket_to_request(lease)
        success = self.op.execute(req)
        self.assertEqual(success, {"ok": True, "reason": "restart_requested"})
        self.assertEqual(self.op.execute(req)["reason"], "maintenance_lease_or_replay_denied")
        restarts = [x for x in self.calls if x[:2] == ("/usr/bin/systemctl", "restart")]
        self.assertEqual(restarts, [("/usr/bin/systemctl", "restart", broker.UNIT)])
        self.assertEqual(self.grants[0], (PR, HEAD, MERGE, broker.CAPABILITY,
                                         broker.OPERATION, broker.TARGET_REPOSITORY))
        audit = (self.state / "broker-audit.jsonl").read_text()
        self.assertIn('"event":"operation_result"', audit)
        self.assertNotIn("ghp_", audit)
        self.assertEqual((self.state / "broker-audit.jsonl").stat().st_mode & 0o777, 0o600)

    def test_rejects_wrong_grant_and_unexpected_operations(self):
        self.assertEqual(self.op.prepare({"operation": "shell_run", **{k:v for k,v in REQUEST.items() if k!="operation"}})["reason"],
                         "invalid_grant_request")
        self.assertEqual(self.op.prepare({**REQUEST, "command": "id"})["reason"],
                         "invalid_grant_request")
        self.assertEqual(self.op.prepare({**REQUEST, "pr_number": True})["reason"],
                         "invalid_grant_request")
        badop = broker.BrokerOperator(state=self.state, grant_checker=lambda *a: False,
                                     runner=self.runner)
        self.assertEqual(badop.prepare(REQUEST)["reason"], "owner_pr_grant_denied")
        self.assertEqual(self.op.execute({"operation": "shell", "command": "id"})["reason"],
                         "unsupported_request_shape")
        self.assertFalse(any(x[1] == "restart" for x in self.calls))

    def test_invalid_fence_and_binding_do_not_consume_lease(self):
        lease = self.op.prepare(REQUEST)
        request = self.ticket_to_request(lease)
        self.assertEqual(self.op.execute({**request, "fencing_token": request["fencing_token"] + 1})["reason"],
                         "maintenance_lease_or_replay_denied")
        self.assertEqual(self.op.execute({**request, "head_sha": "c" * 40})["reason"],
                         "maintenance_lease_or_replay_denied")
        self.assertTrue(self.op.execute(request)["ok"])
        self.assertEqual(sum(x[1] == "restart" for x in self.calls), 1)

    def test_expired_lease_denied(self):
        lease = self.op.prepare(REQUEST)
        with sqlite3.connect(str(self.state / "leases.sqlite3")) as con:
            con.execute("UPDATE leases SET expires_at=0")
        self.assertEqual(self.op.execute(self.ticket_to_request(lease))["reason"],
                         "maintenance_lease_or_replay_denied")

    def test_root_identity_host_and_immutable_state_required(self):
        with patch.object(self.op, "effective_uid", return_value=1001):
            self.assertEqual(self.op.prepare(REQUEST)["reason"], "protected_root_operator_unavailable")
        with patch.object(self.op, "hostname", return_value="other-vm"):
            self.assertEqual(self.op.prepare(REQUEST)["reason"], "protected_root_operator_unavailable")
        fake = self.state.parent / "unsafe-symlink-state"
        fake.symlink_to(self.state, target_is_directory=True)
        self.addCleanup(fake.unlink)
        denied = broker.BrokerOperator(state=fake, grant_checker=lambda *a: True,
                                      runner=self.runner)
        self.assertEqual(denied.prepare(REQUEST)["reason"], "protected_root_operator_unavailable")

    def test_unix_socket_peer_identity_and_frame_controls(self):
        def request(who, payload):
            a, b = socket.socketpair(socket.AF_UNIX, socket.SOCK_STREAM)
            th = threading.Thread(target=broker._serve_connection, args=(a, self.op, who))
            th.start()
            try:
                b.sendall(payload)
                b.settimeout(4)
                received = b""
                while b"\n" not in received:
                    chunk = b.recv(4096)
                    if not chunk:
                        break
                    received += chunk
                return json.loads(received)
            finally:
                b.close()
                a.close()
                th.join(2)
        payload = json.dumps({"action": "prepare", "request": REQUEST}).encode() + b"\n"
        self.assertTrue(request(os.geteuid(), payload)["ok"])
        self.assertEqual(request(os.geteuid() + 1, payload)["reason"], "peer_identity_denied")
        self.assertEqual(request(os.geteuid(), b"x" * 8500 + b"\n")["reason"],
                         "request_size_or_frame_invalid")
        self.assertEqual(request(os.geteuid(), b"{}\n")["reason"], "request_shape_invalid")

    def test_daemon_refuses_non_systemd_invocation(self):
        with patch.dict(os.environ, {"LISTEN_FDS":"0","LISTEN_PID":str(os.getpid())}):
            self.assertEqual(broker.main(), 3)


if __name__ == "__main__":
    unittest.main(verbosity=2)
