from pathlib import Path
import re
ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / '.github' / 'workflows'
SCRIPTS = ROOT / 'scripts'
WINDOWS_HOST = re.compile(r'(fred[-_ ]?win|kocepsv|LAPTOP-NIG4IFUU|DESKTOP-KOCEPSV)', re.I)
BROWSER_WORD = re.compile(r'(browser|chrome|chromium|msedge|opera|playwright|selenium|cdp|oauth|captcha|mfa)', re.I)
DANGEROUS = [
    re.compile(r'select-active-products-browser-relay\.sh', re.I),
    re.compile(r'SV_BROWSER_RELAY_PORT', re.I),
    re.compile(r'Start-Process[^\n]*https?://', re.I),
    re.compile(r'Get-Process[^\n]*(?:opera|chrome|msedge)', re.I),
    re.compile(r'C:\\\\Program Files[^\n]*(?:Chrome|Edge|Opera)', re.I),
    re.compile(r'LOCALAPPDATA[^\n]*(?:Chrome|Opera)', re.I),
    re.compile(r'remote-debugging-port', re.I),
    re.compile(r'fred-win-(?:seller-central|safe-t-status)', re.I),
]
RETIRED = 'WINDOWS_BROWSER_RETIRED_V1'
ALLOWED_CLEANUP = {'scripts/fredwin-runtime-janitor.ps1'}
offenders = []
for p in sorted(WORKFLOWS.glob('*.y*ml')):
    text = p.read_text(encoding='utf-8', errors='ignore')
    if not (WINDOWS_HOST.search(text) or BROWSER_WORD.search(text)): continue
    for pattern in DANGEROUS:
        if pattern.search(text):
            offenders.append(f'{p.relative_to(ROOT)}:{pattern.pattern}')
            break
for p in sorted(WORKFLOWS.glob('*.disabled')):
    text = p.read_text(encoding='utf-8', errors='ignore')
    if WINDOWS_HOST.search(text) and BROWSER_WORD.search(text) and RETIRED not in text:
        offenders.append(f'{p.relative_to(ROOT)}:disabled workflow lacks retired marker')
for p in sorted(SCRIPTS.rglob('*')):
    if not p.is_file(): continue
    rel = str(p.relative_to(ROOT)).replace('\\', '/')
    if rel in ALLOWED_CLEANUP or p.suffix.lower() not in {'.ps1','.py','.js','.mjs','.cjs','.sh'}: continue
    text = p.read_text(encoding='utf-8', errors='ignore')
    windows_scoped = p.suffix.lower() == '.ps1' or WINDOWS_HOST.search(text) or 'fredwin' in p.name.lower() or 'kocepsv' in p.name.lower()
    if not windows_scoped: continue
    for pattern in DANGEROUS:
        if pattern.search(text) and RETIRED not in text:
            offenders.append(f'{rel}:{pattern.pattern}')
            break
required_vm_files = [
    '.github/workflows/active-products-browser-smoke.yml',
    '.github/workflows/backend-vm-admin-mobile-readonly-smoke.yml',
    '.github/workflows/image-run-browser-smoke.yml',
    '.github/workflows/backend-vm-browser-capability.yml',
    '.github/workflows/google-ads-backend-vm-open-oauth.yml',
]
for rel in required_vm_files:
    text = (ROOT / rel).read_text(encoding='utf-8')
    if '10.0.1.38' not in text and '127.0.0.1:17777' not in text and 'run-browser-smoke-on-backend-vm.sh' not in text:
        offenders.append(f'{rel}:missing canonical backend browser route')
    if '5557' in text or '5558' in text:
        offenders.append(f'{rel}:legacy Windows relay port')
remote=(ROOT/'.github/workflows/fred-win-remote-action.yml').read_text(encoding='utf-8')
for action in ('open_shopvivaliz','open_exchange_admin','open_microsoft_365','open_email_login_pair'):
    block=re.search(rf'{action}\)\s*(.*?);;',remote,re.S)
    if not block or 'WINDOWS_BROWSER_FORBIDDEN' not in block.group(1):
        offenders.append(f'.github/workflows/fred-win-remote-action.yml:{action} not fail-closed')
if offenders:
    raise SystemExit('Windows browser policy violations:\n'+'\n'.join(sorted(set(offenders))))
print('WINDOWS_BROWSER_POLICY_REGRESSION=PASS')
