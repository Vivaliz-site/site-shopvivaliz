import importlib.util
import pathlib
import unittest

SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "codex-failover.py"
spec = importlib.util.spec_from_file_location("codex_failover", SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class CodexFailoverTests(unittest.TestCase):
    def test_unusable_http_statuses_trigger_failover(self):
        for status, body in [
            (401, ""),
            (402, ""),
            (403, ""),
            (429, '{"error":{"code":"rate_limit_exceeded"}}'),
            (429, '{"error":{"code":"usage_limit_reached"}}'),
            (429, '{"error":{"code":"insufficient_quota"}}'),
        ]:
            with self.subTest(status=status, body=body):
                self.assertFalse(mod.http_response_usable(status, body))

    def test_failed_primary_switches_to_secondary_when_primary_becomes_unusable(self):
        checks = {"p": [True, False], "s": [True]}
        calls = []

        def checker(key):
            return checks[key].pop(0)

        def runner(key):
            calls.append(key)
            return 7 if key == "p" else 0

        rc, active = mod.run_with_failover("p", "s", "primary", checker, runner)
        self.assertEqual((rc, active), (0, "secondary"))
        self.assertEqual(calls, ["p", "s"])


if __name__ == "__main__":
    unittest.main()