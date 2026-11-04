from __future__ import annotations

import importlib.util
from pathlib import Path
import unittest

CLIENT = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "broker_client.py"
SPEC = importlib.util.spec_from_file_location("broker_client_service_test", CLIENT)
m = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(m)

HEAD = "a" * 40
MERGE = "b" * 40
KEY = "broker-" + "c" * 32
GRANT = {"operation": m.SERVICE_OPERATION, "pr_number": 42, "head_sha": HEAD, "merge_sha": MERGE}
TICKET = dict(GRANT, idempotency_key=KEY, fencing_token=7)


class ServiceClientTests(unittest.TestCase):
    def setUp(self):
        self.client = m.BrokerClient(GRANT, m.SERVICE_OPERATION)

    def test_grant_is_bound_to_one_operation(self):
        with self.assertRaises(ValueError):
            m.BrokerClient(GRANT)  # default is the Olist operation
        with self.assertRaises(ValueError):
            m.BrokerClient(dict(GRANT, operation=m.OPERATION), m.SERVICE_OPERATION)

    def test_prepare_takes_only_grant_fields(self):
        self.assertTrue(self.client._valid_request("prepare", dict(GRANT)))
        self.assertFalse(self.client._valid_request("prepare", dict(GRANT, unit="shopvivaliz-x.service")))

    def test_execute_requires_pattern_unit(self):
        ok = dict(TICKET, unit="shopvivaliz-desktop-commander.service")
        self.assertTrue(self.client._valid_request("execute", ok))
        for bad in ("sshd.service", "shopvivaliz-x.timer", "shopvivaliz-../x.service", "", None):
            self.assertFalse(self.client._valid_request("execute", dict(TICKET, unit=bad)), bad)
        self.assertFalse(self.client._valid_request("execute", dict(TICKET)))

    def test_execute_rejects_foreign_grant_fields(self):
        request = dict(TICKET, unit="shopvivaliz-desktop-commander.service", pr_number=43)
        self.assertFalse(self.client._valid_request("execute", request))

    def test_ticket_validation_does_not_require_unit(self):
        self.assertTrue(self.client._valid_ticket(TICKET))

    def test_preflight_response_echoes_service_operation(self):
        self.assertEqual({"ok": True, "operation": m.SERVICE_OPERATION},
                         self.client._validated_response("preflight", {"ok": True, "operation": m.SERVICE_OPERATION}))
        with self.assertRaises(ValueError):
            self.client._validated_response("preflight", {"ok": True, "operation": m.OPERATION})


if __name__ == "__main__":
    unittest.main()
