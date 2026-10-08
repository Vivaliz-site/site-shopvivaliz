from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import agent_task_state as state

class BrowserSessionBindingTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.old = state.RUNTIME_DIR
        state.RUNTIME_DIR = Path(self.tmp.name)
        state.start_task('corporate-fixture', 'Recover the explicitly bound corporate conversation')
        state.bind_conversation('corporate-fixture', conversation_id='corporate-conversation')
    def tearDown(self):
        state.RUNTIME_DIR = self.old
        self.tmp.cleanup()
    def bind(self, session, task='corporate-fixture'):
        binder = getattr(state, 'bind_browser_session', None)
        self.assertTrue(callable(binder), 'durable account binding is required before browser routing')
        return binder(task, browser_session=session)
    def test_binding_is_persistent_without_manufacturing_progress(self):
        before = state.load_task('corporate-fixture')
        self.bind('atendimento')
        after = state.load_task('corporate-fixture')
        self.assertEqual(after['browser_session'], 'atendimento')
        self.assertEqual(after['updated_at'], before['updated_at'])
        self.assertEqual(after['history'][-1]['event'], 'browser_session_bound')
    def test_cli_accepts_dev_and_atendimento_and_rejects_new_fred_binding(self):
        parser = state._parser()
        for session in ('dev', 'atendimento'):
            args = parser.parse_args(['bind-browser-session', '--task', 'corporate-fixture', '--browser-session', session])
            self.assertEqual(args.browser_session, session)
        with self.assertRaises(SystemExit):
            parser.parse_args(['bind-browser-session', '--task', 'corporate-fixture', '--browser-session', 'fred'])

    def test_dev_binding_is_persistent_and_idempotent(self):
        before = state.load_task('corporate-fixture')
        first = self.bind('dev')
        self.assertEqual(first['browser_session'], 'dev')
        self.assertEqual(first['updated_at'], before['updated_at'])
        self.assertEqual(first, self.bind('dev'))

    def test_binding_is_idempotent(self):
        first = self.bind('atendimento')
        self.assertEqual(first, self.bind('atendimento'))
    def test_cross_account_rebinding_fails_closed(self):
        first = self.bind('atendimento')
        with self.assertRaises(state.TaskStateError): self.bind('fred')
        self.assertEqual(first, state.load_task('corporate-fixture'))
    def test_unknown_account_rejected(self):
        with self.assertRaises(state.TaskStateError): self.bind('external-account')
    def test_unbound_conversation_rejected(self):
        state.start_task('unbound', 'fixture')
        with self.assertRaises(state.TaskStateError): self.bind('atendimento', 'unbound')
    def test_successor_inherits_account_together_with_conversation(self):
        self.bind('atendimento')
        state.mark_ready('corporate-fixture', evidence=['isolated fixture only'], verification='fixture')
        state.complete_task('corporate-fixture')
        successor = state.start_successor_task('successor-fixture', predecessor_task_id='corporate-fixture', goal='fixture successor')
        self.assertEqual(successor['browser_session'], 'atendimento')
        self.assertEqual(successor['conversation_id'], 'corporate-conversation')
    def test_terminal_task_cannot_bind(self):
        state.mark_ready('corporate-fixture', evidence=['isolated fixture only'], verification='fixture')
        state.complete_task('corporate-fixture')
        with self.assertRaises(state.TaskStateError): self.bind('atendimento')
