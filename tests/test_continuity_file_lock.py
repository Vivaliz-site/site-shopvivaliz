import importlib.util
import tempfile
import unittest
from pathlib import Path


MODULE = (
    Path(__file__).resolve().parents[1]
    / "scripts"
    / "continuity"
    / "file_lock.py"
)


def load_module():
    spec = importlib.util.spec_from_file_location("continuity_file_lock", MODULE)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class ContinuityFileLockTests(unittest.TestCase):
    def test_lock_is_acquired_and_released_on_current_platform(self):
        module = load_module()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "continuity.lock"
            with module.locked_file(path) as handle:
                self.assertFalse(handle.closed)
                handle.write("held")
                handle.flush()
            self.assertTrue(handle.closed)

        with module.locked_file(path) as handle:
            self.assertFalse(handle.closed)


if __name__ == "__main__":
    unittest.main()
