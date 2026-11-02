from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / ".github/workflows/mandatory-validation-gate.yml"
POLICY = ROOT / "scripts/check_pr_completion_policy.py"


def assert_owner_review_enforced(text: str):
    checkout = "      - name: Checkout trusted owner-review policy from protected base"
    step = "      - name: Enforce human owner APPROVED review on exact protected PR HEAD"
    next_step = "      - name: Select fail-closed impact scope"
    for token in (checkout, step, next_step):
        if token not in text:
            raise AssertionError("owner_review_required_step_missing")
    a, b, c = text.index(checkout), text.index(step), text.index(next_step)
    if not a < b < c:
        raise AssertionError("owner_review_wrong_order")
    trusted, gate = text[a:b], text[b:c]
    for token in (
        "uses: actions/checkout@v7",
        "ref: ${{ github.event.pull_request.base.sha }}",
        "persist-credentials: false",
        "path: trusted-owner-review",
    ):
        if token not in trusted:
            raise AssertionError("base_checkout_not_pinned")
    for token in (
        "if: github.event_name == 'pull_request' || github.event_name == 'pull_request_review'",
        "GITHUB_TOKEN: ${{ github.token }}",
        "trusted-owner-review/scripts/quality/verify_owner_protected_pr_review.py",
        '--pr "$PR_NUMBER" --head "$HEAD_SHA"',
        "set -Eeuo pipefail",
        "OWNER_REVIEW_GATE=DENIED",
    ):
        if token not in gate:
            raise AssertionError("mandatory_owner_review_gate_disabled")
    if any(x in gate for x in ("continue-on-error: true", "|| true", "exit 0")):
        raise AssertionError("mandatory_owner_review_gate_bypass")


class RequiredOwnerApprovalTests(unittest.TestCase):
    def test_workflow_requires_trusted_base_and_exact_review(self):
        assert_owner_review_enforced(WF.read_text())

    def test_removed_step_fails(self):
        raw = WF.read_text()
        with self.assertRaises(AssertionError):
            assert_owner_review_enforced(raw.replace(
                "      - name: Enforce human owner APPROVED review on exact protected PR HEAD",
                "      - name: Disable human approval",
            ))

    def test_ignore_errors_fails(self):
        raw = WF.read_text()
        with self.assertRaises(AssertionError):
            assert_owner_review_enforced(raw.replace(
                "          set -Eeuo pipefail\n          test -f trusted-owner-review/",
                "          set -Eeuo pipefail\n          exit 0\n          test -f trusted-owner-review/",
            ))

    def test_using_untrusted_pr_head_fails(self):
        raw = WF.read_text()
        with self.assertRaises(AssertionError):
            assert_owner_review_enforced(raw.replace(
                "ref: ${{ github.event.pull_request.base.sha }}",
                "ref: ${{ github.event.pull_request.head.sha }}",
            ))

    def test_independent_commercial_approval_gate_is_preserved(self):
        text = WF.read_text()
        self.assertIn("Enforce commercial approval in required gate", text)
        self.assertIn("python3 scripts/enforce-autonomy-policy.py", text)
        self.assertLess(
            text.index("Enforce human owner APPROVED review on exact protected PR HEAD"),
            text.index("Enforce commercial approval in required gate"),
        )

    def test_policy_engine_requires_gate_marker(self):
        policy = POLICY.read_text()
        self.assertIn('".github/workflows/mandatory-validation-gate.yml"', policy)
        self.assertIn("trusted-owner-review/scripts/quality/verify_owner_protected_pr_review.py", policy)


if __name__ == "__main__":
    unittest.main()
