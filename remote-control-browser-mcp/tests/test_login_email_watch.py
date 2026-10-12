#!/usr/bin/env python3
"""Bounded, credential-safe Gmail metadata watch regression tests."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from urllib.parse import parse_qs, urlsplit
from unittest import mock

MODULE = Path(__file__).resolve().parents[1] / 'login_email_watch.py'
spec = importlib.util.spec_from_file_location('login_email_watch', MODULE)
assert spec and spec.loader
watch = importlib.util.module_from_spec(spec)
spec.loader.exec_module(watch)


def message(message_id='abc123', *, timestamp=1010, sender='OpenAI <noreply@tm.openai.com>',
            recipient='dev@shopvivaliz.com.br', subject='Your temporary ChatGPT login code'):
    return {'id': message_id, 'internalDate': str(timestamp * 1000), 'payload': {'headers': [
        {'name': 'From', 'value': sender}, {'name': 'To', 'value': recipient},
        {'name': 'Subject', 'value': subject},
    ]}, 'body': {'data':'DO_NOT_READ_ME'}, 'snippet':'DO_NOT_READ_ME'}


class FakeClock:
    def __init__(self, now=0): self.now = now; self.sleeps=[]
    def monotonic(self): return self.now
    def sleep(self, seconds): self.sleeps.append(seconds); self.now += seconds


class EmailLoginWatchTests(unittest.TestCase):
    def test_exact_sender_recipient_subject_and_time(self):
        good = message()
        self.assertEqual('abc123', watch.match_metadata(good, 'dev', 1000))
        self.assertIsNone(watch.match_metadata(good, 'atendimento', 1000))
        self.assertIsNone(watch.match_metadata(message(timestamp=999), 'dev', 1000))
        self.assertIsNone(watch.match_metadata(message(sender='attacker@tm.openai.com'), 'dev', 1000))
        self.assertIsNone(watch.match_metadata(message(recipient='atendimento@shopvivaliz.com.br'), 'dev', 1000))
        self.assertIsNone(watch.match_metadata(message(subject='Your Bank temporary login code'), 'dev', 1000))

    def test_watch_checks_until_first_new_message_and_finishes(self):
        clock=FakeClock()
        polls=[]; events=[]
        def fetch(session, since):
            polls.append((session, since))
            if len(polls)==3: return [message(timestamp=1040)]
            return [message(timestamp=999)]
        result=watch.watch_window('dev', start_epoch=1000, fetch=fetch,
                                  monotonic=clock.monotonic, sleep=clock.sleep,
                                  report=events.append)
        self.assertEqual('matched', result['status'])
        self.assertEqual(3,len(polls))
        self.assertEqual([15,15],clock.sleeps)
        self.assertEqual(1,len([e for e in events if e['status']=='matched']))
        self.assertNotIn('body',str(result)); self.assertNotIn('snippet',str(result))

    def test_watch_times_out_by_ten_minutes_and_never_sleeps_beyond(self):
        clock=FakeClock(); attempts=[]
        result=watch.watch_window('atendimento',start_epoch=500,fetch=lambda a,t:(attempts.append(a) or []),
                                  monotonic=clock.monotonic,sleep=clock.sleep)
        self.assertEqual('timeout',result['status'])
        self.assertEqual(600,clock.now)
        self.assertEqual(40,len(attempts))
        self.assertLessEqual(max(clock.sleeps),15)

    def test_only_metadata_endpoints_with_header_projection(self):
        class FakeHTTP:
            def __init__(self): self.urls=[]
            def __call__(self,url,headers,body=None):
                self.urls.append(url)
                if '/profile' in url: return {'emailAddress':'fredmourao@gmail.com'}
                if '/messages?' in url: return {'messages':[{'id':'abc123'}]}
                if '/messages/abc123?' in url: return message()
                raise AssertionError(url)
        client=FakeHTTP()
        api=watch.GmailMetadataReader('SAFE_TEST_ACCESS_TOKEN',request_json=client)
        self.assertEqual('fredmourao@gmail.com',api.verify_mailbox())
        messages=api.fetch('dev',start_epoch=1000)
        self.assertEqual(1,len(messages))
        for url in client.urls:
            self.assertNotIn('format=full',url)
            self.assertNotIn('body',url)
        detail=urlsplit(client.urls[-1]); qs=parse_qs(detail.query)
        self.assertEqual(['metadata'],qs.get('format'))
        self.assertIn('metadataHeaders',qs)
        self.assertIn('fields',qs)
        self.assertNotIn('snippet',str(messages))
        self.assertNotIn('body',str(messages))

    def test_credentials_require_independent_readonly_grant_and_private_file(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'oauth.json'
            self.assertEqual('oauth_not_provisioned',watch.credential_readiness(p)['status'])
            p.write_text('{"mailbox":"fredmourao@gmail.com","scope":"https://www.googleapis.com/auth/gmail.readonly","client_id":"TEST","client_secret":"TEST","refresh_token":"TEST"}')
            os.chmod(p,0o644)
            self.assertEqual('credentials_permissions_invalid',watch.credential_readiness(p)['status'])
            os.chmod(p,0o600)
            self.assertEqual('ready',watch.credential_readiness(p)['status'])
            p.write_text('{"mailbox":"other@gmail.com","scope":"https://www.googleapis.com/auth/gmail.readonly","client_id":"TEST","client_secret":"TEST","refresh_token":"TEST"}')
            self.assertEqual('mailbox_mismatch',watch.credential_readiness(p)['status'])
            p.write_text('{"mailbox":"fredmourao@gmail.com","scope":"https://www.googleapis.com/auth/gmail.send","client_id":"TEST","client_secret":"TEST","refresh_token":"TEST"}')
            self.assertEqual('readonly_scope_required',watch.credential_readiness(p)['status'])

    def test_status_reports_only_nonsecret_fields_from_private_event(self):
        with tempfile.TemporaryDirectory() as d:
            directory=Path(d)
            watch._write_private_event({"status":"matched", "session":"dev", "message_id":"abc123",
                                        "received_at":1020, "started_at":1000, "deadline_at":1600,
                                        "otp":"NEVER_PERSIST","body":"NEVER_PERSIST"},directory=directory)
            self.assertNotIn("NEVER_PERSIST",(directory/"dev.json").read_text())
            status=watch.read_public_status("dev", directory=directory)
            self.assertEqual("matched",status["status"])
            self.assertNotIn("message_id",status)
            self.assertNotIn("otp",status)
            self.assertIsNone(watch.read_public_status("atendimento",directory=directory))

    def test_launch_fails_closed_without_credentials(self):
        with tempfile.TemporaryDirectory() as d:
            with mock.patch.object(watch,'OAUTH_FILE',Path(d)/'missing-oauth.json'):
                with mock.patch.object(watch.subprocess,'Popen') as process:
                    self.assertEqual({'status':'oauth_not_provisioned'},watch.launch_watch('dev'))
                    process.assert_not_called()

if __name__=='__main__': unittest.main()
