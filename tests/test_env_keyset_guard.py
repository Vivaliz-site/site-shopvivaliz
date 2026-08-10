from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "env_keyset_guard.py"
SPEC = importlib.util.spec_from_file_location("env_keyset_guard", MODULE_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("cannot load env_keyset_guard")
mod = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(mod)


class EnvKeysetGuardTest(unittest.TestCase):
    def test_extracts_normal_and_exported_keys(self) -> None:
        keys = mod.keyset_from_text("A=1\nexport B=2\n# C=3\ninvalid-key=4\n")
        self.assertEqual(keys, {"A", "B"})

    def test_monotonic_allows_addition(self) -> None:
        added = mod.assert_monotonic_text("A=1\nB=2\n", "A=9\nB=8\nC=3\n")
        self.assertEqual(added, {"C"})

    def test_monotonic_blocks_deletion(self) -> None:
        with self.assertRaises(mod.KeysetReductionError):
            mod.assert_monotonic_text("A=1\nB=2\n", "A=9\nC=3\n")

    def test_seal_expands_but_never_shrinks(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            env = root / ".env"
            lock = root / ".env.keyset.lock.json"
            env.write_text("A=1\nB=2\n", encoding="utf-8")
            previous, current, added = mod.seal(env, lock)
            self.assertEqual((previous, current, added), (0, 2, 2))

            env.write_text("A=9\nB=8\nC=3\n", encoding="utf-8")
            previous, current, added = mod.seal(env, lock)
            self.assertEqual((previous, current, added), (2, 3, 1))
            locked_before_failure = json.loads(lock.read_text(encoding="utf-8"))["keys"]

            env.write_text("A=9\nC=3\nD=4\n", encoding="utf-8")
            with self.assertRaises(mod.KeysetReductionError):
                mod.seal(env, lock)
            locked_after_failure = json.loads(lock.read_text(encoding="utf-8"))["keys"]
            self.assertEqual(locked_before_failure, locked_after_failure)

    def test_verify_requires_all_sealed_keys(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            env = root / ".env"
            lock = root / ".env.keyset.lock.json"
            env.write_text("A=1\nB=2\n", encoding="utf-8")
            mod.seal(env, lock)
            env.write_text("A=1\n", encoding="utf-8")
            with self.assertRaises(mod.KeysetReductionError):
                mod.verify(env, lock)


if __name__ == "__main__":
    unittest.main()
