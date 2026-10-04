import importlib.util
import json
import re
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ROUTER_PATH = ROOT / "scripts" / "issue-comment-router.py"
WORKFLOWS = ROOT / ".github" / "workflows"

spec = importlib.util.spec_from_file_location("issue_comment_router", ROUTER_PATH)
router = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(router)


def payload(body, issue=1586, user="fredmourao-ai"):
    return {
        "issue": {"number": issue},
        "comment": {"body": body, "user": {"login": user}},
    }


class IssueCommentRouterTest(unittest.TestCase):
    def test_only_dispatcher_subscribes_to_issue_comment(self):
        listeners = []
        for path in WORKFLOWS.glob("*.yml"):
            text = path.read_text(encoding="utf-8")
            if re.search(r"(?m)^  issue_comment:\s*$", text):
                listeners.append(path.name)
        self.assertEqual(["issue-comment-dispatcher.yml"], sorted(listeners))

    def test_explicit_routes_are_single_and_stable(self):
        cases = {
            "/dc-reauth target=fred-win phase=begin": "dc_reauth",
            "/remote target=fred-win action=health reason=test": "remote_access",
            "/mlrr operation=validate reason=test": "mlrr",
            "/continuity-e2e conversation_id=abcDEF_12345678": "continuity_e2e",
            "/backend-continuity-recover-v1": "backend_continuity_recovery",
            "/provision-governed-backend-ci-runners-v1": "provision_backend_runners",
            "/refresh-backend-delete-repo-scope-v1 CONFIRM": "refresh_backend_delete_scope",
            "/refresh-a1-delete-repo-scope-v1 CONFIRM": "refresh_a1_delete_scope",
            "/delete-superseded-solange-v1 CONFIRM": "delete_superseded_solange",
            "/codex-remote-control-mcp-run": "codex_remote_control",
            "/secure-mcp-runtime-recover": "secure_mcp_runtime_recovery",
            "/codex-global-issue-comment-routing-v1": "codex_global_issue_comment_routing",
            "/codex-auto-continuity-v7-goal prompt_comment=123": "codex_continuity_goal",
            "/codex-auto-continuity-v7\nlong prompt": "codex_continuity_launcher",
            "/amazon-support-chat-reply case_ids=22199842931": "amazon_support_breakglass",
        }
        for body, expected in cases.items():
            with self.subTest(body=body):
                self.assertEqual(expected, router.classify(payload(body)))


    def test_continuity_e2e_requires_and_extracts_explicit_binding(self):
        valid = payload("/continuity-e2e conversation_id=abcDEF_12345678")
        self.assertEqual("continuity_e2e", router.classify(valid))
        self.assertEqual("abcDEF_12345678", router.continuity_conversation_id(valid))
        for body in (
            "/continuity-e2e",
            "/continuity-e2e conversation_id=",
            "/continuity-e2e conversation_id=short",
            "/continuity-e2e conversation_id=bad/value",
            "/continuity-e2e conversation_id=abcDEF_12345678 extra=1",
        ):
            with self.subTest(body=body):
                current = payload(body)
                self.assertEqual("none", router.classify(current))
                self.assertEqual("", router.continuity_conversation_id(current))

    def test_slash_route_wins_over_claude_mention(self):
        body = "/remote target=fred-win action=health reason=@claude test"
        self.assertEqual("remote_access", router.classify(payload(body)))

    def test_claude_is_available_outside_control_issue(self):
        self.assertEqual("claude", router.classify(payload("@claude review this", issue=99)))

    def test_unauthorized_and_unrelated_comments_route_nowhere(self):
        self.assertEqual("none", router.classify(payload("/continuity-e2e", user="someone-else")))
        self.assertEqual("none", router.classify(payload("ordinary comment")))
        self.assertEqual("none", router.classify(payload("/continuity-e2e", issue=99)))


if __name__ == "__main__":
    unittest.main()
