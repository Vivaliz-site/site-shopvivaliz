#!/usr/bin/env python3
"""Check real X11 key events and stdin/argv isolation on an owned Xvfb fixture."""
from __future__ import annotations
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--server', type=Path, default=ROOT / 'remote-control-browser-mcp/server.py')
    parser.add_argument('--base-server', type=Path, default=ROOT / 'remote-control-mcp/server.py')
    args = parser.parse_args()
    display = os.environ.get('DISPLAY', '')
    authority = Path(os.environ.get('XAUTHORITY', '/nonexistent'))
    if not display or not authority.is_file() or not authority.parent.name.startswith('xvfb-run.'):
        raise RuntimeError('requires a disposable authenticated xvfb-run fixture')
    for command in ('xdotool', 'xev', 'stdbuf'):
        if not shutil.which(command):
            raise RuntimeError('missing fixture dependency: ' + command)
    os.environ['SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER'] = str(args.base_server.resolve())
    with tempfile.TemporaryDirectory(prefix='sv-gui-stdin-fixture-') as temporary:
        tmp = Path(temporary)
        os.environ['SHOPVIVALIZ_REMOTE_MCP_STATE'] = str(tmp / 'state')
        os.environ.pop('SHOPVIVALIZ_REMOTE_MCP_TOKEN', None)
        spec = importlib.util.spec_from_file_location('gui_stdin_fixture', args.server.resolve())
        assert spec and spec.loader
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        # Keep the exact production typing/runner implementation, but direct it
        # only at the disposable test server, never an operator/account session.
        module.gui_prefix = lambda: ['env', f'DISPLAY={display}', f'XAUTHORITY={authority}', 'LC_ALL=C.UTF-8']
        title = 'sv-typing-fixture-' + str(os.getpid())
        log = tmp / 'events.log'
        value = 'SvTyping_42'
        original_run = subprocess.run
        observed = []

        def observe_run(argv, *positional, **kwargs):
            if 'xdotool' in argv and 'type' in argv:
                observed.append({'argv_contains_input': value in argv,
                                 'stdin_matches': kwargs.get('input') == value})
            return original_run(argv, *positional, **kwargs)

        with log.open('w') as output:
            process = subprocess.Popen(['stdbuf', '-oL', 'xev', '-name', title, '-event', 'keyboard'],
                                       stdout=output, stderr=subprocess.DEVNULL)
            try:
                window = None
                deadline = time.monotonic() + 12
                while time.monotonic() < deadline:
                    found = original_run(['xdotool', 'search', '--onlyvisible', '--name', title],
                                         capture_output=True, text=True, timeout=2)
                    if found.returncode == 0 and found.stdout.strip():
                        window = found.stdout.splitlines()[0]
                        break
                    if process.poll() is not None:
                        raise RuntimeError('fixture window terminated')
                    time.sleep(0.1)
                if window is None:
                    raise RuntimeError('fixture window not ready')
                original_run(['xdotool', 'windowfocus', '--sync', window], check=True, timeout=5)
                module.subprocess.run = observe_run
                try:
                    module.type_text(value)
                finally:
                    module.subprocess.run = original_run
                received = ''
                deadline = time.monotonic() + 5
                while time.monotonic() < deadline:
                    text = log.read_text()
                    blocks = re.findall(r'KeyPress event,.*?(?=\n\w+ event,|\Z)', text, re.S)
                    received = ''.join(match for block in blocks for match in
                                       re.findall(r'XLookupString gives [1-9][0-9]* bytes:.*? "(.*?)"', block))
                    if received == value:
                        break
                    time.sleep(0.05)
                assert received == value, 'X11 key event sequence does not match'
                assert observed and len(observed) == 1, 'expected one real typing process'
                assert not observed[0]['argv_contains_input'], 'typed input entered real process argv'
                assert observed[0]['stdin_matches'], 'typing input was not supplied on stdin'
                result = {'ok': True, 'scope': 'isolated X11 input transport, not account/browser E2E',
                          'characters_confirmed': len(received), 'argv_contains_input': False,
                          'stdin_matches': True, 'server_sha256': hashlib.sha256(args.server.read_bytes()).hexdigest()}
                print(json.dumps(result))
            finally:
                module.subprocess.run = original_run
                process.terminate()
                try:
                    process.wait(timeout=4)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=4)


if __name__ == '__main__':
    main()
