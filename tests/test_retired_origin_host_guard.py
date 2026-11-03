import importlib.util
import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "validate_retired_origin_host", ROOT / "scripts/quality/validate-retired-origin-host.py")
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class RetiredOriginHostGuardTest(unittest.TestCase):
    def test_new_executable_reference_is_rejected(self):
        files = {"scripts/new-deploy.sh": "ssh ubuntu@10.0.1.112 'restart'"}
        self.assertEqual(guard.find_violations(files), ["scripts/new-deploy.sh"])

    def test_baseline_file_is_tolerated(self):
        path = next(iter(guard.BASELINE))
        self.assertEqual(guard.find_violations({path: "10.0.1.112"}), [])

    def test_documentation_is_out_of_scope(self):
        files = {"docs/host.md": "10.0.1.112", "NOTES.md": "10.0.1.112"}
        self.assertEqual(guard.find_violations(files), [])

    def test_marina_address_is_allowed(self):
        files = {"scripts/new-deploy.sh": "ssh ubuntu@10.80.1.116 'restart'"}
        self.assertEqual(guard.find_violations(files), [])

    def test_repository_currently_passes(self):
        result = subprocess.run(
            ["python3", str(ROOT / "scripts/quality/validate-retired-origin-host.py")],
            capture_output=True, text=True, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
