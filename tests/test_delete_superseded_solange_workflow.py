from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
WORKFLOW=ROOT/".github"/"workflows"/"delete-superseded-solange-once.yml"

class DeleteSupersededSolangeWorkflowTests(unittest.TestCase):
    def test_delete_is_exactly_scoped_and_issue_controlled(self):
        text=WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("github.event.issue.number == 1586",text)
        self.assertIn("github.event.comment.user.login == 'fredmourao-ai'",text)
        self.assertIn("github.event.comment.body == '/delete-superseded-solange-v1 CONFIRM'",text)
        self.assertIn("TARGET_REPO: fredmourao-ai/solange-rolla",text)
        self.assertIn("CANONICAL_REPO: fredmourao-ai/solange-rolla-consultorio",text)
        self.assertIn("REPOSITÓRIO HISTÓRICO / SUPERSEDIDO",text)
        self.assertIn('gh repo delete "$TARGET_REPO" --yes',text)
        self.assertNotIn("workflow_dispatch:",text)
        self.assertNotIn("pull_request:",text)

    def test_delete_uses_native_gh_session_not_actions_token(self):
        text=WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("GH_CONFIG_DIR=/home/ubuntu/.config/gh",text)
        self.assertIn("env -u GH_TOKEN -u GITHUB_TOKEN",text)
        self.assertNotIn("secrets.GH_PAT",text)

if __name__=="__main__":
    unittest.main()
