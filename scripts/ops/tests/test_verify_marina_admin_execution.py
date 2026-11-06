import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[1] / "verify-marina-admin-execution.py"
spec = importlib.util.spec_from_file_location("marina_verify", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class TestReceipt(unittest.TestCase):
    def setUp(self):
        self.good = dict(host="marina-a1-4c24g", command="hostname -s; id -un",
                         state="completed", exit_code=0,
                         stdout="marina-a1-4c24g\nubuntu\n", audit_id="example")
    def test_pass(self):
        self.assertIsNone(module.verify(self.good))
    def test_denial(self):
        self.assertEqual(module.verify({**self.good, "state":"failed", "exit_code":77}),
                         "admin_execution_denied_or_failed")
    def test_catalog_only(self):
        self.assertEqual(module.verify({"host":"marina-a1-4c24g", "admin_enabled":True}),
                         "unexpected_command")
    def test_missing_audit(self):
        self.assertEqual(module.verify({**self.good, "audit_id":""}), "missing_audit_id")
    def test_retired_host(self):
        self.assertEqual(module.verify({**self.good, "host":"shopvivaliz-free-a1"}),
                         "unexpected_host")

if __name__ == "__main__":
    unittest.main()
