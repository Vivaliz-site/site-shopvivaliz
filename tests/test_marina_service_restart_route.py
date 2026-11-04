from __future__ import annotations

import importlib.util
from pathlib import Path
import unittest

ROUTE = Path(__file__).resolve().parents[1] / "marina-direct-mcp" / "operator_admin_route.py"
SPEC = importlib.util.spec_from_file_location("operator_admin_route_test", ROUTE)
route = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(route)

UNIT = "shopvivaliz-marina-root-broker.service"
HEAD = "a" * 40
MERGE = "b" * 40


def request(**overrides):
    data = {"operation": "service_restart", "pr_number": 42, "head_sha": HEAD,
            "merge_sha": MERGE, "idempotency_key": "restart-req-0001",
            "fencing_token": 7, "unit": UNIT}
    data.update(overrides)
    return data


class FakeOperator:
    def __init__(self, **overrides):
        self.calls = []
        self.values = {
            "hostname": route.MARINA_HOST, "uid": 0, "grant": True, "pattern": True,
            "runtime": True, "audit": True, "lease": True, "restart": True,
        }
        self.values.update(overrides)

    def hostname(self):
        return self.values["hostname"]

    def effective_uid(self):
        return self.values["uid"]

    def olist_config_is_ready(self):
        self.calls.append("olist_config_is_ready")
        return False

    def owner_pr_grant_is_valid(self, pr, head, merge, capability, operation, target):
        self.calls.append(("grant", operation, target))
        return self.values["grant"]

    def unit_pattern_is_valid(self, unit):
        self.calls.append(("pattern", unit))
        return self.values["pattern"]

    def runtime_permission_is_valid(self):
        return self.values["runtime"]

    def audit_ready(self, operation, rid):
        return self.values["audit"]

    def lease_reserve_once(self, operation, rid, fencing):
        self.calls.append(("lease", operation))
        return self.values["lease"]

    def restart_service(self, unit):
        self.calls.append(("restart", unit))
        return self.values["restart"]


class ServiceRestartRouteTests(unittest.TestCase):
    def test_authorized_pattern_unit_is_restarted(self):
        op = FakeOperator()
        self.assertEqual(route.execute_service_restart(request(), op),
                         (True, "restart_requested"))
        self.assertIn(("restart", UNIT), op.calls)

    def test_olist_gate_is_not_required_for_service_restart(self):
        op = FakeOperator()
        route.execute_service_restart(request(), op)
        self.assertNotIn("olist_config_is_ready", op.calls)

    def test_name_outside_pattern_is_denied_before_any_host_call(self):
        for unit in ("amazon-returns-olist-erp-browser.service", "shopvivaliz-x.timer",
                     "shopvivaliz-../etc.service", "shopvivaliz-Upper.service", "bash"):
            op = FakeOperator()
            self.assertEqual(route.execute_service_restart(request(unit=unit), op),
                             (False, "unit_name_not_in_pattern"), unit)
            self.assertEqual(op.calls, [], unit)

    def test_unit_not_approved_by_pr_rule_is_denied_without_restart(self):
        op = FakeOperator(pattern=False)
        self.assertEqual(route.execute_service_restart(request(), op),
                         (False, "unit_not_authorized_by_human_approved_pr"))
        self.assertNotIn(("restart", UNIT), op.calls)
        self.assertNotIn(("lease", "service_restart"), op.calls)

    def test_grant_for_service_restart_is_bound_to_its_own_operation_and_repository(self):
        op = FakeOperator()
        route.execute_service_restart(request(), op)
        self.assertIn(("grant", "service_restart", "Vivaliz-site/site-shopvivaliz"), op.calls)

    def test_wrong_host_or_non_root_denied(self):
        self.assertEqual(route.execute_service_restart(request(), FakeOperator(hostname="other")),
                         (False, "wrong_host"))
        self.assertEqual(route.execute_service_restart(request(), FakeOperator(uid=1000)),
                         (False, "privileged_host_operator_unavailable"))

    def test_missing_grant_or_lease_or_audit_denies_restart(self):
        for overrides, reason in (({"grant": False}, "human_only_owner_pr_grant_not_verified"),
                                  ({"audit": False}, "protected_audit_unavailable"),
                                  ({"lease": False}, "maintenance_lease_or_replay_denied")):
            op = FakeOperator(**overrides)
            self.assertEqual(route.execute_service_restart(request(), op), (False, reason))
            self.assertNotIn(("restart", UNIT), op.calls)

    def test_unsupported_shapes_and_operations_are_denied(self):
        self.assertEqual(route.execute_service_restart(request(operation="olist_browser_restart"),
                                                       FakeOperator()),
                         (False, "unsupported_operation"))
        extra = request()
        extra["command"] = "rm -rf /"
        self.assertEqual(route.execute_service_restart(extra, FakeOperator()),
                         (False, "unsupported_request_shape"))
        missing_unit = request()
        del missing_unit["unit"]
        self.assertEqual(route.execute_service_restart(missing_unit, FakeOperator()),
                         (False, "unsupported_request_shape"))

    def test_invalid_binding_is_denied(self):
        self.assertEqual(route.execute_service_restart(request(head_sha="x"), FakeOperator()),
                         (False, "invalid_pr_or_lease_binding"))
        self.assertEqual(route.execute_service_restart(request(fencing_token=0), FakeOperator()),
                         (False, "invalid_pr_or_lease_binding"))

    def test_restart_failure_and_host_exception_fail_closed(self):
        self.assertEqual(route.execute_service_restart(request(), FakeOperator(restart=False)),
                         (False, "service_restart_unverified"))

        class Broken(FakeOperator):
            def restart_service(self, unit):
                raise RuntimeError("secret detail must not leak")

        self.assertEqual(route.execute_service_restart(request(), Broken()),
                         (False, "trusted_host_capability_failed"))

    def test_olist_route_still_rejects_service_requests(self):
        self.assertEqual(route.execute_admin_operation(request(), FakeOperator()),
                         (False, "unsupported_request_shape"))


if __name__ == "__main__":
    unittest.main()
