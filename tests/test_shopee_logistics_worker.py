from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / 'scripts' / 'shopee_logistics_worker.py'


def load_module():
    spec = importlib.util.spec_from_file_location('shopee_logistics_worker', MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class ShopeeLogisticsWorkerTests(unittest.TestCase):
    def test_build_ship_request_empty_info_needed_defaults_to_dropoff(self):
        m = load_module()
        body = m.build_ship_request('ORDER1', 'PKG1', {'info_needed': {}})
        self.assertEqual(body, {'order_sn': 'ORDER1', 'package_number': 'PKG1', 'dropoff': {}})

    def test_build_ship_request_dropoff_without_fields_is_safe(self):
        m = load_module()
        body = m.build_ship_request('ORDER1', None, {'info_needed': {'dropoff': []}, 'dropoff': {}})
        self.assertEqual(body, {'order_sn': 'ORDER1', 'dropoff': {}})

    def test_build_ship_request_missing_sender_real_name_requires_manual_action(self):
        m = load_module()
        with self.assertRaisesRegex(m.ManualActionRequired, 'sender_real_name'):
            m.build_ship_request(
                'ORDER1',
                'PKG1',
                {'info_needed': {'dropoff': ['sender_real_name']}, 'dropoff': {}},
            )

    def test_build_ship_request_can_fill_sender_real_name_from_config(self):
        m = load_module()
        body = m.build_ship_request(
            'ORDER1',
            'PKG1',
            {'info_needed': {'dropoff': ['sender_real_name']}, 'dropoff': {}},
            sender_real_name='ShopVivaliz',
        )
        self.assertEqual(body['dropoff'], {'sender_real_name': 'ShopVivaliz'})

    def test_pickup_is_not_scheduled_without_explicit_runtime_opt_in(self):
        m = load_module()
        params = {
            'info_needed': {'pickup': ['address_id', 'pickup_time_id']},
            'pickup': {
                'address_list': [{'address_id': 123, 'address_flag': ['pickup_address']}],
                'time_slot_list': [{'pickup_time_id': 'slot-1'}],
            },
        }
        with self.assertRaisesRegex(m.ManualActionRequired, 'pickup'):
            m.build_ship_request('ORDER1', 'PKG1', params, allow_pickup=False)

    def test_turbo_and_standard_age_thresholds(self):
        m = load_module()
        self.assertEqual(m.minimum_age_seconds(90011, standard_delay=3600), 0)
        self.assertEqual(m.minimum_age_seconds(90012, standard_delay=3600), 0)
        self.assertEqual(m.minimum_age_seconds(90026, standard_delay=3600), 0)
        self.assertEqual(m.minimum_age_seconds(91003, standard_delay=3600), 3600)

    def test_label_alert_only_for_required_turbo_channels(self):
        m = load_module()
        self.assertTrue(m.requires_label_alert(90011))
        self.assertTrue(m.requires_label_alert(90012))
        self.assertFalse(m.requires_label_alert(90026))
        self.assertFalse(m.requires_label_alert(91003))


if __name__ == '__main__':
    unittest.main()

class _FakeSender:
    def __init__(self):
        self.sent = []
    def send(self, subject, body, attachment=None):
        self.sent.append((subject, body, attachment))
        return True


class _FakeClient:
    def __init__(self, package, create_time, *, turbo_label=False):
        self.package = package
        self.create_time = create_time
        self.turbo_label = turbo_label
        self.ship_calls = []
        self.document_calls = []
    def search_ready_packages(self, page_size=100):
        return [self.package]
    def get_order_details(self, order_sns, response_optional_fields='package_list,shipping_carrier'):
        return [{'order_sn': self.package['order_sn'], 'create_time': self.create_time}]
    def get_shipping_parameter(self, order_sn, package_number=None):
        return {'info_needed': {}}
    def ship_order(self, body):
        self.ship_calls.append(body)
        return {'error': ''}
    def search_packages(self, **kwargs):
        return []
    def get_tracking_number(self, order_sn, package_number=None):
        return 'TRACK123' if self.turbo_label else ''
    def get_shipping_document_parameter(self, order_sn, package_number=None):
        return {'suggest_shipping_document_type': 'THERMAL_AIR_WAYBILL'}
    def create_shipping_document(self, order_sn, package_number, tracking_number, shipping_document_type):
        self.document_calls.append(('create', order_sn, package_number, tracking_number, shipping_document_type))
        return {'error': ''}
    def get_shipping_document_result(self, order_sn, package_number=None):
        return {'status': 'READY'}
    def download_shipping_document(self, order_sn, package_number, shipping_document_type):
        self.document_calls.append(('download', order_sn, package_number, shipping_document_type))
        return b'%PDF-1.4\nTEST\n'


class ShopeeLogisticsWorkerCycleTests(unittest.TestCase):
    def test_apply_arranges_eligible_standard_package(self):
        import tempfile
        m = load_module()
        now = 100000
        package = {'order_sn': 'O1', 'package_number': 'P1', 'logistics_channel_id': 91003, 'is_shipment_arranged': False}
        client = _FakeClient(package, now - 7200)
        sender = _FakeSender()
        with tempfile.TemporaryDirectory() as tmp:
            summary = m.run(apply=True, now=now, client=client, shared_root=Path(tmp), sender=sender)
        self.assertEqual(summary['arranged'], 1)
        self.assertEqual(client.ship_calls, [{'order_sn': 'O1', 'package_number': 'P1', 'dropoff': {}}])

    def test_apply_turbo_arranges_and_prepares_label_immediately(self):
        import tempfile
        m = load_module()
        now = 100000
        package = {'order_sn': 'T1', 'package_number': 'TP1', 'logistics_channel_id': 90011, 'is_shipment_arranged': False}
        client = _FakeClient(package, now, turbo_label=True)
        sender = _FakeSender()
        with tempfile.TemporaryDirectory() as tmp:
            summary = m.run(apply=True, now=now, client=client, shared_root=Path(tmp), sender=sender)
            label = Path(tmp) / 'storage/shopee-logistics-worker/labels/TP1.pdf'
            self.assertTrue(label.is_file())
        self.assertEqual(summary['arranged'], 1)
        self.assertEqual(summary['label_ready_or_alerted'], 1)
        self.assertTrue(sender.sent)
        self.assertTrue(any(call[0] == 'create' for call in client.document_calls))
        self.assertTrue(any(call[0] == 'download' for call in client.document_calls))

class ShopeeLogisticsWorkerSafetyCycleTests(unittest.TestCase):
    def test_apply_skips_advance_fulfillment_order(self):
        import tempfile
        m = load_module()
        now = 100000
        package = {'order_sn': 'ADV1', 'package_number': 'AP1', 'logistics_channel_id': 91003, 'is_shipment_arranged': False}
        client = _FakeClient(package, now - 7200)
        client.get_order_details = lambda *args, **kwargs: [{'order_sn': 'ADV1', 'create_time': now - 7200, 'advance_package': True}]
        with tempfile.TemporaryDirectory() as tmp:
            summary = m.run(apply=True, now=now, client=client, shared_root=Path(tmp), sender=_FakeSender())
        self.assertEqual(summary['arranged'], 0)
        self.assertEqual(client.ship_calls, [])

    def test_apply_defers_package_with_pending_terms(self):
        import tempfile
        m = load_module()
        now = 100000
        package = {
            'order_sn': 'PEND1',
            'package_number': 'PP1',
            'logistics_channel_id': 91003,
            'is_shipment_arranged': False,
            'pending_terms': ['ARRANGE_SHIPMENT_PENDING'],
        }
        client = _FakeClient(package, now - 7200)
        with tempfile.TemporaryDirectory() as tmp:
            summary = m.run(apply=True, now=now, client=client, shared_root=Path(tmp), sender=_FakeSender())
        self.assertEqual(summary['arranged'], 0)
        self.assertEqual(client.ship_calls, [])

class ShopeeAlertSenderTests(unittest.TestCase):
    def test_brevo_configuration_is_sufficient_and_identity_is_fixed(self):
        import os
        from unittest.mock import patch
        m = load_module()
        env = {
            'BREVO_API_KEY': 'test-key',
            'EMAIL_FROM': 'Contabilidade Melo <wrong@example.com>',
            'EMAIL_TO': 'seller@example.com',
            'EMAIL_SMTP_HOST': 'smtp.gmail.com',
            'EMAIL_USER': 'legacy@example.com',
            'EMAIL_PASSWORD': 'legacy-pass',
        }
        with patch.dict(os.environ, env, clear=True):
            sender = m.AlertSender()
        self.assertTrue(sender.configured)
        self.assertTrue(sender.brevo_configured)
        self.assertEqual(sender.FROM_EMAIL, 'atendimento@shopvivaliz.com.br')
        self.assertEqual(sender.FROM_NAME, 'ShopVivaliz')
        self.assertEqual(sender.REPLY_TO_EMAIL, 'atendimento@shopvivaliz.com.br')
        self.assertFalse(hasattr(sender, 'smtp_configured'))

    def test_send_uses_brevo_only(self):
        import os
        from unittest.mock import patch
        m = load_module()
        env = {
            'BREVO_API_KEY': 'test-key',
            'EMAIL_TO': 'seller@example.com',
        }
        with patch.dict(os.environ, env, clear=True):
            sender = m.AlertSender()
        with patch.object(sender, '_send_brevo', return_value=True) as brevo:
            self.assertTrue(sender.send('subject', 'body'))
        brevo.assert_called_once()

    def test_brevo_payload_has_fixed_identity_and_pdf_attachment(self):
        import base64
        import json
        import os
        import tempfile
        from unittest.mock import patch
        m = load_module()
        env = {
            'BREVO_API_KEY': 'test-key',
            'EMAIL_FROM': 'Contabilidade Melo <wrong@example.com>',
            'EMAIL_TO': 'seller@example.com',
        }
        captured = {}
        class FakeResponse:
            status = 201
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self): return b'{"messageId":"test"}'
        def fake_urlopen(request, timeout=0):
            captured['payload'] = json.loads(request.data.decode('utf-8'))
            return FakeResponse()
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, env, clear=True), patch('urllib.request.urlopen', fake_urlopen):
            pdf = Path(tmp) / 'label.pdf'
            pdf.write_bytes(b'%PDF-test')
            sender = m.AlertSender()
            self.assertTrue(sender._send_brevo('subject', 'body', pdf))
        payload = captured['payload']
        self.assertEqual(payload['sender'], {'email': 'atendimento@shopvivaliz.com.br', 'name': 'ShopVivaliz'})
        self.assertEqual(payload['replyTo']['email'], 'atendimento@shopvivaliz.com.br')
        self.assertEqual(payload['attachment'][0]['name'], 'label.pdf')
        self.assertEqual(base64.b64decode(payload['attachment'][0]['content']), b'%PDF-test')


if __name__ == '__main__':
    unittest.main()

class ShopeeUnsplitRetryTests(unittest.TestCase):
    def test_ship_order_retries_without_package_number_only_for_unsplit_error(self):
        m = load_module()
        calls = []
        class Client:
            def ship_order(self, body):
                calls.append(dict(body))
                if len(calls) == 1:
                    raise RuntimeError("Shopee API error logistics.ship_order_not_need_pacakge_number: Please don't request with package_number for this unsplit order.")
                return {'error': ''}
        m.ship_order_with_unsplit_retry(Client(), {'order_sn': 'O1', 'package_number': 'P1', 'dropoff': {}})
        self.assertEqual(calls, [
            {'order_sn': 'O1', 'package_number': 'P1', 'dropoff': {}},
            {'order_sn': 'O1', 'dropoff': {}},
        ])

    def test_ship_order_does_not_retry_unrelated_error(self):
        m = load_module()
        calls = []
        class Client:
            def ship_order(self, body):
                calls.append(dict(body))
                raise RuntimeError('Shopee API error logistics.some_other_error: nope')
        with self.assertRaisesRegex(RuntimeError, 'some_other_error'):
            m.ship_order_with_unsplit_retry(Client(), {'order_sn': 'O1', 'package_number': 'P1', 'dropoff': {}})
        self.assertEqual(len(calls), 1)

class ShopeeInvoicePendingRegressionTests(unittest.TestCase):
    def test_invoice_validation_error_is_deferred_not_worker_error(self):
        import tempfile
        m = load_module()
        now = 100000
        package = {'order_sn': 'NF1', 'package_number': 'PKG1', 'logistics_channel_id': 91003, 'is_shipment_arranged': False}
        client = _FakeClient(package, now - 7200)
        def fail_ship(body):
            raise RuntimeError('Shopee API error logistics.lack_of_invoice_data: invalid by SEFAZ')
        client.ship_order = fail_ship
        with tempfile.TemporaryDirectory() as tmp:
            summary = m.run(apply=True, now=now, client=client, shared_root=Path(tmp), sender=_FakeSender())
        self.assertEqual(summary['errors'], 0)
        self.assertEqual(summary['deferred_by_shopee'], 1)
        self.assertEqual(summary['arranged'], 0)
