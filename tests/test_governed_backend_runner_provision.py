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
        self.assertIn("ubuntu@10.0.1.38", text)
        self.assertIn("persist-credentials: false", text)
        self.assertNotIn("pull_request:", text)
        self.assertIn('cron: "7,22,37,52 * * * *"', text)
        self.assertIn("workflow_dispatch:", text)
        self.assertIn("for attempt in {1..12}", text)
        self.assertIn("sleep 2", text)
        self.assertIn("governed_runner=online", text)
        self.assertIn("did not become online", text)

if __name__ == "__main__":
    unittest.main()
