from decimal import Decimal
from datetime import datetime, timezone
import pytest
from okx_pilot.domain import MarketSnapshot, InstrumentType, DecisionKind
from okx_pilot.decision import HeuristicDecisionProvider, DecisionParser, DecisionValidationError


def snap():
    return MarketSnapshot("AAA-USDT", InstrumentType.SPOT, Decimal("100"), Decimal("99.9"), Decimal("100.1"), Decimal("100000"), datetime.now(timezone.utc))


def test_heuristic_provider_outputs_exactly_20_layers_and_trade_contract():
    raw = HeuristicDecisionProvider().analyze(snap())
    assert len(raw["layers"]) == 20
    assert [x["id"] for x in raw["layers"]] == list(range(1,21))
    intent = DecisionParser.parse(raw, now=datetime.now(timezone.utc))
    assert intent.decision in {DecisionKind.TRADE, DecisionKind.HOLD, DecisionKind.REJECT}


def test_parser_rejects_missing_layer_and_weak_trade():
    raw = HeuristicDecisionProvider().analyze(snap())
    raw["layers"] = raw["layers"][:-1]
    with pytest.raises(DecisionValidationError): DecisionParser.parse(raw, now=datetime.now(timezone.utc))
    raw = HeuristicDecisionProvider().analyze(snap())
    raw["decision"]="TRADE"; raw["confidence"]="69"; raw["expected_rr"]="2"
    with pytest.raises(DecisionValidationError): DecisionParser.parse(raw, now=datetime.now(timezone.utc))

def test_codex_bridge_provider_uses_prompt_protocol_without_secrets():
    import json
    from okx_pilot.decision import CodexBridgeDecisionProvider, HeuristicDecisionProvider, REQUIRED_LAYER_NAMES
    captured={}
    def transport(url, payload, timeout):
        captured.update(payload)
        return {"ok": True, "text": json.dumps(HeuristicDecisionProvider().analyze(snap())), "model": "gpt-5.6-sol"}
    provider=CodexBridgeDecisionProvider("http://127.0.0.1:17656/v1/respond", transport=transport)
    raw=provider.analyze(snap(), context={"paper_account":{"total_equity":"100"}})
    assert len(raw["layers"]) == 20
    assert captured["model"] == "gpt-5.6-sol"
    assert captured["web_search"] is False
    assert set(captured) == {"model","effort","prompt","web_search"}
    prompt=captured["prompt"]
    assert "AAA-USDT" in prompt
    for name in REQUIRED_LAYER_NAMES:
        assert name in prompt
    lowered=prompt.lower()
    assert "api_key" not in lowered and "secret" not in lowered and "passphrase" not in lowered
