from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "install-governed-repo-runner.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "provision-governed-backend-ci-runners.yml"

class GovernedBackendRunnerProvisionTests(unittest.TestCase):
    def test_installer_allowlists_exact_repositories_and_labels(self):
        text = SCRIPT.read_text(encoding="utf-8")
        for token in (
            "Vivaliz-site/ml-pricing-api|governed-ml-pricing-ci|ml-pricing-ci",
            "Vivaliz-site/mercadolivre-returns-recovery|governed-mlrr-ci|mlrr-ci",
            "Vivaliz-site/shopvivaliz-m365|governed-m365-ci|m365-ci",
        ):
            self.assertIn(token, text)
        self.assertIn("unsupported governed runner identity", text)

    def test_workflow_is_exact_issue_controlled_and_private_backend_only(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("github.event.issue.number == 1586", text)
        self.assertIn("github.event.comment.user.login == 'fredmourao-ai'", text)
        self.assertIn("github.event.comment.body == '/provision-governed-backend-ci-runners-v1'", text)
        self.assertIn("ubuntu@100.66.174.74", text)
        self.assertIn("persist-credentials: false", text)
        self.assertNotIn("pull_request:", text)
        self.assertIn("workflow_call:", text)
        for event in ('schedule:', 'push:', 'workflow_dispatch:', 'issue_comment:'):
            self.assertNotIn(event, text.split('jobs:', 1)[0])
        self.assertIn("for attempt in $(seq 1 12)", text)
        self.assertIn("sleep 5", text)
        self.assertIn('[[ "$online" == "$name" ]]', text)
        self.assertIn("GOVERNED_BACKEND_RUNNERS=PASS", text)
        dispatcher = (ROOT / '.github/workflows/issue-comment-dispatcher.yml').read_text()
        self.assertIn('uses: ./.github/workflows/provision-governed-backend-ci-runners.yml', dispatcher)
        self.assertIn("if: needs.classify.outputs.route == 'provision_backend_runners'", dispatcher)

    def test_scheduled_and_dispatch_runner_routes_are_tailscale_and_hostkey_pinned(self):
        for name in ("provision-governed-backend-ci-runners.yml", "provision-governed-backend-ci-runners-once.yml"):
            text = (ROOT / ".github" / "workflows" / name).read_text(encoding="utf-8")
            with self.subTest(name=name):
                self.assertIn("ubuntu@100.66.174.74", text)
                self.assertIn("100.66.174.74 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMB4WoE2zIDGh8n1OdlgNpTJuYzzln/qHiX6wUAyqnRL", text)
                self.assertIn('UserKnownHostsFile="$HOME/.ssh/shopvivaliz_backend_known_hosts"', text)
                self.assertIn('StrictHostKeyChecking=yes', text)
                self.assertNotIn("ubuntu@10.0.1.38", text)
                self.assertNotIn("StrictHostKeyChecking=no", text)
                self.assertIn('rm -f "$HOME/.ssh/shopvivaliz_vmkey" "$HOME/.ssh/known_hosts" "$HOME/.ssh/shopvivaliz_backend_known_hosts"', text)


if __name__ == "__main__":
    unittest.main()
