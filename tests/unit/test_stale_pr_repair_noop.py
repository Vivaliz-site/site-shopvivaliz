from pathlib import Path
import unittest
import yaml

WORKFLOW = Path('.github/workflows/ai-stale-pr-repair.yml')


class StalePrRepairNoopTest(unittest.TestCase):
    def test_closed_pr_is_noop_and_all_mutating_steps_require_eligibility(self):
        text = WORKFLOW.read_text(encoding='utf-8')
        self.assertIn("if (data.state !== 'open')", text)
        self.assertIn("core.setOutput('eligible', 'false')", text)
        self.assertIn("core.setOutput('eligible', 'true')", text)
        self.assertIn('repair target is already closed; skipping', text)
        doc = yaml.safe_load(text)
        steps = doc['jobs']['repair']['steps']
        for step in steps[1:]:
            condition = str(step.get('if', ''))
            self.assertIn("steps.meta.outputs.eligible == 'true'", condition, step.get('name'))

    def test_protected_conflict_is_persisted_without_ai_writeback(self):
        text = WORKFLOW.read_text(encoding='utf-8')
        self.assertIn('issues: write', text)
        self.assertIn('pull-requests: write', text)
        self.assertNotIn('pull-requests: read', text)
        self.assertIn('Record protected conflict block', text)
        self.assertIn("steps.merge.outputs.safe == 'false'", text)
        self.assertIn('stale-pr-protected-conflict:', text)
        self.assertIn('process.env.HEAD_SHA', text)
        self.assertNotIn('cat /tmp/ai-protected.txt >&2\n              false', text)
        doc = yaml.safe_load(text)
        steps = {step.get('name'): step for step in doc['jobs']['repair']['steps']}
        for name in (
            'Validate repaired tree',
            'Build verified Git Data manifest',
            'Publish repaired merge commit through GitHub Git Data API',
        ):
            condition = str(steps[name].get('if', ''))
            self.assertIn("steps.merge.outputs.safe == 'true'", condition, name)


if __name__ == '__main__':
    unittest.main()
