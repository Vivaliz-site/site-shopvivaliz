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


if __name__ == '__main__':
    unittest.main()
