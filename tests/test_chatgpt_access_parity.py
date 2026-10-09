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


if __name__ == '__main__':
    test_dev_and_atendimento_browser_mcp_runtime_policy_is_symmetric()
    test_access_parity_policy_is_referenced_by_both_corporate_bootstraps()
    print('CHATGPT_ACCOUNT_ACCESS_PARITY_CONTRACT=PASS')
