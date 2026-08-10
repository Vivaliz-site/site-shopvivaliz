from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class EnvWriterMonotonicPolicyTest(unittest.TestCase):
    def test_python_writers_call_monotonic_guard_before_replace(self) -> None:
        protected = [
            ROOT / "scripts" / "configure-production-runtime.py",
            ROOT / "daemon-token-renewer.py",
            ROOT / "daemon-shopee-token-renewer.py",
            ROOT / "daemon-google-token-renewer.py",
        ]
        for path in protected:
            source = path.read_text(encoding="utf-8")
            with self.subTest(path=str(path.relative_to(ROOT))):
                guard_call = source.find("assert_monotonic_text(")
                replace_call = source.find("os.replace(")
                self.assertGreaterEqual(guard_call, 0, "monotonic guard call missing")
                self.assertGreaterEqual(replace_call, 0, "atomic replace call missing")
                self.assertLess(guard_call, replace_call, "guard must run before atomic replace")

    def test_production_lock_is_root_owned_and_fail_closed(self) -> None:
        workflow = (ROOT / ".github" / "workflows" / "runtime-env-keyset-lock.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("/etc/shopvivaliz/env-keyset.lock.json", workflow)
        self.assertIn('sudo python3 "$guard" seal', workflow)
        self.assertIn('sudo python3 "$guard" verify', workflow)
        self.assertIn('sudo chown root:root "$VM_LOCK"', workflow)
        self.assertIn('sudo chmod 0444 "$VM_LOCK"', workflow)
        self.assertNotIn("cat $VM_ENV", workflow)


if __name__ == "__main__":
    unittest.main()
