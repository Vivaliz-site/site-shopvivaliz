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
    test_access_parity_policy_is_referenced_by_both_corporate_bootstraps()
    test_dedicated_browser_mcp_services_reload_installed_server()
    print('CHATGPT_ACCOUNT_ACCESS_PARITY_CONTRACT=PASS')
