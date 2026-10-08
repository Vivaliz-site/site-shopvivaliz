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
    def test_atomic_start_binds_exact_dev_conversation(self):
        created = state.start_task(
            'atomic-dev-fixture',
            'resume this exact Dev conversation',
            conversation_id='dev-unique-conversation-123',
            browser_session='dev',
        )
        self.assertEqual(created['conversation_id'], 'dev-unique-conversation-123')
        self.assertEqual(created['browser_session'], 'dev')
        self.assertEqual(created['history'][0]['event'], 'started')
        self.assertEqual(created, state.load_task('atomic-dev-fixture'))
        self.assertEqual(
            created,
            state.start_task(
                'atomic-dev-fixture',
                'resume this exact Dev conversation',
                conversation_id='dev-unique-conversation-123',
                browser_session='dev',
            ),
        )

    def test_atomic_start_rejects_partial_or_mismatched_binding(self):
        with self.assertRaises(state.TaskStateError):
            state.start_task('partial-binding', 'fixture', conversation_id='valid-conversation-123')
        self.assertFalse((state.RUNTIME_DIR / 'partial-binding.json').exists())
        with self.assertRaises(state.TaskStateError):
            state.start_task('wrong-account', 'fixture', conversation_id='valid-conversation-123', browser_session='fred')
        self.assertFalse((state.RUNTIME_DIR / 'wrong-account.json').exists())
        with self.assertRaises(state.TaskStateError):
            state.start_task('corporate-fixture', 'Recover the explicitly bound corporate conversation', conversation_id='some-other-conversation', browser_session='dev')

    def test_cli_supports_atomic_dev_binding(self):
        parsed = state._parser().parse_args([
            'start', '--task', 'new-dev', '--goal', 'resume exact conversation',
            '--conversation-id', 'conversation-12345678', '--browser-session', 'dev',
        ])
        self.assertEqual(parsed.conversation_id, 'conversation-12345678')
        self.assertEqual(parsed.browser_session, 'dev')
        parsed_existing = state._parser().parse_args([
            'bind-browser-session', '--task', 'corporate-fixture', '--browser-session', 'dev',
        ])
        self.assertEqual(parsed_existing.browser_session, 'dev')

    def test_binding_is_persistent_without_manufacturing_progress(self):
        before = state.load_task('corporate-fixture')
        self.bind('atendimento')
        after = state.load_task('corporate-fixture')
        self.assertEqual(after['browser_session'], 'atendimento')
        self.assertEqual(after['updated_at'], before['updated_at'])
        self.assertEqual(after['history'][-1]['event'], 'browser_session_bound')
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
