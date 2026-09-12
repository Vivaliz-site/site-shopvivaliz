import importlib.util
import pathlib
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('merchant_returns', ROOT / 'scripts/google_merchant_sync_returns.py')
MOD = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MOD)


class MerchantReturnsV1Test(unittest.TestCase):
    def test_uses_supported_v1_api_and_global_return_fields(self):
        self.assertEqual('https://merchantapi.googleapis.com/accounts/v1', MOD.API_BASE)
        policy = MOD.desired_policy()
        self.assertEqual({'type': 'NUMBER_OF_DAYS_AFTER_DELIVERY', 'days': '7'}, policy['policy'])
        self.assertEqual('FIXED', policy['returnShippingFee']['type'])
        self.assertEqual('0', policy['returnShippingFee']['fixedFee']['amountMicros'])
        self.assertEqual('BRL', policy['returnShippingFee']['fixedFee']['currencyCode'])
        self.assertEqual('DOWNLOAD_AND_PRINT', policy['returnLabelSource'])
        self.assertNotIn('returnReasonCategoryInfo', policy)
        self.assertNotIn('label', policy)

    def test_unlabelled_br_policy_is_the_default_policy(self):
        policy = {'countries': ['BR'], 'returnPolicyId': '9261539376'}
        self.assertTrue(MOD.is_default_policy(policy, 'BR'))
        self.assertTrue(MOD.is_default_policy({'label': 'default', 'countries': ['BR']}, 'BR'))
        self.assertFalse(MOD.is_default_policy({'label': 'promo', 'countries': ['BR']}, 'BR'))

    def test_replaces_existing_policy_with_delete_create_and_readback(self):
        old = {
            'name': 'accounts/5381803710/onlineReturnPolicies/9261539376',
            'returnPolicyId': '9261539376',
            'countries': ['BR'],
            'policy': {},
            'returnMethods': ['BY_MAIL'],
            'itemConditions': ['NEW'],
            'returnShippingFee': {'type': 'CUSTOMER_PAYING_ACTUAL_FEE'},
            'returnPolicyUri': MOD.POLICY_URL,
            'returnLabelSource': 'CUSTOMER_RESPONSIBILITY',
            'acceptExchange': True,
        }
        desired = MOD.desired_policy()
        created = dict(desired, name='accounts/5381803710/onlineReturnPolicies/999')
        calls = []
        get_count = 0

        def fake_request(access, method, url, payload=None):
            nonlocal get_count
            calls.append((method, url, payload))
            if method == 'GET':
                get_count += 1
                return {'onlineReturnPolicies': [old if get_count == 1 else created]}
            if method == 'DELETE':
                return {}
            if method == 'POST':
                return created
            raise AssertionError(method)

        with mock.patch.object(MOD, 'token', return_value='access'), mock.patch.object(MOD, 'request_json', side_effect=fake_request):
            self.assertEqual(0, MOD.main())

        methods = [method for method, _, _ in calls]
        self.assertEqual(['GET', 'DELETE', 'POST', 'GET'], methods)
        self.assertTrue(calls[1][1].startswith('https://merchantapi.googleapis.com/accounts/v1/'))
        self.assertEqual(desired, calls[2][2])

    def test_rolls_back_previous_policy_if_create_fails(self):
        old = {
            'name': 'accounts/5381803710/onlineReturnPolicies/9261539376',
            'returnPolicyId': '9261539376',
            'countries': ['BR'],
            'policy': {'type': 'NO_RETURNS'},
            'returnMethods': [],
            'itemConditions': [],
            'returnPolicyUri': MOD.POLICY_URL,
        }
        posts = []

        def fake_request(access, method, url, payload=None):
            if method == 'GET':
                return {'onlineReturnPolicies': [old]}
            if method == 'DELETE':
                return {}
            if method == 'POST':
                posts.append(payload)
                if len(posts) == 1:
                    raise RuntimeError('create failed')
                return dict(payload, name='accounts/5381803710/onlineReturnPolicies/restored')
            raise AssertionError(method)

        with mock.patch.object(MOD, 'token', return_value='access'), mock.patch.object(MOD, 'request_json', side_effect=fake_request):
            with self.assertRaises(RuntimeError):
                MOD.main()

        self.assertEqual(2, len(posts))
        self.assertEqual({'countries': ['BR'], 'policy': {'type': 'NO_RETURNS'}, 'returnMethods': [], 'itemConditions': [], 'returnPolicyUri': MOD.POLICY_URL}, posts[1])


if __name__ == '__main__':
    unittest.main()
