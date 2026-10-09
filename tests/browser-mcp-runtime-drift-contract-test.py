from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SETUP = ROOT / "scripts" / "setup-remote-control-browser-mcp.sh"
BROWSER_MCP_UNIT = ROOT / "deploy" / "systemd" / "shopvivaliz-remote-control-browser-mcp.service"
CHATGPT_BROWSER_UNIT = ROOT / "ops" / "systemd" / "shopvivaliz-chatgpt-browser.service"


def test_setup_removes_authenticated_session_override_and_verifies_effective_isolation():
    text = SETUP.read_text(encoding="utf-8")
    assert "40-authenticated-session.conf" in text
    assert "40-authenticated-session.conf.disabled" in text
    assert "40-authenticated-session.conf.bak-daybreak" in text
    assert 'rm -f "$CONFLICTING_SESSION_DROPIN" "$CONFLICTING_SESSION_BACKUP" "$CONFLICTING_SESSION_DAYBREAK_BACKUP"' in text
    assert "SHOPVIVALIZ_BROWSER_MCP_GUI_USER=fredconsole" in text
    assert "SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:0" in text
    assert "SHOPVIVALIZ_BROWSER_MCP_WINDOW_CLASS=shopvivaliz-general" in text
    assert "systemctl show shopvivaliz-remote-control-browser-mcp.service" in text


def test_browser_mcp_source_stays_isolated_from_continuity_browser():
    text = BROWSER_MCP_UNIT.read_text(encoding="utf-8")
    assert "SHOPVIVALIZ_BROWSER_MCP_GUI_USER=fredconsole" in text
    assert "SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:0" in text
    assert "SHOPVIVALIZ_BROWSER_MCP_WINDOW_CLASS=shopvivaliz-general" in text
    assert "fredrdp" not in text
    assert "DISPLAY=:99" not in text


def test_chatgpt_browser_has_thread_headroom():
    text = CHATGPT_BROWSER_UNIT.read_text(encoding="utf-8")
    assert "TasksMax=512" in text


def test_two_dedicated_browser_mcp_services_are_declared():
    atendimento = (ROOT / "deploy/systemd/shopvivaliz-browser-atendimento-mcp.service").read_text(encoding="utf-8")
    dev = (ROOT / "deploy/systemd/shopvivaliz-browser-dev-mcp.service").read_text(encoding="utf-8")
    assert "SHOPVIVALIZ_BROWSER_SESSION_NAME=atendimento" in atendimento
    assert "SHOPVIVALIZ_BROWSER_CDP_URL=http://127.0.0.1:9556" in atendimento
    assert "SHOPVIVALIZ_REMOTE_MCP_PORT=5582" in atendimento
    assert "SHOPVIVALIZ_BROWSER_SESSION_NAME=dev" in dev
    assert "SHOPVIVALIZ_BROWSER_CDP_URL=http://127.0.0.1:9559" in dev
    assert "SHOPVIVALIZ_REMOTE_MCP_PORT=5583" in dev
    setup = SETUP.read_text(encoding="utf-8")
    assert 'curl -fsS "http://127.0.0.1:${port}/health"' in setup
    assert "browser_session" in setup


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
                        'approval', 'OAuth', 'CDP `9559`', 'CDP `9556`'):
        assert expectation in policy
