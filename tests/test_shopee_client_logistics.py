from __future__ import annotations

import unittest
from scripts.utils.shopee_client import ShopeeClient


class ShopeeClientLogisticsTests(unittest.TestCase):
    def test_search_packages_uses_nested_current_contract(self):
        client = object.__new__(ShopeeClient)
        calls = []

        def fake_post(path, body, extra_params=None):
            calls.append((path, body))
            return {
                'response': {
                    'packages_list': [{'order_sn': 'O1', 'package_number': 'P1'}],
                    'pagination': {'more': False, 'next_cursor': ''},
                }
            }

        client._post = fake_post
        rows = client.search_packages(
            package_status=2,
            fulfillment_type=2,
            invoice_pending=False,
            logistics_channel_ids=[90011, 90012],
            page_size=20,
        )
        self.assertEqual(rows[0]['package_number'], 'P1')
        path, body = calls[0]
        self.assertEqual(path, '/order/search_package_list')
        self.assertEqual(body['pagination']['page_size'], 20)
        self.assertEqual(body['filter']['package_status'], 2)
        self.assertEqual(body['filter']['fulfillment_type'], 2)
        self.assertFalse(body['filter']['invoice_pending'])
        self.assertEqual(body['filter']['logistics_channel_ids'], [90011, 90012])

    def test_ship_order_rejects_zero_or_multiple_methods(self):
        client = object.__new__(ShopeeClient)
        with self.assertRaisesRegex(ValueError, 'exactly one'):
            client.ship_order({'order_sn': 'O1'})
        with self.assertRaisesRegex(ValueError, 'exactly one'):
            client.ship_order({'order_sn': 'O1', 'dropoff': {}, 'pickup': {}})

    def test_ship_order_posts_exact_body(self):
        client = object.__new__(ShopeeClient)
        calls = []
        client._post = lambda path, body, extra_params=None: calls.append((path, body)) or {'error': ''}
        body = {'order_sn': 'O1', 'package_number': 'P1', 'dropoff': {}}
        client.ship_order(body)
        self.assertEqual(calls, [('/logistics/ship_order', body)])

    def test_document_parameter_uses_package_number_when_present(self):
        client = object.__new__(ShopeeClient)
        calls = []
        def fake_post(path, body, extra_params=None):
            calls.append((path, body))
            return {'response': {'result_list': [{'order_sn': 'O1', 'package_number': 'P1', 'suggest_shipping_document_type': 'THERMAL_AIR_WAYBILL'}]}}
        client._post = fake_post
        row = client.get_shipping_document_parameter('O1', 'P1')
        self.assertEqual(row['suggest_shipping_document_type'], 'THERMAL_AIR_WAYBILL')
        self.assertEqual(calls[0][1], {'order_list': [{'order_sn': 'O1', 'package_number': 'P1'}]})


if __name__ == '__main__':
    unittest.main()
