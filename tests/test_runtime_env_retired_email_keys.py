from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RETIRE_MODULE = ROOT / "scripts" / "retire-legacy-email-runtime-keys.py"
WORKFLOW = ROOT / ".github" / "workflows" / "runtime-env-keyset-lock.yml"


def load_retire_module():
    spec = importlib.util.spec_from_file_location("retire_email_keys", RETIRE_MODULE)
    if spec is None or spec.loader is None:
        raise RuntimeError("import_failed")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class RuntimeEnvRetiredEmailKeysContractTests(unittest.TestCase):
    def test_keyset_lock_explicitly_retires_every_legacy_email_key(self) -> None:
        module = load_retire_module()
        workflow = WORKFLOW.read_text(encoding="utf-8")
        for key in sorted(module.LEGACY_KEYS):
            self.assertGreaterEqual(
                workflow.count(f"--retire-key {key}"),
                2,
                f"{key} must be retired in both seal and verify commands",
            )


if __name__ == "__main__":
    unittest.main()
