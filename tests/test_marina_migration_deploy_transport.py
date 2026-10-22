from pathlib import Path
import unittest


WORKFLOW = Path(__file__).parents[1] / ".github" / "workflows" / "marina-migration-deploy.yml"


class MarinaMigrationDeployTransportTests(unittest.TestCase):
    def test_self_hosted_marina_runner_has_fail_closed_local_transport(self):
        text = WORKFLOW.read_text(encoding="utf-8")

        self.assertIn('test "$(hostname -s)" = "marina-a1-4c24g"', text)
        self.assertIn("MARINA_EXECUTION_MODE=local", text)
        self.assertIn("marina_exec()", text)
        self.assertIn('bash -s "$@"', text)
        self.assertIn("ssh \"${ssh_opts[@]}\" \"ubuntu@$MARINA_PUBLIC_IP\" 'bash -s' -- \"$@\"", text)
        self.assertIn('marina_exec "$DEPLOY_SHA" "$AI_BRIDGE_CONFIRMATION" <<\'REMOTE\'', text)
        self.assertIn("MARINA_RUNNER_IDENTITY_REQUIRED", text)

    def test_native_claude_gate_accepts_explicit_oauth_token_source(self):
        text = WORKFLOW.read_text(encoding="utf-8")

        self.assertIn("CLAUDE_CODE_OAUTH_TOKEN=", text)
        self.assertIn("claude_auth_status_required", text)
        self.assertIn("CLAUDE_NATIVE_RUNTIME=READY", text)
        self.assertIn("HEALTH_AUTH_FAILURE_CLASS", text)


if __name__ == "__main__":
    unittest.main()
