import importlib.util
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('update_env', root / 'scripts' / 'update-production-env.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

with tempfile.TemporaryDirectory() as td:
    env_path = Path(td) / '.env'
    env_path.write_text('KEEP=1\n', encoding='utf-8')
    changed = mod.merge_env(env_path, {
        'GOOGLE_ADS_PURCHASE_CONVERSION_ACTION_ID': '7825344138',
        'GOOGLE_ADS_CONVERSION_SOURCE': 'DIRECT_UPLOAD',
    })
    text = env_path.read_text(encoding='utf-8')
    assert changed == ['GOOGLE_ADS_CONVERSION_SOURCE', 'GOOGLE_ADS_PURCHASE_CONVERSION_ACTION_ID']
    assert 'KEEP=1' in text
    assert 'GOOGLE_ADS_PURCHASE_CONVERSION_ACTION_ID=7825344138' in text
    assert 'GOOGLE_ADS_CONVERSION_SOURCE=DIRECT_UPLOAD' in text
print('PASS: static Google Ads conversion action id is accepted atomically.')
