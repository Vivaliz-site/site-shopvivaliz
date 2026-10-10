import ast
import json
from pathlib import Path
import pytest
from okx_pilot.decision import CodexBridgeDecisionProvider, DecisionValidationError
from test_ai_engine import snap, valid_payload


def test_default_model_is_terra_medium():
    p = CodexBridgeDecisionProvider()
    assert p.model == 'gpt-5.6-terra'
    assert p.effort == 'medium'


def test_runner_defaults_match_terra_login_provider():
    tree = ast.parse((Path(__file__).parents[1] / 'scripts/run.py').read_text())
    defaults = {}
    for node in ast.walk(tree):
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and node.func.attr == 'add_argument' and node.args and isinstance(node.args[0], ast.Constant):
            for kw in node.keywords:
                if kw.arg == 'default' and isinstance(kw.value, ast.Constant):
                    defaults[node.args[0].value] = kw.value.value
    assert defaults['--decision-model'] == 'gpt-5.6-terra'
    assert defaults['--decision-effort'] == 'medium'
    assert defaults['--decision-url'] == 'http://127.0.0.1:17656/v1/respond'


@pytest.mark.parametrize('transport_name', [None, 'openai_api', 'api_key'])
def test_model_name_alone_does_not_prove_login_transport(transport_name):
    m = snap()
    response = {'ok': True, 'model': 'gpt-5.6-terra', 'text': json.dumps(valid_payload(m))}
    if transport_name is not None:
        response['transport'] = transport_name
    p = CodexBridgeDecisionProvider(model='gpt-5.6-terra', transport=lambda *_: response)
    with pytest.raises(DecisionValidationError, match='transport_mismatch'):
        p.analyze(m, context={})


def test_real_login_transport_contract_accepts_terra():
    m = snap()
    response = {'ok': True, 'model': 'gpt-5.6-terra', 'transport': 'codex_chatgpt', 'text': json.dumps(valid_payload(m))}
    p = CodexBridgeDecisionProvider(model='gpt-5.6-terra', transport=lambda *_: response)
    assert p.analyze(m, context={})['instrument'] == m.instrument

@pytest.mark.parametrize('url', ['https://api.openai.com/v1/responses', 'http://example.com/v1/respond', 'http://user:password@localhost:17656/v1/respond', 'http://127.0.0.1:17656/v1/respond?key=test'])
def test_provider_cannot_be_redirected_to_api_key_endpoint(url):
    with pytest.raises(DecisionValidationError, match='loopback_url_required'):
        CodexBridgeDecisionProvider(url)
