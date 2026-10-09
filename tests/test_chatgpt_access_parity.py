from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_dev_and_atendimento_browser_mcp_runtime_policy_is_symmetric():
    # These services differ only in the dedicated identity/session bindings.
    # Tools, privileges, limits, binaries, security and server source stay equal.
    def normalized_unit(name):
        text = (ROOT / 'deploy' / 'systemd' / name).read_text(encoding='utf-8')
        account_fields = (
            'Environment=SHOPVIVALIZ_BROWSER_SESSION_NAME=',
            'Environment=SHOPVIVALIZ_BROWSER_CDP_URL=',
            'Environment=SHOPVIVALIZ_REMOTE_MCP_PORT=',
            'Description=ShopVivaliz Browser ',
        )
        return [line for line in text.splitlines()
                if not any(line.startswith(field) for field in account_fields)]

    atendimento = normalized_unit('shopvivaliz-browser-atendimento-mcp.service')
    dev = normalized_unit('shopvivaliz-browser-dev-mcp.service')
    assert atendimento == dev


def test_dev_and_atendimento_chromium_units_have_identical_effective_limits():
    # Ignore ONLY the account-bound identifiers. CPU, memory, security,
    # browser command-line options and restart policy must remain identical.
    def normalized_unit(session, port):
        path = ROOT / 'ops' / 'systemd' / f'shopvivaliz-{session}-browser.service'
        body = path.read_text(encoding='utf-8')
        expected = (
            (f'Description=ShopVivaliz {session} ChatGPT browser',
             'Description=ShopVivaliz ACCOUNT ChatGPT browser'),
            (f'--user-data-dir=/home/fredrdp/.config/shopvivaliz-{session}-chromium',
             '--user-data-dir=/home/fredrdp/.config/shopvivaliz-ACCOUNT-chromium'),
            (f'--class=shopvivaliz-{session}', '--class=shopvivaliz-ACCOUNT'),
            (f'--remote-debugging-port={port}', '--remote-debugging-port=PORT'),
        )
        for original, normalized in expected:
            assert body.count(original) == 1, (session, original)
            body = body.replace(original, normalized)
        for invariant in ('User=fredrdp', 'NoNewPrivileges=true',
                          'PrivateTmp=true', 'KillMode=control-group'):
            assert invariant in body, (session, invariant)
        return body

    assert normalized_unit('dev', 9559) == normalized_unit('atendimento', 9556)


def test_corporate_browser_units_preserve_task_headroom_under_many_open_tabs():
    # The Dev browser had 43 tabs and 375/384 cgroup tasks; pids.events:max
    # incremented 6 times and every authentication CDP Runtime.evaluate timed out.
    # Keep a bounded safety margin for browser renderer threads, symmetrically.
    for session in ('dev', 'atendimento'):
        unit = (ROOT / 'ops' / 'systemd' /
                f'shopvivaliz-{session}-browser.service').read_text(encoding='utf-8')
        limits = [int(line.split('=', 1)[1]) for line in unit.splitlines()
                  if line.startswith('TasksMax=')]
        assert len(limits) == 1, session
        assert 512 <= limits[0] <= 768, (session, limits[0])
        # Raising only process headroom must not remove the memory and
        # privilege boundaries of either authenticated browser.
        for boundary in ('MemoryHigh=2G', 'MemoryMax=3G', 'NoNewPrivileges=true',
                         'PrivateTmp=true', 'CPUQuota=80%'):
            assert boundary in unit, (session, boundary)


def test_access_parity_policy_is_referenced_by_both_corporate_bootstraps():
    policy = (ROOT / 'docs/knowledge/chatgpt-access-parity.md').read_text(encoding='utf-8')
    for account in ('dev@shopvivaliz.com.br', 'atendimento@shopvivaliz.com.br'):
        assert account in policy
    for path in ('docs/knowledge/dev-chatgpt-bootstrap.md',
                 'docs/knowledge/atendimento-chatgpt-bootstrap.md',
                 'docs/knowledge/README.md'):
        assert 'chatgpt-access-parity.md' in (ROOT / path).read_text(encoding='utf-8')
    for expectation in ('who_am_i.email', 'Both ChatGPT accounts',
                        'approval', 'OAuth', 'CDP `9559`', 'CDP `9556`',
                        '`tools/list`', 'COMPLETE tool-name catalog', 'inputSchema'):
        assert expectation in policy


def test_dedicated_browser_mcp_services_reload_installed_server():
    # Regression: enable --now left the old Python processes running while
    # the shared server.py on disk changed, causing cross-account drift.
    setup = (ROOT / 'scripts/setup-remote-control-browser-mcp.sh').read_text(encoding='utf-8')
    marker = 'systemctl daemon-reload\\nfor session in atendimento dev; do'
    assert marker.replace('\\n', '\n') in setup
    block = setup.split(marker.replace('\\n', '\n'), 1)[1]
    assert 'systemctl enable --now "$unit"' not in block
    assert 'systemctl enable "$unit"' in block
    assert 'systemctl restart "$unit"' in block
    assert block.index('systemctl enable "$unit"') < block.index('systemctl restart "$unit"')
    assert block.index('systemctl restart "$unit"') < block.index('  ready=0')
    assert 'shopvivaliz-browser-${session}-mcp.service' in block


if __name__ == '__main__':
    test_dev_and_atendimento_browser_mcp_runtime_policy_is_symmetric()
    test_dev_and_atendimento_chromium_units_have_identical_effective_limits()
    test_corporate_browser_units_preserve_task_headroom_under_many_open_tabs()
    test_access_parity_policy_is_referenced_by_both_corporate_bootstraps()
    test_dedicated_browser_mcp_services_reload_installed_server()
    print('CHATGPT_ACCOUNT_ACCESS_PARITY_CONTRACT=PASS')
