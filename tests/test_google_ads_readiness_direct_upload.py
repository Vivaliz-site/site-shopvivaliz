import importlib.util
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('readiness', root / 'scripts' / 'google_ads_real_readiness.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

env = {
    'GOOGLE_ADS_CONVERSION_SOURCE': 'DIRECT_UPLOAD',
    'GOOGLE_ADS_PURCHASE_CONVERSION_ACTION_ID': '7825344138',
}
missing, errors = mod.conversion_tracking_errors(env)
assert missing == [], missing
assert errors == [], errors

missing, errors = mod.conversion_tracking_errors({'GOOGLE_ADS_CONVERSION_SOURCE': 'DIRECT_UPLOAD'})
assert 'GOOGLE_ADS_PURCHASE_CONVERSION_ACTION_ID' in missing
assert 'ga4_purchase_server_side_secret_missing' not in errors
print('PASS: direct Google Ads upload is a first-class readiness source without GA4 secret.')
