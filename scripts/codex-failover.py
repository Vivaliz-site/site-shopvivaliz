#!/usr/bin/env python3
import json
import os
import pathlib
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

KEY_FILE = pathlib.Path.home() / '.codex' / 'api-keys.env'
STATE_FILE = pathlib.Path.home() / '.codex' / 'api-key-active'
DEFAULT_MODEL = 'gpt-5.1'


def parse_keys(path=KEY_FILE):
    values = {}
    for raw in pathlib.Path(path).read_text(encoding='utf-8').splitlines():
        if '=' not in raw:
            continue
        name, value = raw.split('=', 1)
        values[name.strip()] = value.strip()
    return values.get('OPENAI_API_KEY_PRIMARY'), values.get('OPENAI_API_KEY_SECONDARY')


def http_response_usable(status, body):
    if status in (401, 403):
        return False
    if status == 429:
        return 'insufficient_quota' not in (body or '')
    return True


def preflight(key, model=None, opener=None):
    if not key:
        return False
    model = model or os.environ.get('CODEX_FAILOVER_MODEL', DEFAULT_MODEL)
    payload = json.dumps({'model': model, 'input': 'ok', 'max_output_tokens': 16}).encode('utf-8')
    req = urllib.request.Request(
        'https://api.openai.com/v1/responses',
        data=payload,
        headers={'Authorization': f'Bearer {key}', 'Content-Type': 'application/json'},
        method='POST',
    )
    opener = opener or urllib.request.urlopen
    try:
        with opener(req, timeout=20) as resp:
            status = getattr(resp, 'status', 200)
            body = resp.read(4096).decode('utf-8', errors='replace')
    except urllib.error.HTTPError as exc:
        status = exc.code
        body = exc.read(4096).decode('utf-8', errors='replace')
    except (urllib.error.URLError, TimeoutError, OSError):
        return True
    return http_response_usable(status, body)


def build_env(key, base=None):
    env = dict(os.environ if base is None else base)
    env['OPENAI_API_KEY'] = key
    env.pop('CODEX_API_KEY', None)
    return env


def _pick_with_checker(primary, secondary, preferred, checker):
    order = [('primary', primary), ('secondary', secondary)]
    if preferred == 'secondary':
        order.reverse()
    for label, key in order:
        if key and checker(key):
            return key, label
    raise RuntimeError('No usable API key')


def pick_key(primary, secondary, preferred='primary'):
    return _pick_with_checker(primary, secondary, preferred, preflight)


def run_with_failover(primary, secondary, preferred, checker, runner):
    first_key, first_label = _pick_with_checker(primary, secondary, preferred, checker)
    rc = runner(first_key)
    if rc in (0, 130, -2):
        return rc, first_label
    if checker(first_key):
        return rc, first_label
    other_key = secondary if first_label == 'primary' else primary
    other_label = 'secondary' if first_label == 'primary' else 'primary'
    if not other_key or not checker(other_key):
        return rc, first_label
    return runner(other_key), other_label


def read_preferred():
    try:
        label = STATE_FILE.read_text(encoding='ascii').strip()
    except OSError:
        return 'primary'
    return label if label in ('primary', 'secondary') else 'primary'


def write_preferred(label):
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    STATE_FILE.write_text(label + '\n', encoding='ascii')
    if os.name != 'nt':
        STATE_FILE.chmod(0o600)


def resolve_codex():
    override = os.environ.get('CODEX_REAL')
    if override:
        return override
    found = shutil.which('codex')
    if not found:
        raise RuntimeError('Codex CLI not found in PATH')
    return found


def main(argv=None):
    argv = list(sys.argv[1:] if argv is None else argv)
    primary, secondary = parse_keys()
    if not primary or not secondary:
        print('Codex failover: two configured keys were not found.', file=sys.stderr)
        return 2
    if argv == ['--check']:
        print('primary=' + ('usable' if preflight(primary) else 'unavailable'))
        print('secondary=' + ('usable' if preflight(secondary) else 'unavailable'))
        return 0
    preferred = read_preferred()
    real = resolve_codex()
    def runner(key):
        cmd = [real, '-c', 'preferred_auth_method="apikey"', *argv]
        return subprocess.call(cmd, env=build_env(key))
    try:
        rc, active = run_with_failover(primary, secondary, preferred, preflight, runner)
    except RuntimeError as exc:
        print(f'Codex failover: {exc}', file=sys.stderr)
        return 3
    write_preferred(active)
    return rc


if __name__ == '__main__':
    raise SystemExit(main())
