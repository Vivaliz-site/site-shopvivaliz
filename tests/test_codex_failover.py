import importlib.util
import pathlib
import unittest
from unittest import mock

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

    def test_parse_keys_accepts_gmail_attachment_aliases(self):
        from tempfile import TemporaryDirectory

        with TemporaryDirectory() as tmp:
            path = pathlib.Path(tmp) / "openai.txt"
            path.write_text("openai_token_1=primary-test\nopenai_token_2=secondary-test\n")
            self.assertEqual(mod.parse_keys(path), ("primary-test", "secondary-test"))


    def test_main_falls_back_to_native_auth_when_both_api_keys_are_unusable(self):
        with mock.patch.object(mod, "parse_keys", return_value=("primary-test", "secondary-test")), \
             mock.patch.object(mod, "read_preferred", return_value="primary"), \
             mock.patch.object(mod, "resolve_codex", return_value="/real/codex"), \
             mock.patch.object(mod, "preflight", return_value=False), \
             mock.patch.object(mod.subprocess, "call", return_value=0) as call:
            rc = mod.main(["exec", "hello"])

        self.assertEqual(rc, 0)
        command = call.call_args.args[0]
        self.assertEqual(command, ["/real/codex", "exec", "hello"])
        env = call.call_args.kwargs["env"]
        self.assertNotIn("OPENAI_API_KEY", env)
        self.assertNotIn("CODEX_API_KEY", env)


if __name__ == "__main__":
    unittest.main()