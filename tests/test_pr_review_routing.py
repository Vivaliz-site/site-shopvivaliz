import importlib.util
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts' / 'check_pr_review_routing.py'
spec = importlib.util.spec_from_file_location('pr_review_routing', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RoutingPolicyTests(unittest.TestCase):
    def scan(self, *, owners, workflow=None):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            (root / '.github').mkdir()
            (root / '.github/CODEOWNERS').write_text(owners, encoding='utf-8')
            if workflow is not None:
                (root / '.github/workflows').mkdir()
                (root / '.github/workflows/reviewer.yml').write_text(workflow, encoding='utf-8')
            return module.violations(root)

    def test_common_changes_not_owned_by_human(self):
        self.assertFalse(self.scan(owners='/security/owner-approvals/ @shopvivaliz-human\n'))

    def test_explicit_security_validators_may_be_owned(self):
        owners = '/security/owner-approvals/ @shopvivaliz-human\n/scripts/validate-owner-pr-grant.py @shopvivaliz-human\n'
        self.assertFalse(self.scan(owners=owners))

    def test_generic_wildcard_is_rejected(self):
        self.assertTrue(self.scan(owners='* @shopvivaliz-human\n/security/owner-approvals/ @shopvivaliz-human\n'))

    def test_implicit_broad_folder_is_rejected(self):
        self.assertTrue(self.scan(owners='/docs/ @shopvivaliz-human\n/security/owner-approvals/ @shopvivaliz-human\n'))

    def test_workflow_automatic_human_review_is_rejected(self):
        self.assertTrue(self.scan(owners='/security/owner-approvals/ @shopvivaliz-human\n', workflow='reviewers: shopvivaliz-human\n'))

    def test_agent_code_owner_is_not_restricted(self):
        self.assertFalse(self.scan(owners='* @fredmourao-ai\n/security/owner-approvals/ @shopvivaliz-human\n'))

    def test_missing_security_coverage_is_rejected(self):
        self.assertTrue(self.scan(owners='# just a comment\n'))


    def test_single_owner_profile_does_not_waive_protected_approval(self):
        root = SCRIPT.parents[1]
        report = root / 'docs/ops/single-owner-governance-audit-2026-11-02.md'
        self.assertTrue(report.is_file())
        for relative in ('AGENTS.md', 'docs/knowledge/agent-rules.md'):
            policy = (root / relative).read_text(encoding='utf-8')
            for invariant in (
                '<!-- SOLO_OWNER_PROPORTIONAL_GOVERNANCE_V1 -->',
                'P0 (somente leitura/preparacao)',
                'P1 (mudanca tecnica reversivel',
                'P2 (novo poder/efeito protegido)',
                'shopvivaliz-human',
                'sem aprovacoes ou preflights duplicados',
                'NoNewPrivileges=yes',
                'fail-closed',
            ):
                with self.subTest(path=relative, invariant=invariant):
                    self.assertIn(invariant, policy)
        # Policy for a sole human owner must never remove grant-only CODEOWNERS.
        owners = (root / '.github/CODEOWNERS').read_text(encoding='utf-8')
        self.assertIn('/security/owner-approvals/ @shopvivaliz-human', owners)



if __name__ == '__main__':
    unittest.main()
