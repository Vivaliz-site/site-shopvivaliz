import json
import stat
import time
from datetime import datetime, timedelta, timezone
from decimal import Decimal as D
from pathlib import Path
import pytest

from okx_pilot.decision import DecisionParser, DecisionValidationError
from okx_pilot.provider_chain import (
    PROVIDER_ORDER, GeminiFreeProvider, OllamaLocalProvider,
    ChatGPTNormalProvider, PriorityFallbackDecisionProvider,
    _read_free_key, _expand_compact,
)
from test_ai_engine import snap, valid_payload

class Stub:
    def __init__(self, name, outcomes, calls):
        self.name = name
        self.outcomes = outcomes
        self.calls = calls
    def analyze(self, snapshot, context=None):
        self.calls.append(self.name)
        value = self.outcomes[self.name]
        if isinstance(value, Exception):
            raise value
        return value


def chain_for(outcomes):
    m = snap()
    calls = []
    chain = PriorityFallbackDecisionProvider([
        Stub(name, outcomes, calls) for name in PROVIDER_ORDER
    ])
    return chain, m, calls


def test_precedence_gemini_first_shortcircuits():
    m = snap()
    response = valid_payload(m)
    calls = []
    chain = PriorityFallbackDecisionProvider([
        Stub(name, {name: response}, calls) for name in PROVIDER_ORDER
    ])
    assert chain.analyze(m)["_provider_source"] == "gemini"
    assert calls == ["gemini"]


def test_unavailable_gemini_then_ollama_stops_on_hold():
    m = snap()
    calls = []
    response = valid_payload(m)
    response.update(decision="HOLD", suggested_risk=0, stop=0, targets=[],
                    entry_low=0, entry_high=0, expected_rr=0, confidence=55, suggested_leverage=0)
    outcomes = {
        "gemini": DecisionValidationError("gemini_free_tier_not_verified"),
        "ollama": response,
        "chatgpt_normal": DecisionValidationError("not_configured"),
        "codex": DecisionValidationError("paid_provider_disabled"),
    }
    chain = PriorityFallbackDecisionProvider([Stub(name, outcomes, calls) for name in PROVIDER_ORDER])
    raw = chain.analyze(m)
    assert DecisionParser.parse(raw, datetime.now(timezone.utc)).decision.value == "HOLD"
    assert raw["_provider_source"] == "ollama"
    assert calls == ["gemini", "ollama"]
    assert chain.describe()["decision_provider_selected"] == "ollama"


def test_invalid_ollama_falls_through_chatgpt_then_codex():
    m = snap()
    calls = []
    invalid = valid_payload(m)
    invalid["layers"] = invalid["layers"][:7]
    valid = valid_payload(m)
    outcomes = {
        "gemini": DecisionValidationError("free_unverified"),
        "ollama": invalid,
        "chatgpt_normal": DecisionValidationError("chatgpt_normal_interactive_only"),
        "codex": valid,
    }
    chain = PriorityFallbackDecisionProvider([Stub(n, outcomes, calls) for n in PROVIDER_ORDER])
    assert chain.analyze(m)["_provider_source"] == "codex"
    assert calls == list(PROVIDER_ORDER)
    assert chain.describe()["decision_provider_failures"]["ollama"] == "invalid_response"


def test_all_unavailable_fail_closed_without_trades():
    m = snap()
    calls = []
    outcomes = {n: DecisionValidationError("unavailable") for n in PROVIDER_ORDER}
    chain = PriorityFallbackDecisionProvider([Stub(n, outcomes, calls) for n in PROVIDER_ORDER])
    with pytest.raises(DecisionValidationError, match="fallback_exhausted"):
        chain.analyze(m)
    assert chain.describe()["decision_provider_selected"] is None
    assert calls == list(PROVIDER_ORDER)
    with pytest.raises(DecisionValidationError, match="fallback_exhausted"):
        chain.analyze(m)
    assert calls == list(PROVIDER_ORDER)


def test_prevents_reordering():
    outcomes = {n: DecisionValidationError("unavailable") for n in PROVIDER_ORDER}
    calls = []
    with pytest.raises(ValueError, match="priority_order"):
        PriorityFallbackDecisionProvider([Stub(n, outcomes, calls) for n in reversed(PROVIDER_ORDER)])


def test_missing_gemini_proof_makes_zero_network_calls(tmp_path):
    calls = []
    env = tmp_path / "gemini.env"
    env.write_text("GEMINI_API_KEY=DEMO_TEST_NO_REAL_KEY\n")
    provider = GeminiFreeProvider(env_path=env, proof_path=tmp_path / "absent.json",
                                  transport=lambda *a, **k: calls.append((a, k)))
    with pytest.raises(DecisionValidationError, match="gemini_free_tier_not_verified"):
        provider.analyze(snap(), {})
    assert calls == []


def test_gemini_proof_matches_exact_key_and_age(tmp_path):
    import hashlib
    env = tmp_path / "key.env"
    env.write_text('GEMINI_API_KEY=DEMO_TEST_KEY\n')
    proof = tmp_path / "proof.json"
    def write_proof(age):
        proof.write_text(json.dumps({
            "schema_version": 1, "billing_tier": "free",
            "verified_source": "google_ai_studio", "project_id": "test-example",
            "verified_at": (datetime.now(timezone.utc) - age).isoformat(),
            "key_sha256": [hashlib.sha256(b"DEMO_TEST_KEY").hexdigest()],
        }))
        proof.chmod(0o600)
    write_proof(timedelta(days=31))
    with pytest.raises(DecisionValidationError):
        _read_free_key(env, proof)
    write_proof(timedelta(hours=1))
    assert _read_free_key(env, proof) == "DEMO_TEST_KEY"
    proof.chmod(0o644)
    with pytest.raises(DecisionValidationError):
        _read_free_key(env, proof)


def test_structured_ollama_converts_actual_20_generated_layers():
    m = snap()
    row = valid_payload(m)
    data = {k: v for k, v in row.items() if k not in (
        "layers", "decision_id", "instrument", "instrument_type", "time_horizon",
        "created_at", "expires_at", "market_snapshot_ts"
    )}
    data["assessments"] = [x["assessment"] for x in row["layers"]]
    data["evidence"] = [x["evidence"][0] for x in row["layers"]]
    data["supporting_evidence"] = row["supporting_evidence"][0]
    data["contrary_evidence"] = row["contrary_evidence"][0]
    def transport(url, payload, **kwargs):
        assert url == "http://127.0.0.1:11434/api/generate"
        assert payload["stream"] is False and payload["format"]["type"] == "object"
        return {"done": True, "done_reason": "stop", "response": json.dumps(data)}
    result = OllamaLocalProvider(transport=transport).analyze(m, {})
    assert DecisionParser.parse(result, datetime.now(timezone.utc)).layers[0]["name"] == "market_regime"


def test_normal_chatgpt_is_not_a_cookie_scraper():
    with pytest.raises(DecisionValidationError, match="interactive_only"):
        ChatGPTNormalProvider().analyze(snap(), {})


def test_stale_snapshot_cannot_be_approved():
    m = snap()
    old = m.timestamp - timedelta(hours=1)
    # Dataclass snapshot is frozen, so build with timestamp in original order.
    from okx_pilot.domain import MarketSnapshot
    stale = MarketSnapshot(m.instrument, m.instrument_type, m.last, m.bid, m.ask,
                           m.volume_24h, old, open_24h=m.open_24h)
    calls = []
    response = valid_payload(stale)
    chain = PriorityFallbackDecisionProvider([
        Stub(n, {n: response}, calls) for n in PROVIDER_ORDER
    ])
    with pytest.raises(DecisionValidationError, match="fallback_exhausted"):
        chain.analyze(stale)


def test_gemini_free_analysis_cadence_is_independent_of_five_second_market_polling():
    clock=[100.]
    m=snap()
    calls=[]
    response=valid_payload(m)
    chain=PriorityFallbackDecisionProvider([
        Stub(name,{name:response},calls) for name in PROVIDER_ORDER
    ],clock=lambda:clock[0])
    assert chain.claim_analysis_slot() is True
    assert chain.claim_analysis_slot() is False
    assert chain.describe()["decision_provider_next_analysis_seconds"] == 300.0
    clock[0]+=299
    assert chain.claim_analysis_slot() is False
    clock[0]+=1
    assert chain.claim_analysis_slot() is True


def test_orchestrator_respects_global_analysis_budget_without_treating_idle_as_failure(tmp_path):
    from okx_pilot.orchestrator import PilotOrchestrator
    from okx_pilot.scanner import MarketScanner
    from okx_pilot.risk import RiskGateway
    from okx_pilot.domain import PilotLimits
    from okx_pilot.paper import PaperBroker
    from okx_pilot.decision import DecisionValidationError
    clock=[100.]
    calls=[]
    m=snap()
    # Invalid free proof is deliberately allowed only as a negative fixture.
    providers=[Stub(n,{n:DecisionValidationError("unavailable")},calls) for n in PROVIDER_ORDER]
    chain=PriorityFallbackDecisionProvider(providers,clock=lambda:clock[0])
    assert chain.claim_analysis_slot()
    assert chain.claim_analysis_slot() is False
    assert chain.describe()["decision_provider_next_analysis_seconds"]>0
